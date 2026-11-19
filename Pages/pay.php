<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/paystack.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('Pages/dashboard.php'));
    exit;
}
reject_invalid_csrf();

$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
if (!$bookingId || !is_role('student_parent', 'customer')) {
    page_error('This payment request is not available.', 403);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $query = $pdo->prepare(
        'SELECT b.id, b.customer_id, b.provider_id, b.checkout_order_id, b.status, b.quoted_amount, b.commission_amount, b.provider_amount,
                c.module, u.email, pp.approval_status
         FROM bookings b
         JOIN service_categories c ON c.id = b.category_id
         JOIN users u ON u.id = b.customer_id
         JOIN provider_profiles pp ON pp.user_id = b.provider_id
         WHERE b.id = ? AND b.customer_id = ?
         FOR UPDATE'
    );
    $query->execute([$bookingId, current_user()['id']]);
    $booking = $query->fetch();
    if (!$booking || $booking['status'] !== 'awaiting_payment' || $booking['quoted_amount'] === null
        || ($booking['module'] === 'home_services' && $booking['checkout_order_id'] !== null)
        || $booking['approval_status'] !== 'approved') {
        $pdo->rollBack();
        page_error('This booking does not have a payable quote.', 409);
    }

    $amountCents = (int) round((float) $booking['quoted_amount'] * 100);
    $commissionCents = (int) round((float) $booking['commission_amount'] * 100);
    $providerCents = (int) round((float) $booking['provider_amount'] * 100);
    if ($amountCents <= 0 || $commissionCents < 0 || $providerCents < 0
        || $commissionCents + $providerCents !== $amountCents) {
        $pdo->rollBack();
        page_error('This booking quote is not valid for payment.');
    }

    if (APP_ENV === 'local' && !paystack_checkout_ready(null, false)) {
        $demoMethods = [
            'mpesa' => 'M-Pesa/mobile money',
            'card' => 'card',
            'bank_transfer' => 'bank transfer',
        ];
        $demoMethod = (string) ($_POST['demo_method'] ?? '');
        if (!isset($demoMethods[$demoMethod])) {
            $pdo->rollBack();
            page_error('Choose a simulated payment method.', 400);
        }
        $activePaystack = $pdo->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE booking_id = ? AND gateway = 'paystack' AND status IN ('pending','initiated')"
        );
        $activePaystack->execute([$bookingId]);
        $existingPaid = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'paid'");
        $existingPaid->execute([$bookingId]);
        if ((int) $activePaystack->fetchColumn() > 0 || (int) $existingPaid->fetchColumn() > 0) {
            $pdo->rollBack();
            flash('info', 'A payment attempt already exists for this booking. Check the booking status before trying again.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }

        $reference = 'TLK-DEMO-' . date('ymd') . '-' . bin2hex(random_bytes(12));
        $pdo->prepare(
            "INSERT INTO payments (booking_id, gateway, gateway_reference, reference, amount, commission_amount, provider_amount, status, paid_at)
             VALUES (?, 'demo', ?, ?, ?, ?, ?, 'paid', NOW())"
        )->execute([
            $bookingId, 'DEMO-' . strtoupper($demoMethod), $reference,
            $amountCents / 100, $commissionCents / 100, $providerCents / 100,
        ]);
        $nextStatus = $booking['checkout_order_id'] === null ? 'paid' : 'requested';
        $updated = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ? AND customer_id = ? AND status = \'awaiting_payment\'');
        $updated->execute([$nextStatus, $bookingId, current_user()['id']]);
        if ($updated->rowCount() !== 1) {
            $pdo->rollBack();
            flash('error', 'This booking is no longer waiting for payment.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }
        $pdo->prepare(
            'INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, ?, ?)'
        )->execute([
            $bookingId, current_user()['id'], $nextStatus,
            $booking['checkout_order_id'] === null
                ? 'Local simulated ' . $demoMethods[$demoMethod] . ' payment recorded. No money was transferred.'
                : 'Local simulated ' . $demoMethods[$demoMethod] . ' payment recorded. Provider request sent; no money was transferred.',
        ]);
        if ($booking['checkout_order_id'] === null && $booking['module'] === 'tutoring') {
            $classOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare(
                'UPDATE bookings SET class_otp = ?, class_otp_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                 WHERE id = ? AND class_started_at IS NULL AND class_otp IS NULL'
            )->execute([$classOtp, $bookingId]);
        }
        $customerNotice = 'A simulated ' . $demoMethods[$demoMethod] . ' payment of KES ' . number_format($amountCents / 100, 2)
            . ' was recorded for booking #' . $bookingId . '. No money was transferred.';
        $providerNotice = $booking['checkout_order_id'] === null
            ? 'A simulated ' . $demoMethods[$demoMethod] . ' payment was recorded for booking #' . $bookingId
                . '. Provider share: KES ' . number_format($providerCents / 100, 2) . '. No money was transferred.'
            : 'A simulated ' . $demoMethods[$demoMethod] . ' payment was recorded for booking #' . $bookingId
                . '. Review the request in your dashboard. No money was transferred.';
        $pdo->prepare(
            "INSERT INTO notifications (user_id, booking_id, channel, subject, body)
             VALUES (?, ?, 'in_app', 'Demo payment recorded', ?), (?, ?, 'in_app', 'Demo booking payment', ?)"
        )->execute([
            current_user()['id'], $bookingId, $customerNotice,
            $booking['provider_id'], $bookingId, $providerNotice,
        ]);
        $pdo->commit();
        flash('info', 'Simulated ' . $demoMethods[$demoMethod] . ' payment recorded. No money was transferred.');
        header('Location: ' . url('Pages/dashboard.php'));
        exit;
    }

    if ($amountCents < 300 || $providerCents <= 0) {
        $pdo->rollBack();
        page_error('This quote cannot be processed through the configured Paystack checkout.');
    }
    $recent = $pdo->prepare(
        "SELECT id, reference FROM payments
         WHERE booking_id = ? AND gateway = 'paystack' AND status IN ('pending','initiated')
         ORDER BY id DESC LIMIT 1"
    );
    $recent->execute([$bookingId]);
    $previousPayment = $recent->fetch();
    if ($previousPayment) {
        $pdo->rollBack();
        try {
            $verification = paystack_verify_transaction((string) $previousPayment['reference']);
            paystack_settle_reference($pdo, (string) $previousPayment['reference'], $verification);
            $statusQuery = $pdo->prepare('SELECT status FROM payments WHERE id = ?');
            $statusQuery->execute([$previousPayment['id']]);
            $previousStatus = $statusQuery->fetchColumn();
            if ($previousStatus === 'paid') {
                flash('success', 'Your payment has already been confirmed.');
                header('Location: ' . url('Pages/dashboard.php'));
                exit;
            }
            if ($previousStatus !== 'failed') {
                flash('info', 'A Paystack checkout is still in progress. Complete it before starting another payment.');
                header('Location: ' . url('Pages/dashboard.php'));
                exit;
            }
        } catch (RuntimeException $exception) {
            flash('info', 'We are checking your previous Paystack attempt before allowing another payment. Please try again shortly.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }

        $pdo->beginTransaction();
        $lockBooking = $pdo->prepare('SELECT status FROM bookings WHERE id = ? AND customer_id = ? FOR UPDATE');
        $lockBooking->execute([$bookingId, current_user()['id']]);
        if ($lockBooking->fetchColumn() !== 'awaiting_payment') {
            $pdo->rollBack();
            flash('success', 'Your payment has already been confirmed.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }
        $stillActive = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND gateway = 'paystack' AND status IN ('pending','initiated')");
        $stillActive->execute([$bookingId]);
        if ((int) $stillActive->fetchColumn() > 0) {
            $pdo->rollBack();
            flash('info', 'Your previous Paystack checkout is still being confirmed. Please try again shortly.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }
    } else {
        $pdo->commit();
        $pdo->beginTransaction();
        $lockBooking = $pdo->prepare('SELECT status FROM bookings WHERE id = ? AND customer_id = ? FOR UPDATE');
        $lockBooking->execute([$bookingId, current_user()['id']]);
        if ($lockBooking->fetchColumn() !== 'awaiting_payment') {
            $pdo->rollBack();
            flash('error', 'This booking is no longer waiting for payment.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }
        $stillActive = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND gateway = 'paystack' AND status IN ('pending','initiated')");
        $stillActive->execute([$bookingId]);
        if ((int) $stillActive->fetchColumn() > 0) {
            $pdo->rollBack();
            flash('info', 'A Paystack checkout is already in progress. Complete it before starting another payment.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }
    }

    $reference = 'TLK-' . date('ymd') . '-' . bin2hex(random_bytes(12));
    $insert = $pdo->prepare(
        "INSERT INTO payments (booking_id, gateway, reference, amount, commission_amount, provider_amount, status, payout_mode)
         VALUES (?, 'paystack', ?, ?, ?, ?, 'pending', ?)"
    );
    $insert->execute([
        $bookingId,
        $reference,
        $amountCents / 100,
        $commissionCents / 100,
        $providerCents / 100,
        'platform_held',
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    if ($booking['checkout_order_id'] !== null) {
        $pdo->prepare('UPDATE checkout_orders SET payment_reference = ?, checkout_url = NULL WHERE id = ?')
            ->execute([$reference, $booking['checkout_order_id']]);
    }
    $callback = paystack_app_url() . '/Pages/paystack_callback.php';
    $pdo->commit();
    $paymentPayload = [
        'email' => $booking['email'],
        'amount' => $amountCents,
        'currency' => 'KES',
        'reference' => $reference,
        'callback_url' => $callback,
        'channels' => ['card', 'mobile_money'],
        'metadata' => [
            'booking_id' => (int) $bookingId,
            'checkout_order_id' => $booking['checkout_order_id'] === null ? null : (int) $booking['checkout_order_id'],
            'payment_id' => $paymentId,
            'platform' => 'LertFind Kenya',
        ],
    ];
    $response = paystack_request('POST', 'transaction/initialize', $paymentPayload);
    $checkoutUrl = $response['data']['authorization_url'] ?? '';
    if (!is_string($checkoutUrl) || !str_starts_with($checkoutUrl, 'https://checkout.paystack.com/')) {
        throw new RuntimeException('Paystack returned an invalid checkout address.');
    }
    $update = $pdo->prepare("UPDATE payments SET status = 'initiated', gateway_reference = ? WHERE id = ? AND status = 'pending'");
    $update->execute([$reference, $paymentId]);
    header('Location: ' . $checkoutUrl, true, 303);
    exit;
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($paymentId)) {
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ? AND status = 'pending'")->execute([$paymentId]);
    }
    flash('error', $exception->getMessage());
    header('Location: ' . url('Pages/dashboard.php'));
    exit;
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    page_error('We could not start this payment. Please try again.');
}
