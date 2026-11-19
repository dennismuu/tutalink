<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/paystack.php';
require_login();
$sessionUser = current_user();
try {
    $identityQuery = db()->prepare(
        'SELECT u.id, u.full_name, u.email, r.slug AS role
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?'
    );
    $identityQuery->execute([$sessionUser['id']]);
    $accountIdentity = $identityQuery->fetch();
    if (!$accountIdentity) page_error('Your account could not be found.', 404);
    $user = [
        'id' => (int) $accountIdentity['id'],
        'name' => $accountIdentity['full_name'],
        'email' => $accountIdentity['email'],
        'role' => $accountIdentity['role'],
    ];
    $_SESSION['user'] = $user;
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
$isProvider = in_array($user['role'], ['tutor', 'service_provider'], true);
$isCustomer = in_array($user['role'], ['student_parent', 'customer'], true);
$isAdmin = in_array($user['role'], ['super_admin', 'admin1', 'admin2'], true);
$showRequests = ($_GET['view'] ?? '') === 'requests';
$dashboardReturnPath = $showRequests ? 'Pages/dashboard.php?view=requests' : 'Pages/dashboard.php';
$providerApprovalStatus = null;
if ($isProvider) {
    try {
        $approvalQuery = db()->prepare('SELECT approval_status FROM provider_profiles WHERE user_id = ?');
        $approvalQuery->execute([$user['id']]);
        $providerApprovalStatus = $approvalQuery->fetchColumn();
        if ($providerApprovalStatus === false) page_error('Your provider profile could not be found.', 404);
    } catch (RuntimeException $exception) {
        page_error($exception->getMessage(), 503);
    }
}
$adminRecipients = [];
$quoteEmail = null;
$quoteMessage = '';
$commissionRate = 10.0;
$providerEarnings = 0.0;
$availableBalance = 0.0;
$payoutInProgress = 0.0;
$customerEmail = null;
$adminAnalytics = [];
$adminBookingTrend = [];
$pendingProviderApprovals = 0;
$canManageProviderApprovals = false;
$userAnalytics = [];
$userBookingTrend = [];
$pageTitle = $showRequests ? ($isAdmin ? 'Booking actions' : 'My requests') : 'My dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$bookingId) page_error('That booking could not be found.', 404);
    $serviceStartOtp = null;
    $serviceOtpEmail = null;
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $query = $pdo->prepare(
            'SELECT b.id, b.customer_id, b.provider_id, b.category_id, b.checkout_order_id, b.status, b.session_hours, b.student_count,
                    b.laundry_baskets, b.booked_service_price, b.quoted_amount, b.service_start_otp,
                    b.service_start_otp_expires_at, b.service_started_at,
                    b.provider_amount, b.commission_amount,
                    b.provider_completed_at, b.customer_completed_at, b.class_otp, b.class_otp_expires_at,
                    b.class_started_at, c.module, c.slug, c.name,
                    COALESCE(b.booked_hourly_rate, c.hourly_rate) AS service_hourly_rate,
                    COALESCE(b.booked_service_price, c.service_price) AS service_price,
                    pp.approval_status AS provider_approval_status,
                    provider.full_name AS provider_name, provider.email AS provider_email,
                    (SELECT payment.gateway FROM payments payment WHERE payment.booking_id = b.id AND payment.status = \'paid\' ORDER BY payment.id DESC LIMIT 1) AS paid_gateway,
                    (SELECT payment.payout_mode FROM payments payment WHERE payment.booking_id = b.id AND payment.status = \'paid\' ORDER BY payment.id DESC LIMIT 1) AS paid_payout_mode,
                    (SELECT payment.status FROM payments payment WHERE payment.booking_id = b.id ORDER BY payment.id DESC LIMIT 1) AS latest_payment_status,
                    (SELECT wr.status FROM tutor_withdrawal_requests wr WHERE wr.booking_id = b.id LIMIT 1) AS withdrawal_status,
                    (SELECT rr.status FROM refund_requests rr WHERE rr.booking_id = b.id LIMIT 1) AS refund_status
             FROM bookings b
             JOIN service_categories c ON c.id = b.category_id
             JOIN provider_profiles pp ON pp.user_id = b.provider_id
             JOIN users provider ON provider.id = b.provider_id
             WHERE b.id = ? FOR UPDATE'
        );
        $query->execute([$bookingId]);
        $item = $query->fetch();
        if (!$item) {
            $pdo->rollBack();
            page_error('That booking could not be found.', 404);
        }

        if (in_array($action, ['accept', 'decline'], true)) {
            $legacyAcceptedService = $action === 'accept' && $item['module'] === 'home_services'
                && $item['status'] === 'accepted' && $item['quoted_amount'] === null;
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id'] || $item['provider_approval_status'] !== 'approved'
                || ($item['status'] !== 'requested' && !$legacyAcceptedService)) {
                $pdo->rollBack();
                page_error('You cannot update this booking.', 403);
            }
            if ($action === 'accept') {
                $providerLock = $pdo->prepare('SELECT user_id FROM provider_profiles WHERE user_id = ? FOR UPDATE');
                $providerLock->execute([$user['id']]);
                if (!$providerLock->fetchColumn()) {
                    $pdo->rollBack();
                    page_error('Your provider profile could not be found.', 404);
                }
                $otherActiveBooking = $pdo->prepare(
                    "SELECT COUNT(*) FROM bookings
                     WHERE provider_id = ? AND id <> ?
                       AND status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress')"
                );
                $otherActiveBooking->execute([$user['id'], $bookingId]);
                if ((int) $otherActiveBooking->fetchColumn() > 0) {
                    $pdo->rollBack();
                    page_error('You have another unfinished booking. Complete it before accepting a new request.', 409);
                }
            }
            $prepaidCheckout = $item['checkout_order_id'] !== null && in_array($item['paid_gateway'], ['paystack', 'demo'], true);
            $status = $action === 'decline' ? 'declined' : 'accepted';
            $note = $action === 'accept' ? 'Provider accepted the request.' : 'Provider declined the request.';
            if ($action === 'accept' && $prepaidCheckout) {
                $status = 'paid';
                $note = 'Provider accepted the paid checkout request.';
                $pdo->prepare("UPDATE bookings SET status = 'paid' WHERE id = ? AND status = 'requested'")
                    ->execute([$bookingId]);
                if ($item['module'] === 'tutoring') {
                    $classOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $pdo->prepare(
                        'UPDATE bookings SET class_otp = ?, class_otp_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                         WHERE id = ? AND class_started_at IS NULL AND class_otp IS NULL'
                    )->execute([$classOtp, $bookingId]);
                }
            } elseif ($action === 'accept' && $item['module'] === 'tutoring') {
                if ($item['service_hourly_rate'] === null || $item['session_hours'] === null) {
                    $pdo->rollBack();
                    page_error('This tutoring request has no admin-set hourly price or session length.', 409);
                }
                $commissionValue = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'commission_percent'")->fetchColumn();
                if ($commissionValue === false || !is_numeric($commissionValue) || (float) $commissionValue < 0 || (float) $commissionValue > 100) {
                    $pdo->rollBack();
                    page_error('The platform commission has not been configured correctly.');
                }
                $hourlyRateCents = (int) round((float) $item['service_hourly_rate'] * 100);
                $sessionHours = (float) $item['session_hours'];
                $studentCount = (int) $item['student_count'];
                $amountCents = (int) round($hourlyRateCents * $sessionHours * $studentCount);
                if ($hourlyRateCents <= 0 || $sessionHours < 0.5 || $sessionHours > 12 || $studentCount < 1 || $studentCount > 30 || $amountCents <= 0 || $amountCents > 9999999999) {
                    $pdo->rollBack();
                    page_error('The tutoring rate or session length is outside the supported range.');
                }
                $commissionCents = (int) round($amountCents * (float) $commissionValue / 100);
                $providerCents = $amountCents - $commissionCents;
                $status = 'awaiting_payment';
                $note = 'Tutor accepted. Admin-set price calculated as KES ' . number_format($hourlyRateCents / 100, 2)
                    . ' × ' . number_format($sessionHours, 2) . ' hours × ' . $studentCount . ' student(s).';
                $pdo->prepare(
                    "UPDATE bookings SET quoted_amount = ?, commission_amount = ?, provider_amount = ?, status = 'awaiting_payment'
                     WHERE id = ? AND status = 'requested'"
                )->execute([$amountCents / 100, $commissionCents / 100, $providerCents / 100, $bookingId]);
            } elseif ($action === 'accept' && $item['module'] === 'home_services' && !$prepaidCheckout) {
                if ($item['service_price'] === null || (float) $item['service_price'] <= 0) {
                    $pdo->rollBack();
                    page_error('This home service has no admin-set price yet.', 409);
                }
                $commissionValue = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'commission_percent'")->fetchColumn();
                if ($commissionValue === false || !is_numeric($commissionValue) || (float) $commissionValue < 0 || (float) $commissionValue > 100) {
                    $pdo->rollBack();
                    page_error('The platform commission has not been configured correctly.');
                }
                $servicePriceCents = (int) round((float) $item['service_price'] * 100);
                $serviceQuantity = $item['slug'] === 'laundry' ? (int) $item['laundry_baskets'] : 1;
                $serviceAmountCents = $servicePriceCents * $serviceQuantity;
                if ($servicePriceCents <= 0 || $serviceQuantity < 1 || $serviceQuantity > 10
                    || $serviceAmountCents <= 0 || $serviceAmountCents > 9999999999) {
                    $pdo->rollBack();
                    page_error('The admin-set service price or quantity is outside the supported range.');
                }
                $commissionCents = (int) round($serviceAmountCents * (float) $commissionValue / 100);
                $providerCents = $serviceAmountCents - $commissionCents;
                $status = 'awaiting_payment';
                $note = $item['slug'] === 'laundry'
                    ? 'Provider accepted. Admin-set Laundry price: KES ' . number_format($servicePriceCents / 100, 2)
                        . ' per basket × ' . $serviceQuantity . ' baskets.'
                    : 'Provider accepted. Admin-set fixed price per service: KES ' . number_format($servicePriceCents / 100, 2) . '.';
                $pdo->prepare(
                    "UPDATE bookings SET booked_service_price = ?, quoted_amount = ?, commission_amount = ?, provider_amount = ?, status = 'awaiting_payment'
                     WHERE id = ? AND status IN ('requested','accepted')"
                )->execute([
                    $servicePriceCents / 100, $serviceAmountCents / 100, $commissionCents / 100,
                    $providerCents / 100, $bookingId,
                ]);
            } else {
                $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ? AND status = \'requested\'')
                    ->execute([$status, $bookingId]);
            }
            $pdo->prepare('INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, ?, ?)')
                ->execute([$bookingId, $user['id'], $status, $note]);
            $customerMessage = $action === 'decline'
                ? 'Your ' . $item['name'] . ' request was declined. Our team has been notified to help find another match.'
                : ($prepaidCheckout
                    ? 'Your selected provider accepted your paid ' . $item['name'] . ' booking. Check your dashboard for the next steps.'
                    : ($item['module'] === 'tutoring'
                    ? 'Your tutor accepted. ' . number_format($sessionHours, 2) . ' hours × ' . $studentCount . ' student(s) × KES ' . number_format($hourlyRateCents / 100, 2)
                        . ' per hour = KES ' . number_format($amountCents / 100, 2) . '. Platform commission: KES '
                        . number_format($commissionCents / 100, 2) . '. Check your dashboard to pay.'
                    : ($item['module'] === 'home_services'
                        ? 'Your provider accepted. ' . ($item['slug'] === 'laundry'
                            ? 'KES ' . number_format($servicePriceCents / 100, 2) . ' per basket × ' . $serviceQuantity . ' baskets'
                            : 'Fixed price per service: KES ' . number_format($servicePriceCents / 100, 2))
                            . ' = KES ' . number_format($serviceAmountCents / 100, 2) . '. Check your dashboard to pay.'
                        : 'Your ' . $item['name'] . ' request was accepted. Check your dashboard for next steps.')));
            if ($action === 'accept' && in_array($item['module'], ['tutoring', 'home_services'], true) && !$prepaidCheckout) {
                $priceSubject = $item['module'] === 'tutoring' ? 'Your tutoring price is ready' : 'Your home-service price is ready';
                $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', ?, ?)")
                    ->execute([$item['customer_id'], $bookingId, $priceSubject, $customerMessage]);
            }
            else {
                $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', ?, ?)")
                    ->execute([$item['customer_id'], $bookingId, 'Booking ' . $status, $customerMessage]);
            }
            $customerEmailQuery = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $customerEmailQuery->execute([$item['customer_id']]);
            $customerEmail = $customerEmailQuery->fetchColumn();

            if ($action === 'decline') {
                $admins = $pdo->prepare(
                    "SELECT u.id, u.email FROM users u JOIN roles r ON r.id = u.role_id
                     LEFT JOIN admin_permissions ap ON ap.user_id = u.id AND ap.module = ?
                     WHERE u.is_active = 1 AND u.email_verified_at IS NOT NULL
                       AND (r.slug = 'super_admin' OR (r.slug IN ('admin1','admin2') AND ap.manage_bookings = 1))"
                );
                $admins->execute([$item['module']]);
                $adminRecipients = $admins->fetchAll();
                foreach ($adminRecipients as $admin) {
                    $body = 'Booking #' . $bookingId . ' for ' . $item['name'] . ' was declined. Please help match the customer with another provider.';
                    $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Provider declined a request', ?)")
                        ->execute([$admin['id'], $bookingId, $body]);
                }
                if ($item['checkout_order_id'] !== null && $item['paid_gateway'] === 'paystack') {
                    $existingRefund = $pdo->prepare('SELECT id FROM refund_requests WHERE booking_id = ?');
                    $existingRefund->execute([$bookingId]);
                    if (!$existingRefund->fetchColumn()) {
                        $pdo->prepare(
                            "INSERT INTO refund_requests (booking_id, requested_by, reason)
                             VALUES (?, ?, 'Selected provider declined after payment. Refund must be processed before reassignment.')"
                        )->execute([$bookingId, $item['customer_id']]);
                    }
                }
            }
        } elseif ($action === 'quote') {
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id'] || $item['provider_approval_status'] !== 'approved' || $item['status'] !== 'accepted'
                || $item['module'] === 'home_services'
                || ($item['module'] === 'tutoring' && $item['session_hours'] !== null)
                || $amount === false || $amount <= 0 || $amount > 10000000) {
                $pdo->rollBack();
                page_error($item['module'] === 'home_services'
                    ? 'Home-service prices are fixed by LertFind and cannot be changed by providers.'
                    : 'You cannot submit that price for this booking.', 403);
            }
            $rateValue = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'commission_percent'")->fetchColumn();
            if ($rateValue === false || !is_numeric($rateValue)) {
                $pdo->rollBack();
                page_error('The platform commission has not been configured.');
            }
            $rate = (float) $rateValue;
            if ($rate < 0 || $rate > 100) {
                $pdo->rollBack();
                page_error('The platform commission is not configured correctly.');
            }
            $amountCents = (int) round($amount * 100);
            $commissionCents = (int) round($amountCents * $rate / 100);
            $providerCents = $amountCents - $commissionCents;
            $pdo->prepare("UPDATE bookings SET quoted_amount = ?, commission_amount = ?, provider_amount = ?, status = 'awaiting_payment' WHERE id = ? AND status = 'accepted'")
                ->execute([$amountCents / 100, $commissionCents / 100, $providerCents / 100, $bookingId]);
            $quoteMessage = 'Your provider quoted KES ' . number_format($amountCents / 100, 2) . '. Platform commission: KES ' . number_format($commissionCents / 100, 2) . '. Provider earnings: KES ' . number_format($providerCents / 100, 2) . '.';
            $pdo->prepare('INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, ?, ?)')
                ->execute([$bookingId, $user['id'], 'awaiting_payment', 'Provider submitted a quote.']);
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Your booking quote is ready', ?)")
                ->execute([$item['customer_id'], $bookingId, $quoteMessage]);
            $customerQuery = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $customerQuery->execute([$item['customer_id']]);
            $quoteEmail = $customerQuery->fetchColumn();
        } elseif ($action === 'start_class') {
            $otp = trim((string) ($_POST['class_otp'] ?? ''));
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id'] || $item['module'] !== 'tutoring'
                || $item['status'] !== 'paid' || $item['class_started_at'] !== null
                || !preg_match('/^\d{6}$/', $otp) || !is_string($item['class_otp'])
                || !hash_equals($item['class_otp'], $otp) || $item['class_otp_expires_at'] === null
                || strtotime($item['class_otp_expires_at']) <= time()) {
                $pdo->rollBack();
                page_error('The class code is invalid or expired, or this class cannot be started.', 403);
            }
            $pdo->prepare('UPDATE bookings SET class_started_at = NOW(), class_otp = NULL, class_otp_expires_at = NULL WHERE id = ? AND class_started_at IS NULL')
                ->execute([$bookingId]);
            $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'class_started', 'Tutor verified the student code and started the lesson.')")
                ->execute([$bookingId, $user['id']]);
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Your class has started', ?)")
                ->execute([$item['customer_id'], $bookingId, 'Your tutor verified the one-time code. The class has started.']);
        } elseif ($action === 'regenerate_class_code') {
            if (!$isCustomer || (int) $item['customer_id'] !== (int) $user['id'] || $item['module'] !== 'tutoring'
                || $item['status'] !== 'paid' || $item['class_started_at'] !== null
                || ($item['class_otp_expires_at'] !== null && strtotime($item['class_otp_expires_at']) > time())) {
                $pdo->rollBack();
                page_error('A new class code is not available for this booking yet.', 403);
            }
            $classOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE bookings SET class_otp = ?, class_otp_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ? AND class_started_at IS NULL')
                ->execute([$classOtp, $bookingId]);
            $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'class_code_regenerated', 'Student regenerated an expired one-time class code.')")
                ->execute([$bookingId, $user['id']]);
        } elseif ($action === 'on_the_way') {
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id']
                || $item['module'] !== 'home_services' || $item['status'] !== 'paid'
                || $item['paid_gateway'] === null) {
                $pdo->rollBack();
                page_error('Only the assigned provider can move a paid home-service booking through its next status.', 403);
            }
            $serviceStartOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare(
                "UPDATE bookings SET status = 'on_the_way', service_start_otp = ?,
                    service_start_otp_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                 WHERE id = ? AND status = 'paid' AND service_started_at IS NULL"
            )->execute([$serviceStartOtp, $bookingId]);
            $pdo->prepare('INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, ?, ?)')
                ->execute([$bookingId, $user['id'], 'on_the_way', 'Provider marked that they are on the way; a service start code was issued to the customer.']);
            $statusMessage = 'Your provider is on the way for ' . $item['name']
                . '. Your service start code is ready in your dashboard. Share it through the booking conversation or when the provider arrives.';
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Your provider is on the way', ?)")
                ->execute([$item['customer_id'], $bookingId, $statusMessage]);
            $customerEmailQuery = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $customerEmailQuery->execute([$item['customer_id']]);
            $serviceOtpEmail = (string) $customerEmailQuery->fetchColumn();
        } elseif ($action === 'regenerate_service_otp') {
            if (!$isCustomer || (int) $item['customer_id'] !== (int) $user['id'] || $item['module'] !== 'home_services'
                || $item['status'] !== 'on_the_way' || $item['service_started_at'] !== null
                || ($item['service_start_otp_expires_at'] !== null && strtotime($item['service_start_otp_expires_at']) > time())) {
                $pdo->rollBack();
                page_error('A new service start code is available only after the current code expires while your provider is on the way.', 403);
            }
            $serviceStartOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare(
                'UPDATE bookings SET service_start_otp = ?, service_start_otp_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                 WHERE id = ? AND status = \'on_the_way\' AND service_started_at IS NULL'
            )->execute([$serviceStartOtp, $bookingId]);
            $pdo->prepare('INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, ?, ?)')
                ->execute([$bookingId, $user['id'], 'service_code_regenerated', 'Customer generated a new service start code.']);
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'A new service start code is ready', ?)")
                ->execute([$item['customer_id'], $bookingId, 'A new service start code is ready in your dashboard. Share it with your provider through the booking conversation or when they arrive.']);
            $customerEmailQuery = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $customerEmailQuery->execute([$item['customer_id']]);
            $serviceOtpEmail = (string) $customerEmailQuery->fetchColumn();
        } elseif ($action === 'start_service') {
            $otp = trim((string) ($_POST['service_otp'] ?? ''));
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id'] || $item['module'] !== 'home_services'
                || $item['status'] !== 'on_the_way' || $item['paid_gateway'] === null
                || $item['service_started_at'] !== null || !preg_match('/^\d{6}$/', $otp)
                || !is_string($item['service_start_otp']) || !hash_equals($item['service_start_otp'], $otp)
                || $item['service_start_otp_expires_at'] === null
                || strtotime($item['service_start_otp_expires_at']) <= time()) {
                $pdo->rollBack();
                page_error('The service start code is invalid or expired, or this service cannot be started.', 403);
            }
            $pdo->prepare(
                "UPDATE bookings SET status = 'in_progress', service_started_at = NOW(),
                    service_start_otp = NULL, service_start_otp_expires_at = NULL
                 WHERE id = ? AND status = 'on_the_way' AND service_started_at IS NULL"
            )->execute([$bookingId]);
            $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'in_progress', 'Provider verified the customer service start code before beginning work.')")
                ->execute([$bookingId, $user['id']]);
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Service started', ?)")
                ->execute([$item['customer_id'], $bookingId, 'Your provider verified your service start code and has begun ' . $item['name'] . '.']);
        } elseif ($action === 'complete') {
            $validCompletionStatus = ($item['module'] === 'tutoring' && in_array($item['status'], ['paid', 'completed'], true))
                || ($item['module'] === 'home_services' && $item['status'] === 'in_progress' && $item['service_started_at'] !== null);
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id'] || !$validCompletionStatus
                || $item['provider_completed_at'] !== null
                || ($item['module'] === 'tutoring' && $item['status'] === 'paid' && $item['class_started_at'] === null)) {
                $pdo->rollBack();
                page_error('Only the assigned provider can confirm this paid booking, and only once.', 403);
            }
            if ($item['module'] === 'tutoring') {
                $pdo->prepare('UPDATE bookings SET provider_completed_at = NOW() WHERE id = ? AND provider_completed_at IS NULL')
                    ->execute([$bookingId]);
                $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'provider_completed', 'Tutor confirmed the lesson is complete.')")
                    ->execute([$bookingId, $user['id']]);
                if ($item['customer_completed_at'] !== null) {
                    $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ? AND status = 'paid'")
                        ->execute([$bookingId]);
                    $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'completed', 'Both tutor and student confirmed the lesson is complete.')")
                        ->execute([$bookingId, $user['id']]);
                    $body = 'Both you and your tutor have confirmed the lesson is complete. Your tutor can now request withdrawal.';
                } else {
                    $body = 'Your tutor marked the lesson complete. Confirm the lesson in your dashboard before withdrawal can be requested.';
                }
                $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Lesson completion update', ?)")
                    ->execute([$item['customer_id'], $bookingId, $body]);
            } else {
                $completionStatus = $item['customer_completed_at'] !== null ? 'completed' : 'in_progress';
                $pdo->prepare(
                    "UPDATE bookings SET provider_completed_at = NOW(), status = ?
                     WHERE id = ? AND status = 'in_progress' AND provider_completed_at IS NULL"
                )->execute([$completionStatus, $bookingId]);
                $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'provider_completed', 'Provider marked the service complete.')")
                    ->execute([$bookingId, $user['id']]);
                if ($item['customer_completed_at'] !== null) {
                    $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'completed', 'Both provider and customer confirmed the service is complete.')")
                        ->execute([$bookingId, $user['id']]);
                    $body = 'Both you and your provider have confirmed ' . $item['name'] . ' is complete. You can now leave a review.';
                    $subject = 'Service completion confirmed';
                } else {
                    $body = 'Your provider marked ' . $item['name'] . ' complete. Confirm the service in your dashboard to finish the booking and leave a review.';
                    $subject = 'Confirm service completion';
                }
                $pdo->prepare('INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, \'in_app\', ?, ?)')
                    ->execute([$item['customer_id'], $bookingId, $subject, $body]);
            }
        } elseif ($action === 'confirm_complete') {
            if ($item['module'] === 'tutoring') {
                if (!$isCustomer || (int) $item['customer_id'] !== (int) $user['id']
                    || !in_array($item['status'], ['paid', 'completed'], true) || $item['customer_completed_at'] !== null
                    || ($item['status'] === 'paid' && $item['class_started_at'] === null)) {
                    $pdo->rollBack();
                    page_error('Only the assigned student can confirm this lesson, and only once.', 403);
                }
                $pdo->prepare('UPDATE bookings SET customer_completed_at = NOW() WHERE id = ? AND customer_completed_at IS NULL')
                    ->execute([$bookingId]);
                $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'customer_completed', 'Student confirmed the lesson is complete.')")
                    ->execute([$bookingId, $user['id']]);
                if ($item['provider_completed_at'] !== null) {
                    $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ? AND status = 'paid'")
                        ->execute([$bookingId]);
                    $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'completed', 'Both tutor and student confirmed the lesson is complete.')")
                        ->execute([$bookingId, $user['id']]);
                    $body = 'Both you and your student have confirmed the lesson is complete. The tutor can now request withdrawal.';
                } else {
                    $body = 'The student confirmed the lesson is complete. Please confirm it in your dashboard to finish the completion process.';
                }
                $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Lesson completion update', ?)")
                    ->execute([$item['provider_id'], $bookingId, $body]);
            } elseif ($item['module'] === 'home_services') {
                if (!$isCustomer || (int) $item['customer_id'] !== (int) $user['id']
                    || !in_array($item['status'], ['in_progress', 'completed'], true)
                    || ($item['status'] === 'in_progress' && $item['service_started_at'] === null)
                    || $item['provider_completed_at'] === null || $item['customer_completed_at'] !== null) {
                    $pdo->rollBack();
                    page_error('Only the assigned customer can confirm this service after the provider marks it complete.', 403);
                }
                $pdo->prepare(
                    "UPDATE bookings SET customer_completed_at = NOW(), status = 'completed'
                     WHERE id = ? AND status IN ('in_progress', 'completed') AND customer_completed_at IS NULL"
                )->execute([$bookingId]);
                $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'customer_completed', 'Customer confirmed the provider-completed service.')")
                    ->execute([$bookingId, $user['id']]);
                if ($item['status'] !== 'completed') {
                    $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'completed', 'Both provider and customer confirmed the service is complete.')")
                        ->execute([$bookingId, $user['id']]);
                }
                $body = 'The customer confirmed ' . $item['name'] . ' is complete. Both parties have now confirmed the service.';
                $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Service completion confirmed', ?)")
                    ->execute([$item['provider_id'], $bookingId, $body]);
            } else {
                $pdo->rollBack();
                page_error('This booking cannot be confirmed as complete.', 403);
            }
        } elseif ($action === 'request_withdrawal') {
            $eligiblePayment = ($item['paid_gateway'] === 'paystack' && $item['paid_payout_mode'] === 'platform_held')
                || (APP_ENV === 'local' && $item['paid_gateway'] === 'demo');
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id']
                || !in_array($item['module'], ['tutoring', 'home_services'], true)
                || $item['status'] !== 'completed' || $item['provider_completed_at'] === null
                || $item['customer_completed_at'] === null || !$eligiblePayment || $item['refund_status'] !== null) {
                $pdo->rollBack();
                page_error('A withdrawal is available only after both parties confirm an eligible payment as complete.', 403);
            }
            $existingRequest = $pdo->prepare('SELECT id FROM tutor_withdrawal_requests WHERE booking_id = ?');
            $existingRequest->execute([$bookingId]);
            $existingRequestId = $existingRequest->fetchColumn();
            if ($existingRequestId) {
                $resetRejected = $pdo->prepare(
                    "UPDATE tutor_withdrawal_requests
                     SET status = 'pending', amount = ?, commission_amount = ?, requested_at = NOW(),
                         approved_by = NULL, approved_at = NULL, payout_method = NULL,
                         payout_details_encrypted = NULL, payout_reference = NULL, released_by = NULL, released_at = NULL
                     WHERE id = ? AND status = 'rejected'"
                );
                $resetRejected->execute([$item['provider_amount'] ?? 0, $item['commission_amount'] ?? 0, $existingRequestId]);
                if ($resetRejected->rowCount() !== 1) {
                    $pdo->rollBack();
                    page_error('A withdrawal request already exists for this lesson.', 409);
                }
            } else {
                $pdo->prepare(
                    "INSERT INTO tutor_withdrawal_requests (booking_id, requested_by, amount, commission_amount)
                     VALUES (?, ?, ?, ?)"
                )->execute([$bookingId, $user['id'], $item['provider_amount'] ?? 0, $item['commission_amount'] ?? 0]);
            }
            $admins = $pdo->prepare(
                "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                 LEFT JOIN admin_permissions ap ON ap.user_id = u.id AND ap.module = ?
                 WHERE u.is_active = 1 AND (r.slug = 'super_admin' OR (r.slug IN ('admin1','admin2') AND ap.manage_payments = 1))"
            );
            $admins->execute([$item['module']]);
            $adminBody = 'Provider ' . $item['provider_name'] . ' requested KES '
                . number_format((float) ($item['provider_amount'] ?? 0), 2)
                . ' for ' . ($item['module'] === 'tutoring' ? 'tutoring' : 'home-service')
                . ' booking #' . $bookingId . '.'
                . ($item['paid_gateway'] === 'demo'
                    ? ' This is a local demo request; no money will be transferred.'
                    : ' Verify and pay the provider before recording release.');
            $notifyAdmin = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Provider withdrawal requested', ?)");
            foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                $notifyAdmin->execute([$adminId, $bookingId, $adminBody]);
            }
        } elseif ($action === 'submit_payout') {
            $payoutMethod = (string) ($_POST['payout_method'] ?? '');
            $payoutDetails = trim((string) ($_POST['payout_details'] ?? ''));
            $withdrawalQuery = $pdo->prepare('SELECT id, status FROM tutor_withdrawal_requests WHERE booking_id = ? AND requested_by = ? FOR UPDATE');
            $withdrawalQuery->execute([$bookingId, $user['id']]);
            $withdrawal = $withdrawalQuery->fetch();
            $eligiblePayment = ($item['paid_gateway'] === 'paystack' && $item['paid_payout_mode'] === 'platform_held')
                || (APP_ENV === 'local' && $item['paid_gateway'] === 'demo');
            $validDestination = ($payoutMethod === 'mobile_money' && preg_match('/^(?:\+?254|0)?[17]\d{8}$/', preg_replace('/[\s()-]/', '', $payoutDetails)))
                || ($payoutMethod === 'bank' && mb_strlen($payoutDetails) >= 12 && mb_strlen($payoutDetails) <= 500);
            if (!$isProvider || (int) $item['provider_id'] !== (int) $user['id']
                || !in_array($item['module'], ['tutoring', 'home_services'], true)
                || $item['status'] !== 'completed' || $item['provider_completed_at'] === null
                || $item['customer_completed_at'] === null || !$withdrawal || $withdrawal['status'] !== 'approved'
                || !$eligiblePayment || !$validDestination) {
                $pdo->rollBack();
                page_error('This approved provider payout needs valid mobile-money or bank details.', 403);
            }
            $encryptedDetails = encrypt_payout_details($payoutDetails);
            $pdo->prepare(
                "UPDATE tutor_withdrawal_requests
                 SET status = 'payout_requested', payout_method = ?, payout_details_encrypted = ?
                 WHERE id = ? AND status = 'approved'"
            )->execute([$payoutMethod, $encryptedDetails, $withdrawal['id']]);
            $admins = $pdo->prepare(
                "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                 LEFT JOIN admin_permissions ap ON ap.user_id = u.id AND ap.module = ?
                 WHERE u.is_active = 1 AND (r.slug = 'super_admin' OR (r.slug IN ('admin1','admin2') AND ap.manage_payments = 1))"
            );
            $admins->execute([$item['module']]);
            $adminNotice = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Provider payout details submitted', ?)");
            foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                $adminNotice->execute([
                    $adminId,
                    $bookingId,
                    'Provider ' . $item['provider_name'] . ' submitted payout details for booking #' . $bookingId . '. '
                    . ($item['paid_gateway'] === 'demo'
                        ? 'This is a demo workflow; record the status only. No money will be transferred.'
                        : 'Review and record the manual payout in Payments.'),
                ]);
            }
        } elseif ($action === 'request_refund') {
            $reason = trim((string) ($_POST['refund_reason'] ?? ''));
            if (!$isCustomer || (int) $item['customer_id'] !== (int) $user['id'] || $item['module'] !== 'tutoring'
                || !in_array($item['status'], ['paid', 'completed'], true)
                || !in_array($item['paid_gateway'], ['paystack', 'demo'], true)
                || $item['withdrawal_status'] !== null || $item['refund_status'] !== null
                || $reason === '' || mb_strlen($reason) > 2000) {
                $pdo->rollBack();
                page_error('A refund can only be requested for an eligible paid lesson; include a reason.', 403);
            }
            $pdo->prepare('INSERT INTO refund_requests (booking_id, requested_by, reason) VALUES (?, ?, ?)')
                ->execute([$bookingId, $user['id'], $reason]);
            $admins = $pdo->prepare(
                "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                 LEFT JOIN admin_permissions ap ON ap.user_id = u.id AND ap.module = 'tutoring'
                 WHERE u.is_active = 1 AND (r.slug = 'super_admin' OR (r.slug IN ('admin1','admin2') AND ap.manage_payments = 1))"
            );
            $admins->execute();
            $notifyAdmin = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Student refund requested', ?)");
            foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                $notifyAdmin->execute([$adminId, $bookingId, 'A student requested a refund for tutoring booking #' . $bookingId . '. Review the request in Payments.']);
            }
        } elseif ($action === 'review') {
            $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
            $review = trim((string) ($_POST['review'] ?? ''));
            if (!$isCustomer || (int) $item['customer_id'] !== (int) $user['id'] || $item['status'] !== 'completed'
                || ($item['module'] === 'home_services'
                    && ($item['provider_completed_at'] === null || $item['customer_completed_at'] === null))
                || !$rating || $rating < 1 || $rating > 5 || mb_strlen($review) > 2000) {
                $pdo->rollBack();
                page_error('A review requires a completed booking; home services also require both completion confirmations.', 403);
            }
            $pdo->prepare('INSERT INTO reviews (booking_id, customer_id, provider_id, rating, comment) VALUES (?, ?, ?, ?, ?)')
                ->execute([$bookingId, $user['id'], $item['provider_id'], $rating, $review ?: null]);
            $pdo->prepare('UPDATE provider_profiles SET average_rating = (SELECT AVG(rating) FROM reviews WHERE provider_id = ?) WHERE user_id = ?')
                ->execute([$item['provider_id'], $item['provider_id']]);
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'You received a review', ?)")
                ->execute([$item['provider_id'], $bookingId, 'A customer left a ' . $rating . '-star review for your service.']);
        } elseif ($action === 'reassign') {
            if (!$isAdmin || $item['status'] !== 'declined' || !admin_has_permission($item['module'], 'manage_bookings')) {
                $pdo->rollBack();
                page_error('You do not have permission to reassign this booking.', 403);
            }
            $newProviderId = filter_var($_POST['provider_id'] ?? null, FILTER_VALIDATE_INT);
            $candidateLock = $pdo->prepare('SELECT user_id FROM provider_profiles WHERE user_id = ? FOR UPDATE');
            $candidateLock->execute([$newProviderId ?: 0]);
            $lockedCandidateId = $candidateLock->fetchColumn();
            $candidate = $pdo->prepare(
                "SELECT u.id, u.full_name, u.email FROM users u
                 JOIN provider_profiles pp ON pp.user_id = u.id AND pp.approval_status = 'approved'
                 JOIN provider_services ps ON ps.provider_id = u.id
                 WHERE u.id = ? AND ps.category_id = ? AND pp.is_available = 1
                 AND u.is_active = 1 AND u.email_verified_at IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM bookings busy WHERE busy.provider_id = u.id
                     AND busy.status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress'))"
            );
            $candidate->execute([$newProviderId, $item['category_id']]);
            $replacement = $candidate->fetch();
            if (!$lockedCandidateId || !$replacement || (int) $replacement['id'] === (int) $item['provider_id']) {
                $pdo->rollBack();
                flash('error', 'Choose an available, verified provider for this category.');
                header('Location: ' . url($dashboardReturnPath));
                exit;
            }
            $refundBeforeReassign = $item['checkout_order_id'] !== null && $item['latest_payment_status'] === 'refunded';
            if ($item['checkout_order_id'] !== null && $item['paid_gateway'] === 'paystack' && !$refundBeforeReassign) {
                $pdo->rollBack();
                flash('error', 'Process and record the Paystack refund before assigning this paid request to another provider.');
                header('Location: ' . url($dashboardReturnPath));
                exit;
            }
            $nextStatus = $refundBeforeReassign ? 'awaiting_payment' : 'requested';
            $pdo->prepare('UPDATE bookings SET provider_id = ?, status = ? WHERE id = ? AND status = \'declined\'')
                ->execute([$replacement['id'], $nextStatus, $bookingId]);
            $pdo->prepare('INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, ?, ?)')
                ->execute([
                    $bookingId, $user['id'], $nextStatus,
                    $refundBeforeReassign
                        ? 'Admin processed the refund and selected a replacement provider. Customer payment is required before the new request is sent.'
                        : 'Admin linked a replacement provider and re-sent the request.',
                ]);
            if (!$refundBeforeReassign) {
                $body = 'A new ' . $item['name'] . ' request has been assigned to you. Sign in to accept or decline.';
                $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'New booking request', ?)")
                    ->execute([$replacement['id'], $bookingId, $body]);
            } else {
                $body = 'Your refund was recorded and a replacement provider was selected. Pay the updated booking again to send the request.';
            }
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Your request has a new match', ?)")
                ->execute([$item['customer_id'], $bookingId, $body]);
        } else {
            $pdo->rollBack();
            page_error('That action is not available.', 400);
        }

        $pdo->commit();
        if (APP_ENV !== 'local') {
            if (in_array($action, ['on_the_way', 'regenerate_service_otp'], true) && $serviceStartOtp !== null) {
                $otpBody = 'Your LertFind provider is on the way for booking #' . $bookingId . '. Your service start code is '
                    . $serviceStartOtp . '. Share it with the provider through the booking conversation or when they arrive. '
                    . 'It expires 24 hours after issue. The provider must enter it before work can begin.';
                if (!filter_var($serviceOtpEmail, FILTER_VALIDATE_EMAIL)
                    || !mail($serviceOtpEmail, 'Your LertFind service start code', $otpBody, "From: LertFind <no-reply@tutalink.co.ke>\r\n")) {
                    error_log('LertFind service start-code email could not be sent for booking ID ' . $bookingId . '. The code remains available in the customer dashboard.');
                }
            } elseif ($action === 'decline') {
                foreach ($adminRecipients as $admin) {
                    mail($admin['email'], 'LertFind booking needs a new match', 'Booking #' . $bookingId . ' was declined and needs admin follow-up.', "From: LertFind <no-reply@tutalink.co.ke>\r\n");
                }
                if ($customerEmail) {
                    mail($customerEmail, 'Your LertFind request needs a new match', $customerMessage, "From: LertFind <no-reply@tutalink.co.ke>\r\n");
                }
            } elseif ($action === 'accept' && $customerEmail) {
                $emailSubject = $item['module'] === 'tutoring'
                    ? 'Your tutoring price is ready'
                    : ($item['module'] === 'home_services' && !$prepaidCheckout ? 'Your home-service price is ready' : 'Your LertFind request was accepted');
                mail($customerEmail, $emailSubject, $customerMessage, "From: LertFind <no-reply@tutalink.co.ke>\r\n");
            } elseif ($action === 'reassign' && !$refundBeforeReassign) {
                mail($replacement['email'], 'New LertFind booking request', $body, "From: LertFind <no-reply@tutalink.co.ke>\r\n");
            } elseif ($action === 'reassign' && $refundBeforeReassign) {
                $customerEmailQuery = $pdo->prepare('SELECT email FROM users WHERE id = ?');
                $customerEmailQuery->execute([$item['customer_id']]);
                $reassignmentCustomerEmail = (string) $customerEmailQuery->fetchColumn();
                if (filter_var($reassignmentCustomerEmail, FILTER_VALIDATE_EMAIL)) {
                    mail($reassignmentCustomerEmail, 'Refund recorded; payment required', $body, "From: LertFind <no-reply@tutalink.co.ke>\r\n");
                }
            } elseif ($action === 'quote' && $quoteEmail) {
                mail($quoteEmail, 'Your LertFind booking quote is ready', $quoteMessage, "From: LertFind <no-reply@tutalink.co.ke>\r\n");
            } elseif ($action === 'complete' && ($item['module'] !== 'tutoring' || $item['customer_completed_at'] !== null)) {
                $customerEmail = $pdo->prepare('SELECT email FROM users WHERE id = ?');
                $customerEmail->execute([$item['customer_id']]);
                $completionSubject = $item['module'] === 'home_services' && $item['customer_completed_at'] === null
                    ? 'Please confirm your LertFind service'
                    : 'Your LertFind service is complete';
                mail((string) $customerEmail->fetchColumn(), $completionSubject, $body, "From: LertFind <no-reply@tutalink.co.ke>\r\n");
            }
        }
        $successMessage = match ($action) {
            'accept' => match (true) {
                $prepaidCheckout => 'Booking accepted. The customer has already paid.',
                $item['module'] === 'tutoring' => 'Booking accepted. The student’s session total has been calculated from your hourly rate.',
                $item['module'] === 'home_services' => 'Booking accepted. The customer can pay the admin-set service price from their dashboard.',
                default => 'Booking accepted.',
            },
            'decline' => 'Booking declined. The relevant admins have been notified.',
            'reassign' => $refundBeforeReassign
                ? 'Refund recorded and replacement provider selected. Customer payment is required before dispatch.'
                : 'The request has been sent to the replacement provider.',
            'quote' => 'Your quote has been sent to the customer.',
            'complete' => $item['module'] === 'tutoring'
                ? ($item['customer_completed_at'] === null
                    ? 'You marked the lesson complete. The student must also confirm before withdrawal is available.'
                    : 'The lesson is complete. The student and tutor have both confirmed.')
                : ($item['customer_completed_at'] === null
                    ? 'You marked the service complete. The customer must confirm before it is fully complete or can be rated.'
                    : 'The customer and provider have both confirmed the service is complete.'),
            'start_class' => 'The tutor verified the code and started the lesson.',
            'regenerate_class_code' => 'Your new one-time class code is ready.',
            'confirm_complete' => $item['module'] === 'home_services'
                ? 'You confirmed the service is complete. You can now leave a review for your provider.'
                : ($item['provider_completed_at'] === null
                    ? 'You confirmed the lesson. The tutor must also confirm before withdrawal is available.'
                    : 'The lesson is complete. The tutor can now request withdrawal.'),
            'request_withdrawal' => $item['paid_gateway'] === 'demo'
                ? 'Demo withdrawal request submitted for admin review.'
                : 'Withdrawal requested. An admin will review it and record release after paying you.',
            'submit_payout' => $item['paid_gateway'] === 'demo'
                ? 'Demo payout details submitted for admin status review.'
                : 'Your payout details were submitted securely. An admin will arrange the payment.',
            'on_the_way' => 'The customer has been notified that you are on the way.',
            'regenerate_service_otp' => 'A new service start code is ready in your dashboard and has been emailed if delivery is configured.',
            'start_service' => 'The customer’s code was verified and the service is marked as in progress.',
            'request_refund' => 'Your refund request was sent to the LertFind team.',
            default => 'Thank you for sharing your review.',
        };
        flash('success', $successMessage);
        header('Location: ' . url($dashboardReturnPath));
        exit;
    } catch (RuntimeException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        page_error($exception->getMessage(), 503);
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        page_error('We could not update that booking. Please try again.');
    }
}

try {
    $pdo = db();
    $rateValue = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'commission_percent'")->fetchColumn();
    if ($rateValue === false || !is_numeric($rateValue) || (float) $rateValue < 0 || (float) $rateValue > 100) {
        page_error('The platform commission has not been configured.');
    }
    $commissionRate = (float) $rateValue;
    if ($isProvider) {
        $statement = $pdo->prepare(
            'SELECT b.*, c.name AS service_name, c.module, c.slug, tl.name AS teaching_level_name,
                    COALESCE(b.booked_hourly_rate, c.hourly_rate) AS service_hourly_rate,
                    COALESCE(b.booked_service_price, c.service_price) AS service_price,
                    (SELECT o.status FROM checkout_orders o WHERE o.id = b.checkout_order_id) AS checkout_order_status,
                    (SELECT o.total_amount FROM checkout_orders o WHERE o.id = b.checkout_order_id) AS checkout_total,
                    customer.full_name AS customer_name,
                    r.id AS review_id, r.rating AS customer_rating,
                    (SELECT payment.gateway FROM payments payment WHERE payment.booking_id = b.id AND payment.status = \'paid\' ORDER BY payment.id DESC LIMIT 1) AS paid_gateway,
                    (SELECT payment.payout_mode FROM payments payment WHERE payment.booking_id = b.id AND payment.status = \'paid\' ORDER BY payment.id DESC LIMIT 1) AS paid_payout_mode,
                    (SELECT payment.status FROM payments payment WHERE payment.booking_id = b.id ORDER BY payment.id DESC LIMIT 1) AS latest_payment_status,
                    (SELECT wr.status FROM tutor_withdrawal_requests wr WHERE wr.booking_id = b.id LIMIT 1) AS withdrawal_status,
                    (SELECT rr.status FROM refund_requests rr WHERE rr.booking_id = b.id LIMIT 1) AS refund_status
             FROM bookings b JOIN service_categories c ON c.id = b.category_id
             LEFT JOIN tutor_teaching_levels tl ON tl.id = b.teaching_level_id
             JOIN users customer ON customer.id = b.customer_id
             JOIN provider_profiles pp ON pp.user_id = b.provider_id
             LEFT JOIN reviews r ON r.booking_id = b.id
             WHERE b.provider_id = ? ORDER BY b.created_at DESC LIMIT 100'
        );
        $statement->execute([$user['id']]);
        $bookings = $statement->fetchAll();
        $earnings = $pdo->prepare(
            "SELECT COALESCE(SUM(b.provider_amount), 0) FROM bookings b
             WHERE b.provider_id = ? AND b.status IN ('paid','on_the_way','in_progress','completed')
               AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.gateway = 'paystack' AND p.status = 'paid')"
        );
        $earnings->execute([$user['id']]);
        $providerEarnings = (float) $earnings->fetchColumn();
        $availableQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(wr.amount), 0) FROM tutor_withdrawal_requests wr
             WHERE wr.requested_by = ? AND wr.status = 'approved'
               AND EXISTS (
                   SELECT 1 FROM payments p
                   WHERE p.booking_id = wr.booking_id AND p.gateway = 'paystack'
                     AND p.status = 'paid' AND p.payout_mode = 'platform_held'
               )"
        );
        $availableQuery->execute([$user['id']]);
        $availableBalance = (float) $availableQuery->fetchColumn();
        $payoutQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(wr.amount), 0) FROM tutor_withdrawal_requests wr
             WHERE wr.requested_by = ? AND wr.status = 'payout_requested'
               AND EXISTS (
                   SELECT 1 FROM payments p
                   WHERE p.booking_id = wr.booking_id AND p.gateway = 'paystack'
                     AND p.status = 'paid' AND p.payout_mode = 'platform_held'
               )"
        );
        $payoutQuery->execute([$user['id']]);
        $payoutInProgress = (float) $payoutQuery->fetchColumn();
    } elseif ($isCustomer) {
        $statement = $pdo->prepare(
            'SELECT b.*, c.name AS service_name, c.module, c.slug, tl.name AS teaching_level_name,
                    COALESCE(b.booked_hourly_rate, c.hourly_rate) AS service_hourly_rate,
                    COALESCE(b.booked_service_price, c.service_price) AS service_price,
                    (SELECT o.status FROM checkout_orders o WHERE o.id = b.checkout_order_id) AS checkout_order_status,
                    (SELECT o.total_amount FROM checkout_orders o WHERE o.id = b.checkout_order_id) AS checkout_total,
                    provider.full_name AS provider_name,
                    r.id AS review_id, r.rating AS customer_rating,
                    (SELECT payment.gateway FROM payments payment WHERE payment.booking_id = b.id AND payment.status = \'paid\' ORDER BY payment.id DESC LIMIT 1) AS paid_gateway,
                    (SELECT payment.payout_mode FROM payments payment WHERE payment.booking_id = b.id AND payment.status = \'paid\' ORDER BY payment.id DESC LIMIT 1) AS paid_payout_mode,
                    (SELECT payment.status FROM payments payment WHERE payment.booking_id = b.id ORDER BY payment.id DESC LIMIT 1) AS latest_payment_status,
                    (SELECT wr.status FROM tutor_withdrawal_requests wr WHERE wr.booking_id = b.id LIMIT 1) AS withdrawal_status,
                    (SELECT rr.status FROM refund_requests rr WHERE rr.booking_id = b.id LIMIT 1) AS refund_status
             FROM bookings b JOIN service_categories c ON c.id = b.category_id
             LEFT JOIN tutor_teaching_levels tl ON tl.id = b.teaching_level_id
             JOIN users provider ON provider.id = b.provider_id
             JOIN provider_profiles pp ON pp.user_id = b.provider_id
             LEFT JOIN reviews r ON r.booking_id = b.id
             WHERE b.customer_id = ? ORDER BY b.created_at DESC LIMIT 100'
        );
        $statement->execute([$user['id']]);
        $bookings = $statement->fetchAll();
    } elseif ($isAdmin) {
        $modules = [];
        foreach (['tutoring', 'home_services'] as $module) {
            if (admin_has_permission($module, 'manage_bookings')) $modules[] = $module;
        }
        if ($user['role'] === 'super_admin') $modules = ['tutoring', 'home_services'];
        $providerModules = [];
        foreach (['tutoring', 'home_services'] as $module) {
            if (admin_has_permission($module, 'manage_providers')) $providerModules[] = $module;
        }
        if ($user['role'] === 'super_admin') $providerModules = ['tutoring', 'home_services'];
        $canManageProviderApprovals = (bool) $providerModules;
        if ($providerModules) {
            $providerPlaceholders = implode(',', array_fill(0, count($providerModules), '?'));
            $approvalQuery = $pdo->prepare(
                "SELECT COUNT(DISTINCT p.user_id)
                 FROM provider_profiles p
                 JOIN provider_services ps ON ps.provider_id = p.user_id
                 JOIN service_categories c ON c.id = ps.category_id
                 WHERE p.approval_status = 'pending' AND c.module IN ({$providerPlaceholders})"
            );
            $approvalQuery->execute($providerModules);
            $pendingProviderApprovals = (int) $approvalQuery->fetchColumn();
        }
        if ($modules) {
            $placeholders = implode(',', array_fill(0, count($modules), '?'));
            $analyticsQuery = $pdo->prepare(
                "SELECT COUNT(*) AS total_bookings,
                        SUM(b.status = 'requested') AS pending_requests,
                        SUM(b.status = 'completed') AS completed_bookings,
                        COALESCE(SUM(CASE WHEN EXISTS (
                            SELECT 1 FROM payments payment WHERE payment.booking_id = b.id AND payment.status = 'paid'
                        ) THEN b.quoted_amount ELSE 0 END), 0) AS payment_volume
                 FROM bookings b JOIN service_categories c ON c.id = b.category_id
                 WHERE c.module IN ({$placeholders})"
            );
            $analyticsQuery->execute($modules);
            $adminAnalytics = $analyticsQuery->fetch() ?: [];
            $trendQuery = $pdo->prepare(
                "SELECT DATE(b.created_at) AS booking_day, COUNT(*) AS booking_count
                 FROM bookings b JOIN service_categories c ON c.id = b.category_id
                 WHERE c.module IN ({$placeholders}) AND b.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                 GROUP BY DATE(b.created_at) ORDER BY booking_day"
            );
            $trendQuery->execute($modules);
            foreach ($trendQuery->fetchAll() as $trendRow) {
                $adminBookingTrend[$trendRow['booking_day']] = (int) $trendRow['booking_count'];
            }
            $statement = $pdo->prepare(
                "SELECT b.*, c.name AS service_name, c.module, c.slug, tl.name AS teaching_level_name,
                        COALESCE(b.booked_hourly_rate, c.hourly_rate) AS service_hourly_rate,
                        COALESCE(b.booked_service_price, c.service_price) AS service_price,
                        (SELECT o.status FROM checkout_orders o WHERE o.id = b.checkout_order_id) AS checkout_order_status,
                        (SELECT o.total_amount FROM checkout_orders o WHERE o.id = b.checkout_order_id) AS checkout_total,
                        customer.full_name AS customer_name, provider.full_name AS provider_name,
                        (SELECT payment.gateway FROM payments payment WHERE payment.booking_id = b.id AND payment.status = 'paid' ORDER BY payment.id DESC LIMIT 1) AS paid_gateway,
                        (SELECT payment.payout_mode FROM payments payment WHERE payment.booking_id = b.id AND payment.status = 'paid' ORDER BY payment.id DESC LIMIT 1) AS paid_payout_mode,
                        (SELECT payment.status FROM payments payment WHERE payment.booking_id = b.id ORDER BY payment.id DESC LIMIT 1) AS latest_payment_status,
                        (SELECT wr.status FROM tutor_withdrawal_requests wr WHERE wr.booking_id = b.id LIMIT 1) AS withdrawal_status,
                        (SELECT rr.status FROM refund_requests rr WHERE rr.booking_id = b.id LIMIT 1) AS refund_status
                 FROM bookings b JOIN service_categories c ON c.id = b.category_id
                 LEFT JOIN tutor_teaching_levels tl ON tl.id = b.teaching_level_id
                 JOIN users customer ON customer.id = b.customer_id JOIN users provider ON provider.id = b.provider_id
                 JOIN provider_profiles pp ON pp.user_id = b.provider_id
                 WHERE c.module IN ({$placeholders}) ORDER BY b.created_at DESC LIMIT 100"
            );
            $statement->execute($modules);
            $bookings = $statement->fetchAll();
        } else {
            $bookings = [];
        }
    } else {
        $bookings = [];
    }
    if ($isProvider || $isCustomer) {
        $userColumn = $isProvider ? 'provider_id' : 'customer_id';
        $userAnalyticsQuery = $pdo->prepare(
            "SELECT COUNT(*) AS total_bookings,
                    SUM(b.status = 'requested') AS pending_requests,
                    SUM(b.status IN ('accepted','linked','awaiting_payment','paid','on_the_way','in_progress')) AS active_bookings,
                    SUM(b.status = 'completed') AS completed_bookings,
                    SUM(b.status IN ('declined','cancelled')) AS closed_bookings
             FROM bookings b WHERE b.{$userColumn} = ?"
        );
        $userAnalyticsQuery->execute([$user['id']]);
        $userAnalytics = $userAnalyticsQuery->fetch() ?: [];
        $userTrendQuery = $pdo->prepare(
            "SELECT DATE(b.created_at) AS booking_day, COUNT(*) AS booking_count
             FROM bookings b
             WHERE b.{$userColumn} = ? AND b.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(b.created_at) ORDER BY booking_day"
        );
        $userTrendQuery->execute([$user['id']]);
        foreach ($userTrendQuery->fetchAll() as $trendRow) {
            $userBookingTrend[$trendRow['booking_day']] = (int) $trendRow['booking_count'];
        }
    }
    $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')
        ->execute([$user['id']]);
    $notices = $pdo->prepare('SELECT subject, body, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
    $notices->execute([$user['id']]);
    $notifications = $notices->fetchAll();
    $statusHistory = [];
    if ($bookings) {
        $bookingIds = array_map('intval', array_column($bookings, 'id'));
        $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
        $historyQuery = $pdo->prepare(
            "SELECT h.booking_id, h.status, h.note, h.created_at, u.full_name AS actor_name
             FROM booking_status_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.booking_id IN ({$placeholders}) ORDER BY h.created_at, h.id"
        );
        $historyQuery->execute($bookingIds);
        foreach ($historyQuery->fetchAll() as $history) {
            $statusHistory[$history['booking_id']][] = $history;
        }
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <?php if ($isProvider && $providerApprovalStatus === 'pending'): ?><div class="notice notice-info provider-dashboard-review" role="status"><strong>Your profile is waiting for admin approval.</strong> You can use your dashboard, but your profile will not be listed for new requests until it is approved and available. <a href="<?= e(url('Pages/profile.php')) ?>">Review your profile</a>.</div><?php endif; ?>
    <div class="dashboard-welcome">
        <div><div class="eyebrow"><?= $isAdmin ? ($showRequests ? 'BOOKING OPERATIONS' : 'LERTFIND CONTROL CENTRE') : ($showRequests ? 'YOUR BOOKING TRAIL' : 'YOUR LERTFIND') ?></div><h1<?= $showRequests ? '' : ' class="dashboard-greeting"' ?>><?= $showRequests ? ($isAdmin ? 'Booking actions.' : 'Requests & progress.') : 'Good to see you, ' . e(explode(' ', $user['name'])[0]) . '.' ?></h1><p><?= $showRequests ? ($isAdmin ? 'Review requests and reassign declined bookings.' : 'Follow each booking from request to completion.') : ($isProvider ? 'New opportunities and your active requests, all in one place.' : ($isAdmin ? 'Manage requests within your assigned modules.' : 'Your activity and next steps, all in one place.')) ?></p></div>
        <?php if ($isProvider): ?><div class="earnings-card"><span>CONFIRMED EARNINGS</span><strong>KES <?= number_format($providerEarnings, 2) ?></strong><small>Verified real payments</small><span>AVAILABLE TO WITHDRAW</span><strong>KES <?= number_format($availableBalance, 2) ?></strong><?php if ($payoutInProgress > 0): ?><small>Withdrawal in progress: KES <?= number_format($payoutInProgress, 2) ?></small><?php endif; ?></div><?php else: ?><div class="role-badge"><?= e(ucwords(str_replace('_', ' ', $user['role']))) ?></div><?php endif; ?>
    </div>
    <?php if ($isAdmin && !$showRequests): ?>
        <?php $trendMaximum = max(1, ...array_values($adminBookingTrend ?: [0])); ?>
        <section class="admin-analytics" aria-label="System analytics">
            <div class="section-title"><div><div class="eyebrow">SYSTEM ANALYTICS</div><h2>Platform pulse</h2></div><a class="button button-outline button-small" href="<?= e(url('Admin/reports.php')) ?>">Full reports</a></div>
            <div class="analytics-card-grid admin-analytics-cards">
                <article class="analytics-card"><span>All bookings</span><strong><?= number_format((int) ($adminAnalytics['total_bookings'] ?? 0)) ?></strong><small>Within your managed modules</small></article>
                <article class="analytics-card"><span>Awaiting providers</span><strong><?= number_format((int) ($adminAnalytics['pending_requests'] ?? 0)) ?></strong><small>Requests to review</small></article>
                <article class="analytics-card"><span>Completed bookings</span><strong><?= number_format((int) ($adminAnalytics['completed_bookings'] ?? 0)) ?></strong><small>Provider and customer work completed</small></article>
                <article class="analytics-card"><span>Verified payment volume</span><strong>KES <?= number_format((float) ($adminAnalytics['payment_volume'] ?? 0), 2) ?></strong><small>Settled payments only</small></article>
                <?php if ($canManageProviderApprovals): ?><article class="analytics-card"><span>Profiles awaiting approval</span><strong><?= number_format($pendingProviderApprovals) ?></strong><small><a href="<?= e(url('Admin/providers.php')) ?>">Review provider profiles</a></small></article><?php endif; ?>
            </div>
            <article class="analytics-chart"><h3>Booking requests · last 7 days</h3><div class="analytics-bars"><?php for ($dayOffset = 6; $dayOffset >= 0; $dayOffset--): $day = date('Y-m-d', strtotime('-' . $dayOffset . ' days')); $count = $adminBookingTrend[$day] ?? 0; ?><div class="analytics-bar-column"><span><?= $count ?></span><i style="height: <?= max(4, (int) round($count / $trendMaximum * 100)) ?>%"></i><small><?= e(date('D', strtotime($day))) ?></small></div><?php endfor; ?></div></article>
        </section>
    <?php elseif (!$showRequests): ?>
        <?php
        $trendMaximum = max(1, ...array_values($userBookingTrend ?: [0]));
        $statusRows = [
            ['Awaiting provider', (int) ($userAnalytics['pending_requests'] ?? 0)],
            ['Active bookings', (int) ($userAnalytics['active_bookings'] ?? 0)],
            ['Completed', (int) ($userAnalytics['completed_bookings'] ?? 0)],
            ['Declined or cancelled', (int) ($userAnalytics['closed_bookings'] ?? 0)],
        ];
        $statusMaximum = max(1, ...array_column($statusRows, 1));
        ?>
        <section class="admin-analytics user-analytics" aria-label="Your booking analytics">
            <div class="section-title"><div><div class="eyebrow">YOUR ACTIVITY</div><h2>Booking insights</h2></div></div>
            <div class="analytics-card-grid">
                <article class="analytics-card"><span>Total bookings</span><strong><?= number_format((int) ($userAnalytics['total_bookings'] ?? 0)) ?></strong><small>All requests made on your account</small></article>
                <article class="analytics-card"><span>Awaiting provider</span><strong><?= number_format((int) ($userAnalytics['pending_requests'] ?? 0)) ?></strong><small><?= $isProvider ? 'Requests waiting for your response' : 'Requests waiting for a provider' ?></small></article>
                <article class="analytics-card"><span>Active bookings</span><strong><?= number_format((int) ($userAnalytics['active_bookings'] ?? 0)) ?></strong><small>Accepted, in progress, or awaiting payment</small></article>
                <article class="analytics-card"><span>Completed</span><strong><?= number_format((int) ($userAnalytics['completed_bookings'] ?? 0)) ?></strong><small>Successfully completed bookings</small></article>
            </div>
            <div class="user-analytics-charts">
                <article class="analytics-chart"><h3>Booking activity · last 7 days</h3><div class="analytics-bars"><?php for ($dayOffset = 6; $dayOffset >= 0; $dayOffset--): $day = date('Y-m-d', strtotime('-' . $dayOffset . ' days')); $count = $userBookingTrend[$day] ?? 0; ?><div class="analytics-bar-column"><span><?= $count ?></span><i style="height: <?= max(4, (int) round($count / $trendMaximum * 100)) ?>%"></i><small><?= e(date('D', strtotime($day))) ?></small></div><?php endfor; ?></div></article>
                <article class="analytics-chart"><h3>Booking status</h3><div class="analytics-status-list"><?php foreach ($statusRows as [$label, $count]): ?><div class="analytics-status-row"><div><span><?= e($label) ?></span><strong><?= number_format($count) ?></strong></div><i><span style="width: <?= (int) round($count / $statusMaximum * 100) ?>%"></span></i></div><?php endforeach; ?></div></article>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($showRequests): ?>
    <div class="dashboard-columns">
        <section class="dashboard-main" id="booking-requests">
            <div class="section-title"><div><div class="eyebrow"><?= $isProvider ? 'YOUR REQUESTS' : ($isAdmin ? 'BOOKING OVERVIEW' : 'YOUR BOOKING TRAIL') ?></div><h2><?= $isProvider ? 'Requests & bookings' : ($isAdmin ? 'Bookings to manage' : 'Requests & progress') ?></h2></div><span class="count-pill"><?= count($bookings) ?> total</span></div>
            <?php if (!$bookings): ?>
                <div class="empty-state compact"><h3><?= $isAdmin ? 'No bookings in your modules.' : 'Your next step starts here.' ?></h3><p><?= $isAdmin ? 'Bookings for modules you can manage will appear here.' : ($isProvider ? 'When a customer requests your service, you’ll find it here.' : 'Explore services and book with a trusted professional.') ?></p>
                    <?php if (!$isAdmin && !$isProvider): ?><a class="button button-primary" href="<?= e(url('Pages/services.php?module=' . ($user['role'] === 'student_parent' ? 'tutoring' : 'home_services'))) ?>">Explore services</a><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="booking-list">
                <?php $shownCheckoutPayments = []; foreach ($bookings as $item):
                    $displayStatus = match ($item['status']) {
                        'requested' => 'Awaiting provider',
                        'paid', 'accepted' => 'Confirmed',
                        default => ucwords(str_replace('_', ' ', $item['status'])),
                    };
                    $checkoutNotYetDispatched = $item['checkout_order_id'] !== null && $item['status'] === 'awaiting_payment';
                ?>
                    <article class="dashboard-booking">
                        <div class="booking-card-top"><span class="booking-category"><?= e($item['service_name']) ?> <i>·</i> <?= $item['module'] === 'tutoring' ? 'Tutoring' : 'Home services' ?></span><span class="status status-<?= e($item['status']) ?>"><?= e($displayStatus) ?></span></div>
                        <h3><?= e($isProvider ? $item['customer_name'] : ($isCustomer ? $item['provider_name'] : $item['customer_name'] . ' · ' . $item['provider_name'])) ?></h3>
                        <p><?= e($item['service_location']) ?><?= $item['module'] === 'tutoring' ? ' · ' . e((string) ($item['teaching_level_name'] ?? 'Level not specified')) . ' · ' . e(ucfirst($item['session_location_type'])) . ' session' : '' ?><?= $item['scheduled_for'] ? ' · ' . e(date('M j, Y · g:i a', strtotime($item['scheduled_for']))) : '' ?></p>
                        <?php if ($item['session_hours'] !== null && $item['service_hourly_rate'] !== null): ?><p class="booking-hourly-rate"><?= number_format((float) $item['session_hours'], 2) ?> hours<?= $item['module'] === 'tutoring' ? ' · ' . (int) $item['student_count'] . ' student(s)' : '' ?> · LertFind price KES <?= number_format((float) $item['service_hourly_rate'], 2) ?> per hour<?= $item['module'] === 'tutoring' ? ' each' : '' ?></p><?php endif; ?>
                        <?php if ($item['module'] === 'tutoring' && $item['session_hours'] !== null && $item['service_hourly_rate'] !== null && $item['quoted_amount'] === null): ?><p class="booking-estimate">Estimated session total: KES <?= number_format((float) $item['session_hours'] * (float) $item['service_hourly_rate'] * (int) $item['student_count'], 2) ?>. Final total and commission are confirmed when the tutor accepts.</p><?php endif; ?>
                        <?php if ($item['details']): ?><p class="booking-detail"><?= e($item['details']) ?></p><?php endif; ?>
                        <?php if (!empty($statusHistory[$item['id']])): ?><details class="status-history"><summary>View request history <span><?= count($statusHistory[$item['id']]) ?> updates</span></summary><ol><?php foreach ($statusHistory[$item['id']] as $entry): ?><li><b><?= e(ucwords(str_replace('_', ' ', $entry['status']))) ?></b><span><?= e($entry['note']) ?></span><small><?= $entry['actor_name'] ? e($entry['actor_name']) . ' · ' : '' ?><?= e(date('M j, Y · g:i a', strtotime($entry['created_at']))) ?></small></li><?php endforeach; ?></ol></details><?php endif; ?>
                        <?php if ($isCustomer): ?>
                            <div class="progress-trail"><span class="trail-step <?= !$checkoutNotYetDispatched && in_array($item['status'], ['requested','accepted','awaiting_payment','paid','on_the_way','in_progress','completed'], true) ? 'done' : '' ?>">Request sent</span><span class="trail-step <?= in_array($item['status'], ['accepted','paid','on_the_way','in_progress','completed'], true) || ($item['status'] === 'awaiting_payment' && $item['checkout_order_id'] === null) ? 'done' : '' ?>">Provider confirmed</span><span class="trail-step <?= in_array($item['status'], ['paid','on_the_way','in_progress','completed'], true) ? 'done' : '' ?>">Paid</span><?php if ($item['module'] === 'home_services'): ?><span class="trail-step <?= in_array($item['status'], ['on_the_way','in_progress','completed'], true) ? 'done' : '' ?>">On the way</span><span class="trail-step <?= in_array($item['status'], ['in_progress','completed'], true) ? 'done' : '' ?>">In progress</span><?php endif; ?><span class="trail-step <?= $item['status'] === 'completed' ? 'done' : '' ?>">Complete</span></div>
                            <?php if ($item['module'] === 'tutoring' && $item['service_hourly_rate'] !== null): ?><p class="booking-hourly-rate">LertFind price: KES <?= number_format((float) $item['service_hourly_rate'], 2) ?> per hour per student</p><?php elseif ($item['module'] === 'home_services' && $item['service_price'] !== null): ?><p class="booking-hourly-rate"><?= $item['slug'] === 'laundry' ? 'Laundry price: KES ' . number_format((float) $item['service_price'], 2) . ' per basket × ' . (int) $item['laundry_baskets'] . ' baskets = KES ' . number_format((float) $item['service_price'] * (int) $item['laundry_baskets'], 2) : 'Fixed price per service: KES ' . number_format((float) $item['service_price'], 2) ?></p><?php endif; ?>
                            <?php if ($item['quoted_amount'] !== null): ?><div class="price-breakdown"><b>Service total · KES <?= number_format((float) $item['quoted_amount'], 2) ?></b><span>Platform commission <strong>KES <?= number_format((float) $item['commission_amount'], 2) ?></strong></span><span>Provider receives <strong>KES <?= number_format((float) $item['provider_amount'], 2) ?></strong></span>
                                <?php if ($item['module'] === 'tutoring' && $item['checkout_order_id'] !== null && $item['status'] === 'awaiting_payment' && $item['checkout_order_status'] === 'awaiting_payment' && empty($shownCheckoutPayments[$item['checkout_order_id']])): ?>
                                    <?php $shownCheckoutPayments[$item['checkout_order_id']] = true; ?>
                                    <form method="post" class="payment-form" action="<?= e(url('Pages/pay_checkout.php')) ?>"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $item['checkout_order_id'] ?>"><button class="button button-pay" type="submit">PAY CHECKOUT <span>KES <?= number_format((float) $item['checkout_total'], 2) ?></span></button><?php if (APP_ENV === 'local' && !paystack_checkout_ready(null, false)): ?><small class="demo-payment-note">Local demo only — no money will be transferred.</small><?php elseif (APP_ENV === 'local'): ?><small>Paystack test checkout</small><?php else: ?><small>Secure checkout through Paystack</small><?php endif; ?></form>
                                <?php elseif ($item['module'] === 'tutoring' && $item['checkout_order_id'] !== null && $item['status'] === 'awaiting_payment' && $item['checkout_order_status'] === 'paid'): ?>
                                    <form method="post" class="payment-form" action="<?= e(url('Pages/pay.php')) ?>"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><button class="button button-pay" type="submit">PAY AGAIN <span>KES <?= number_format((float) $item['quoted_amount'], 2) ?></span></button><small>Required after the refund and provider reassignment.</small></form>
                                <?php elseif ($item['checkout_order_id'] === null && $item['status'] === 'awaiting_payment' && APP_ENV === 'local' && !paystack_checkout_ready(null, false)): ?><form method="post" class="payment-form" action="<?= e(url('Pages/pay.php')) ?>"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><label>Simulated payment method<select name="demo_method" required><option value="">Choose a demo method</option><option value="mpesa">M-Pesa / mobile money (simulated)</option><option value="card">Card (simulated)</option><option value="bank_transfer">Bank transfer (simulated)</option></select></label><small class="demo-payment-note">Demo only: no payment request is sent and no money is transferred. Do not enter real card, PIN, or bank details.</small><button class="button button-pay" type="submit">Record simulated payment <span>KES <?= number_format((float) $item['quoted_amount'], 2) ?></span></button></form>
                                <?php elseif ($item['checkout_order_id'] === null && $item['status'] === 'awaiting_payment' && paystack_checkout_ready(null, false)): ?><form method="post" class="payment-form" action="<?= e(url('Pages/pay.php')) ?>"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><button class="button button-pay" type="submit">PAY NOW <span>KES <?= number_format((float) $item['quoted_amount'], 2) ?></span></button><small><?= APP_ENV === 'local' ? 'Paystack test checkout' : 'Secure checkout through Paystack' ?></small></form>
                                <?php elseif ($item['checkout_order_id'] === null && $item['status'] === 'awaiting_payment'): ?><small>Online checkout is being prepared for this provider. Contact support if it remains unavailable.</small>
                                <?php elseif ($item['paid_gateway'] !== 'demo' && in_array($item['status'], ['requested','paid','completed','on_the_way','in_progress'], true) && $item['paid_gateway']): ?><small>Payment confirmed securely through Paystack. <?= $item['status'] === 'requested' ? 'Waiting for your selected provider to respond.' : '' ?></small><?php endif; ?>
                            </div><?php endif; ?>
                            <?php if ($item['module'] === 'tutoring' && in_array($item['status'], ['paid', 'completed'], true)): ?><p class="booking-hourly-rate">Lesson confirmation: tutor <?= $item['provider_completed_at'] !== null ? 'confirmed' : 'pending' ?> · student <?= $item['customer_completed_at'] !== null ? 'confirmed' : 'pending' ?></p><?php if ($item['customer_completed_at'] === null && ($item['status'] === 'completed' || $item['class_started_at'] !== null)): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_complete"><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><button class="button button-outline button-small" type="submit">Confirm lesson complete</button></form><?php elseif ($item['status'] === 'paid' && $item['class_started_at'] === null): ?><small>Confirm the lesson after your tutor starts it with your class code.</small><?php endif; ?><?php endif; ?>
                            <?php if ($item['module'] === 'home_services' && in_array($item['status'], ['in_progress', 'completed'], true)): ?>
                                <p class="booking-hourly-rate">Completion confirmation: provider <?= $item['provider_completed_at'] !== null ? 'confirmed' : 'pending' ?> · customer <?= $item['customer_completed_at'] !== null ? 'confirmed' : 'pending' ?></p>
                                <?php if ($item['provider_completed_at'] !== null && $item['customer_completed_at'] === null): ?>
                                    <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_complete"><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><button class="button button-outline button-small" type="submit">Confirm service complete</button></form>
                                <?php elseif ($item['provider_completed_at'] === null): ?><small>Your provider must mark the service complete before you can confirm it.</small><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($item['module'] === 'tutoring' && $item['status'] === 'paid' && $item['class_started_at'] === null): ?>
                                <?php if ($item['class_otp'] !== null && $item['class_otp_expires_at'] !== null && strtotime($item['class_otp_expires_at']) > time()): ?>
                                    <div class="class-code-panel"><b>Your one-time class code: <?= e((string) $item['class_otp']) ?></b><small>Give this code to your tutor at the start of the lesson. It expires <?= e(date('M j, Y · g:i a', strtotime($item['class_otp_expires_at']))) ?>.</small></div>
                                <?php else: ?>
                                    <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="regenerate_class_code"><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><button class="button button-outline button-small" type="submit">Generate class code</button></form>
                                <?php endif; ?>
                            <?php elseif ($item['module'] === 'tutoring' && $item['class_started_at'] !== null): ?><p class="booking-hourly-rate">Class started <?= e(date('M j, Y · g:i a', strtotime($item['class_started_at']))) ?>.</p><?php endif; ?>
                            <?php if ($item['module'] === 'home_services' && $item['status'] === 'on_the_way' && $item['service_started_at'] === null): ?>
                                <?php if ($item['service_start_otp'] !== null && $item['service_start_otp_expires_at'] !== null && strtotime($item['service_start_otp_expires_at']) > time()): ?>
                                    <div class="class-code-panel"><b>Your service start code: <?= e((string) $item['service_start_otp']) ?></b><small>Share this code with your provider through the booking conversation or when they arrive. It expires <?= e(date('M j, Y · g:i a', strtotime($item['service_start_otp_expires_at']))) ?>. We also emailed it to you when configured.</small></div>
                                <?php else: ?>
                                    <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="regenerate_service_otp"><button class="button button-outline button-small" type="submit">Generate a new service code</button></form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($item['module'] === 'tutoring' && in_array($item['status'], ['paid','completed'], true)): ?>
                                <?php if ($item['refund_status'] !== null): ?><small>Refund request status: <?= e(str_replace('_', ' ', $item['refund_status'])) ?>.
                                <?php elseif ($item['withdrawal_status'] === null): ?><form method="post" class="review-form"><?= csrf_field() ?><input type="hidden" name="action" value="request_refund"><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><label for="refund-reason-<?= (int) $item['id'] ?>">Need a refund before provider payout? Tell us why</label><textarea id="refund-reason-<?= (int) $item['id'] ?>" name="refund_reason" rows="2" maxlength="2000" required placeholder="Describe the issue for admin review"></textarea><button class="button button-outline button-small" type="submit">Request refund review</button></form><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($item['status'] === 'completed' && !$item['review_id']
                                && ($item['module'] !== 'home_services' || ($item['provider_completed_at'] !== null && $item['customer_completed_at'] !== null))): ?><form method="post" class="review-form"><?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><fieldset class="rating-input"><legend><?= $item['module'] === 'tutoring' ? 'Rate your tutor' : 'Rate your experience' ?></legend><div class="star-rating"><?php for ($star = 5; $star >= 1; $star--): ?><input id="rating-<?= (int) $item['id'] ?>-<?= $star ?>" type="radio" name="rating" value="<?= $star ?>" required><label for="rating-<?= (int) $item['id'] ?>-<?= $star ?>" title="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>"><span class="sr-only"><?= $star ?> star<?= $star === 1 ? '' : 's' ?></span>★</label><?php endfor; ?></div></fieldset><textarea name="review" rows="2" maxlength="2000" placeholder="Share a helpful note (optional)"></textarea><button class="button button-outline button-small" type="submit"><?= $item['module'] === 'tutoring' ? 'Submit tutor rating' : 'Leave review' ?></button></form><?php elseif ($item['review_id']): ?><p class="reviewed-note">Your rating: <span class="rating-stars" role="img" aria-label="Your rating: <?= (int) $item['customer_rating'] ?> out of 5 stars"><?php for ($star = 1; $star <= 5; $star++): ?><span class="<?= $star <= (int) $item['customer_rating'] ? 'filled' : '' ?>">★</span><?php endfor; ?></span></p><?php endif; ?>
                            <?php if (in_array($item['status'], ['requested','paid','on_the_way','in_progress','completed'], true) && $item['paid_gateway']): ?><details class="conversation-popover" data-conversation-popover><summary class="button button-outline button-small booking-action-button" aria-expanded="false">Open conversation</summary><iframe title="Booking #<?= (int) $item['id'] ?> conversation" loading="lazy" referrerpolicy="no-referrer" src="<?= e(url('Pages/messages.php?booking=' . (int) $item['id'] . '&inline=1')) ?>"></iframe></details><?php endif; ?>
                            <a class="button button-outline button-small booking-action-button" href="<?= e(url('Pages/complaint.php?booking=' . (int) $item['id'])) ?>">Report an issue</a>
                        <?php endif; ?>
                        <?php if ($isProvider && $item['quoted_amount'] !== null): ?>
                            <div class="price-breakdown provider-price-breakdown">
                                <b><?= $item['module'] === 'home_services' || $item['checkout_order_id'] !== null || $item['module'] === 'tutoring' ? 'Service total' : 'Customer quote' ?> · KES <?= number_format((float) $item['quoted_amount'], 2) ?></b>
                                <?php if ($item['module'] === 'home_services' && $item['service_price'] !== null): ?><span><?= $item['slug'] === 'laundry' ? 'KES ' . number_format((float) $item['service_price'], 2) . ' per basket × ' . (int) $item['laundry_baskets'] . ' baskets' : 'Fixed admin-set price per service: KES ' . number_format((float) $item['service_price'], 2) ?></span><?php elseif ($item['session_hours'] !== null): ?><span><?= number_format((float) $item['session_hours'], 2) ?> hours<?= $item['module'] === 'tutoring' ? ' × ' . (int) $item['student_count'] . ' student(s)' : '' ?> × KES <?= number_format((float) $item['service_hourly_rate'], 2) ?> per hour</span><?php endif; ?>
                                <span>Platform commission <strong>KES <?= number_format((float) $item['commission_amount'], 2) ?></strong></span>
                                <span>Your share <strong>KES <?= number_format((float) $item['provider_amount'], 2) ?></strong></span>
                                <?php if ($item['status'] === 'awaiting_payment'): ?><small>Waiting for the student to pay.</small>
                                <?php elseif ($item['paid_gateway'] !== 'demo' && in_array($item['status'], ['requested','paid','on_the_way','in_progress','completed'], true)): ?><small>Payment received through the configured gateway.</small><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($isProvider && $item['status'] === 'requested'): ?>
                            <div class="inline-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="accept"><button class="button button-primary button-small" type="submit">Accept request</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="decline"><button class="button button-outline button-small" type="submit">Decline</button></form></div>
                        <?php endif; ?>
                        <?php if ($isProvider && $item['module'] === 'home_services' && $item['status'] === 'accepted' && $item['quoted_amount'] === null): ?>
                            <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="accept"><button class="button button-primary button-small" type="submit">Confirm admin-set price</button></form>
                        <?php endif; ?>
                        <?php if ($isProvider && $item['module'] === 'home_services' && $item['status'] === 'paid'): ?>
                            <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="on_the_way"><button class="button button-primary button-small" type="submit">Mark on the way</button></form>
                        <?php elseif ($isProvider && $item['module'] === 'home_services' && $item['status'] === 'on_the_way'): ?>
                            <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="start_service"><label for="service-start-otp-<?= (int) $item['id'] ?>">Enter the customer's service start code</label><input id="service-start-otp-<?= (int) $item['id'] ?>" name="service_otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="6-digit code"><button class="button button-primary button-small" type="submit">Verify &amp; start service</button></form>
                        <?php elseif ($isProvider && $item['module'] === 'home_services' && $item['status'] === 'in_progress' && $item['provider_completed_at'] === null): ?>
                            <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="complete"><button class="button button-primary button-small" type="submit">Mark service complete</button></form>
                        <?php elseif ($isProvider && $item['module'] === 'home_services' && $item['status'] === 'in_progress' && $item['customer_completed_at'] === null): ?>
                            <small>You marked the service complete. Waiting for the customer to confirm before it can be rated.</small>
                        <?php endif; ?>
                        <?php if ($isProvider && in_array($item['status'], ['requested','paid','on_the_way','in_progress','completed'], true) && $item['paid_gateway']): ?><details class="conversation-popover" data-conversation-popover><summary class="button button-outline button-small booking-action-button" aria-expanded="false">Open conversation</summary><iframe title="Booking #<?= (int) $item['id'] ?> conversation" loading="lazy" referrerpolicy="no-referrer" src="<?= e(url('Pages/messages.php?booking=' . (int) $item['id'] . '&inline=1')) ?>"></iframe></details><?php endif; ?>
                        <?php if ($isProvider && $item['module'] === 'tutoring' && in_array($item['status'], ['paid', 'completed'], true)): ?>
                            <p class="booking-hourly-rate">Lesson confirmation: tutor <?= $item['provider_completed_at'] !== null ? 'confirmed' : 'pending' ?> · student <?= $item['customer_completed_at'] !== null ? 'confirmed' : 'pending' ?></p>
                            <?php if ($item['status'] === 'paid' && $item['class_started_at'] === null): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="start_class"><label for="class-otp-<?= (int) $item['id'] ?>">Enter student's class code</label><input id="class-otp-<?= (int) $item['id'] ?>" name="class_otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="6-digit code"><button class="button button-primary button-small" type="submit">Verify &amp; start class</button></form>
                            <?php elseif ($item['class_started_at'] !== null): ?><p class="booking-hourly-rate">Class started <?= e(date('M j, Y · g:i a', strtotime($item['class_started_at']))) ?>.</p><?php endif; ?>
                            <?php if ($item['provider_completed_at'] === null && ($item['status'] === 'completed' || $item['class_started_at'] !== null)): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="complete"><button class="button button-primary button-small" type="submit">Mark lesson complete</button></form><?php elseif ($item['status'] === 'paid' && $item['class_started_at'] === null): ?><small>Enter the student's one-time code to start the lesson.</small><?php endif; ?>
                            <?php endif; ?>
                        <?php if ($isProvider && in_array($item['module'], ['tutoring', 'home_services'], true)
                            && $item['status'] === 'completed' && $item['provider_completed_at'] !== null && $item['customer_completed_at'] !== null): ?>
                            <?php if ($item['paid_gateway'] === 'demo' && APP_ENV !== 'local'): ?><small>Demo withdrawals are unavailable in this environment.</small>
                            <?php elseif ($item['paid_gateway'] !== 'demo' && ($item['paid_gateway'] !== 'paystack' || $item['paid_payout_mode'] !== 'platform_held')): ?><small>This payment is not held by LertFind and is not eligible for a withdrawal request.</small>
                            <?php else: ?>
                            <?php if ($item['withdrawal_status'] === 'pending'): ?><small><?= $item['paid_gateway'] === 'demo' ? 'Demo withdrawal request submitted. Waiting for admin review.' : 'Withdrawal request submitted. Waiting for admin review.' ?></small>
                            <?php elseif ($item['withdrawal_status'] === 'approved'): ?><form method="post" class="review-form"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="submit_payout"><label for="payout-method-<?= (int) $item['id'] ?>"><?= $item['paid_gateway'] === 'demo' ? 'Demo payout method' : 'Payout method' ?></label><select id="payout-method-<?= (int) $item['id'] ?>" name="payout_method" required><option value="">Choose payout method</option><option value="mobile_money">Mobile money</option><option value="bank">Bank account</option></select><label for="payout-details-<?= (int) $item['id'] ?>"><?= $item['paid_gateway'] === 'demo' ? 'Test mobile number or bank details' : 'Mobile number or bank details' ?></label><input id="payout-details-<?= (int) $item['id'] ?>" name="payout_details" maxlength="500" required autocomplete="off" placeholder="<?= $item['paid_gateway'] === 'demo' ? 'Use test-only details' : 'Enter your payout destination' ?>"><button class="button button-primary button-small" type="submit"><?= $item['paid_gateway'] === 'demo' ? 'Submit demo payout details' : 'Submit payout details' ?></button></form>
                            <?php elseif ($item['withdrawal_status'] === 'payout_requested'): ?><small><?= $item['paid_gateway'] === 'demo' ? 'Demo payout details submitted for admin status review.' : 'Payout details submitted securely. Admin is arranging your withdrawal.' ?></small>
                            <?php elseif ($item['withdrawal_status'] === 'released'): ?><small><?= $item['paid_gateway'] === 'demo' ? 'Demo payout status recorded.' : 'Admin recorded your payout as released.' ?></small>
                            <?php elseif ($item['withdrawal_status'] === 'rejected'): ?><small>Your request was declined. You may submit a new request for review.</small><form method="post" class="inline-actions" data-confirm="<?= $item['paid_gateway'] === 'demo' ? 'Request a demo withdrawal? No money will be transferred.' : 'Request withdrawal of KES ' . e(number_format((float) $item['provider_amount'], 2)) . ' again?' ?>"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="request_withdrawal"><button class="button button-primary button-small" type="submit"><?= $item['paid_gateway'] === 'demo' ? 'Request demo withdrawal again' : 'Request withdrawal again' ?></button></form>
                            <?php elseif ($item['withdrawal_status'] === null && $item['refund_status'] === null): ?><form method="post" class="inline-actions" data-confirm="<?= $item['paid_gateway'] === 'demo' ? 'Request a demo withdrawal? No money will be transferred.' : 'Request withdrawal of KES ' . e(number_format((float) $item['provider_amount'], 2)) . '? An admin will review and record the payout after paying you.' ?>"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="request_withdrawal"><button class="button button-primary button-small" type="submit"><?= $item['paid_gateway'] === 'demo' ? 'Request demo withdrawal' : 'Request withdrawal' ?></button></form><?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($isAdmin && $item['status'] === 'declined' && admin_has_permission($item['module'], 'manage_bookings')): ?>
                            <?php
                            $matchQuery = $pdo->prepare("SELECT u.id, u.full_name FROM provider_services ps JOIN users u ON u.id = ps.provider_id JOIN provider_profiles pp ON pp.user_id = u.id WHERE ps.category_id = ? AND u.id != ? AND u.is_active = 1 AND u.email_verified_at IS NOT NULL AND pp.is_available = 1 AND pp.approval_status = 'approved' AND NOT EXISTS (SELECT 1 FROM bookings busy WHERE busy.provider_id = u.id AND busy.status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress')) ORDER BY pp.average_rating DESC, u.full_name");
                            $matchQuery->execute([$item['category_id'], $item['provider_id']]);
                            $matches = $matchQuery->fetchAll();
                            ?>
                            <?php if ($item['checkout_order_id'] !== null && $item['paid_gateway'] === 'paystack' && $item['latest_payment_status'] !== 'refunded'): ?>
                                <p class="form-footnote">Refund this paid booking in Payment Operations before reassigning it. The customer will pay again after the refund is recorded.</p>
                            <?php elseif ($matches): ?><form method="post" class="reassign-form"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="reassign"><label class="sr-only" for="provider-<?= (int) $item['id'] ?>">Replacement provider</label><select id="provider-<?= (int) $item['id'] ?>" name="provider_id" required><option value="">Choose another provider</option><?php foreach ($matches as $match): ?><option value="<?= (int) $match['id'] ?>"><?= e($match['full_name']) ?></option><?php endforeach; ?></select><button class="button button-primary button-small" type="submit"><?= $item['checkout_order_id'] !== null ? 'Assign and request payment' : 'Send new request' ?></button></form><?php else: ?><p class="form-footnote">No other verified providers are available for this service yet.</p><?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <aside class="dashboard-side">
            <details class="side-panel notification-panel" id="notifications">
                <summary class="notification-toggle"><span><span class="eyebrow">KEEP IN THE LOOP</span><strong>Notifications</strong><small><?= number_format(count($notifications)) ?> recent updates</small></span><i class="notification-arrow" aria-hidden="true"></i></summary>
                <?php if (!$notifications): ?><p class="muted-copy">Updates about your bookings will show up here.</p><?php else: ?><div class="notification-list"><?php foreach ($notifications as $notification): ?><article class="notification-item <?= $notification['read_at'] ? '' : 'unread' ?>"><span class="notification-dot"></span><div><h3><?= e($notification['subject']) ?></h3><p><?= e($notification['body']) ?></p><small><?= e(date('M j · g:i a', strtotime($notification['created_at']))) ?></small></div></article><?php endforeach; ?></div><?php endif; ?>
            </section>
            <section class="side-panel help-panel"><h3>Need a hand?</h3><p>Our team is here to help you get the most out of LertFind. Send a support request any time.</p><a class="button button-outline button-small" href="<?= e(url('Pages/complaint.php')) ?>">Contact support</a></section>
        </aside>
    </div>
    <?php else: ?>
        <div class="dashboard-home-grid">
            <section class="dashboard-shortcuts">
                <div class="section-title"><div><div class="eyebrow">YOUR WORKSPACE</div><h2>Quick actions</h2></div></div>
                <div class="dashboard-quick-links">
                    <?php if ($isAdmin): ?>
                        <?php if ($user['role'] === 'super_admin' || admin_has_permission('tutoring', 'manage_bookings') || admin_has_permission('home_services', 'manage_bookings')): ?><a class="dashboard-quick-link" href="<?= e(url('Pages/dashboard.php?view=requests')) ?>"><span class="quick-link-icon">01</span><span><strong>Handle booking requests</strong><small>Review requests and reassign declined bookings.</small></span><b aria-hidden="true">→</b></a><?php endif; ?>
                        <?php if ($user['role'] === 'super_admin' || admin_has_permission('tutoring', 'manage_providers') || admin_has_permission('home_services', 'manage_providers')): ?><a class="dashboard-quick-link" href="<?= e(url('Admin/providers.php')) ?>"><span class="quick-link-icon">02</span><span><strong>Manage providers</strong><small>Review provider profiles and availability.</small></span><b aria-hidden="true">→</b></a><?php endif; ?>
                        <?php if ($user['role'] === 'super_admin' || admin_has_permission('tutoring', 'view_reports') || admin_has_permission('home_services', 'view_reports')): ?><a class="dashboard-quick-link" href="<?= e(url('Admin/reports.php')) ?>"><span class="quick-link-icon">03</span><span><strong>Open reports</strong><small>See detailed platform performance.</small></span><b aria-hidden="true">→</b></a><?php endif; ?>
                    <?php elseif ($isProvider): ?>
                        <a class="dashboard-quick-link" href="<?= e(url('Pages/dashboard.php?view=requests')) ?>"><span class="quick-link-icon">01</span><span><strong>Review requests</strong><small>Respond to new bookings and follow active work.</small></span><b aria-hidden="true">→</b></a>
                        <a class="dashboard-quick-link" href="<?= e(url('Pages/profile.php')) ?>"><span class="quick-link-icon">02</span><span><strong>Update your profile</strong><small>Keep your services and availability current.</small></span><b aria-hidden="true">→</b></a>
                    <?php else: ?>
                        <?php if ($user['role'] === 'student_parent'): ?><a class="dashboard-quick-link" href="<?= e(url('Pages/services.php?module=tutoring')) ?>"><span class="quick-link-icon">01</span><span><strong>Find a tutor</strong><small>Browse subjects and tutors by learner level.</small></span><b aria-hidden="true">→</b></a><?php endif; ?>
                        <a class="dashboard-quick-link" href="<?= e(url('Pages/services.php?module=home_services')) ?>"><span class="quick-link-icon">02</span><span><strong>Browse home services</strong><small>Find a trusted provider for your home.</small></span><b aria-hidden="true">→</b></a>
                        <a class="dashboard-quick-link" href="<?= e(url('Pages/dashboard.php?view=requests')) ?>"><span class="quick-link-icon">03</span><span><strong>Track your requests</strong><small>See booking updates and next steps.</small></span><b aria-hidden="true">→</b></a>
                    <?php endif; ?>
                </div>
            </section>
            <aside class="dashboard-side">
                <details class="side-panel notification-panel" id="notifications">
                    <summary class="notification-toggle"><span><span class="eyebrow">KEEP IN THE LOOP</span><strong>Notifications</strong><small><?= number_format(count($notifications)) ?> recent updates</small></span><i class="notification-arrow" aria-hidden="true"></i></summary>
                    <?php if (!$notifications): ?><p class="muted-copy">Updates about your bookings will show up here.</p><?php else: ?><div class="notification-list"><?php foreach ($notifications as $notification): ?><article class="notification-item <?= $notification['read_at'] ? '' : 'unread' ?>"><span class="notification-dot"></span><div><h3><?= e($notification['subject']) ?></h3><p><?= e($notification['body']) ?></p><small><?= e(date('M j · g:i a', strtotime($notification['created_at']))) ?></small></div></article><?php endforeach; ?></div><?php endif; ?>
                </details>
                <section class="side-panel help-panel"><h3>Need a hand?</h3><p>Our team is here to help you get the most out of LertFind. Send a support request any time.</p><a class="button button-outline button-small" href="<?= e(url('Pages/complaint.php')) ?>">Contact support</a></section>
            </aside>
        </div>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
