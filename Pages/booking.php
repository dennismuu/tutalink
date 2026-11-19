<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$requestData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$serviceId = filter_var($requestData['service'] ?? $_SESSION['pending_service_id'] ?? null, FILTER_VALIDATE_INT);
$providerId = filter_var($requestData['provider'] ?? $_SESSION['pending_provider_id'] ?? null, FILTER_VALIDATE_INT);
$teachingLevelId = filter_var($requestData['level'] ?? ($_SESSION['pending_booking_details']['teaching_level_id'] ?? null), FILTER_VALIDATE_INT);
if (!$serviceId || !$providerId) page_error('Your selected service or provider could not be found. Please choose again.', 404);

if ($_SERVER['REQUEST_METHOD'] === 'POST') reject_invalid_csrf();
if (!current_user()) {
    $_SESSION['pending_service_id'] = (int) $serviceId;
    $_SESSION['pending_provider_id'] = (int) $providerId;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['pending_booking_details'] = [
            'details' => trim((string) ($_POST['details'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'location_type' => trim((string) ($_POST['location_type'] ?? 'other')),
            'teaching_level_id' => trim((string) ($_POST['teaching_level_id'] ?? '')),
            'scheduled_for' => trim((string) ($_POST['scheduled_for'] ?? '')),
            'session_hours' => trim((string) ($_POST['session_hours'] ?? '')),
            'student_count' => trim((string) ($_POST['student_count'] ?? '1')),
            'laundry_baskets' => trim((string) ($_POST['laundry_baskets'] ?? '1')),
        ];
    }
    flash('info', 'Your selection is saved. Log in or create an account to continue your booking.');
    header('Location: ' . url('Pages/login.php'));
    exit;
}

$errors = [];
$defaults = $_SESSION['pending_booking_details'] ?? ['details' => '', 'location' => '', 'location_type' => 'other', 'teaching_level_id' => (string) ($teachingLevelId ?: ''), 'scheduled_for' => '', 'session_hours' => '', 'student_count' => '1', 'laundry_baskets' => '1'];
unset($_SESSION['pending_booking_details']);
try {
    $pdo = db();
    $selection = $pdo->prepare(
        "SELECT c.id, c.name, c.module, c.slug, c.hourly_rate AS service_hourly_rate,
                c.service_price, u.id AS provider_id,
                u.full_name AS provider_name, u.email AS provider_email, p.location AS provider_location
         FROM service_categories c
         JOIN provider_services ps ON ps.category_id = c.id
         JOIN provider_profiles p ON p.user_id = ps.provider_id AND p.is_available = 1 AND p.approval_status = 'approved'
         JOIN users u ON u.id = p.user_id AND u.is_active = 1 AND u.email_verified_at IS NOT NULL
         WHERE c.id = ? AND u.id = ? AND c.is_active = 1 AND c.is_coming_soon = 0
           AND NOT EXISTS (SELECT 1 FROM bookings busy WHERE busy.provider_id = u.id
               AND busy.status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress'))
           AND (c.module <> 'tutoring' OR EXISTS (
               SELECT 1 FROM provider_teaching_levels ptl WHERE ptl.provider_id = u.id AND ptl.level_id = ?
           ))"
    );
    $selection->execute([$serviceId, $providerId, $teachingLevelId ?: 0]);
    $booking = $selection->fetch();
    if (!$booking) page_error('That provider is no longer available for the selected service.', 404);
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$allowedRole = $booking['module'] === 'tutoring' ? 'student_parent' : 'customer';
if (!is_role($allowedRole)) {
    page_error($booking['module'] === 'tutoring'
        ? 'A Student / Parent account is required to request tutoring.'
        : 'A Home services customer account is required to book home services.', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $details = trim((string) ($_POST['details'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $scheduled = trim((string) ($_POST['scheduled_for'] ?? ''));
    $sessionHours = null;
    $studentCount = 1;
    $laundryBaskets = 1;
    if ($location === '' || mb_strlen($location) > 180) $errors[] = 'Enter the service location.';
    if ($booking['module'] === 'home_services' && $details === '') {
        $errors[] = 'Describe the work you need so the provider can understand the request and prepare for the service.';
    }
    if (mb_strlen($details) > 5000) $errors[] = 'Please keep booking notes under 5,000 characters.';
    if ($booking['module'] === 'tutoring') {
        $selectedLevel = filter_var($_POST['teaching_level_id'] ?? $teachingLevelId, FILTER_VALIDATE_INT);
        $levelCheck = $pdo->prepare('SELECT COUNT(*) FROM provider_teaching_levels WHERE provider_id = ? AND level_id = ?');
        $levelCheck->execute([$providerId, $selectedLevel ?: 0]);
        if (!$selectedLevel || (int) $levelCheck->fetchColumn() !== 1) $errors[] = 'Choose a teaching level this tutor offers.';
        $locationType = (string) ($_POST['location_type'] ?? 'other');
        if (!in_array($locationType, ['home', 'hall', 'other'], true)) $errors[] = 'Choose where the tutoring session will take place.';
        $sessionHours = filter_var($_POST['session_hours'] ?? null, FILTER_VALIDATE_FLOAT);
        $studentCount = filter_var($_POST['student_count'] ?? null, FILTER_VALIDATE_INT);
        if ($booking['service_hourly_rate'] === null || (float) $booking['service_hourly_rate'] <= 0) {
            $errors[] = 'This tutoring subject is not available until an administrator sets its hourly price.';
        }
        if ($sessionHours === false || $sessionHours < 0.5 || $sessionHours > 12
            || fmod((float) $sessionHours * 2, 1.0) !== 0.0) {
            $errors[] = 'Choose a session length from 0.5 to 12 hours in half-hour increments.';
        }
        if ($studentCount === false || $studentCount < 1 || $studentCount > 30) {
            $errors[] = 'Choose between 1 and 30 students.';
        }
    } elseif ($booking['service_price'] === null || (float) $booking['service_price'] <= 0) {
        $errors[] = 'This home service is unavailable until an administrator sets its price.';
    } elseif ($booking['slug'] === 'laundry') {
        $laundryBaskets = filter_var($_POST['laundry_baskets'] ?? null, FILTER_VALIDATE_INT);
        if ($laundryBaskets === false || $laundryBaskets < 1 || $laundryBaskets > 10) {
            $errors[] = 'Choose between 1 and 10 laundry baskets.';
        }
    }
    $scheduledAt = null;
    if ($scheduled !== '') {
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $scheduled);
        if (!$date || $date < new DateTime()) {
            $errors[] = 'Choose a future date and time.';
        } else {
            $scheduledAt = $date->format('Y-m-d H:i:s');
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $providerLock = $pdo->prepare('SELECT user_id FROM provider_profiles WHERE user_id = ? FOR UPDATE');
            $providerLock->execute([$providerId]);
            $lockedProviderId = $providerLock->fetchColumn();
            $activeRequest = $pdo->prepare(
                "SELECT COUNT(*) FROM bookings
                 WHERE provider_id = ? AND status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress')"
            );
            $activeRequest->execute([$providerId]);
            if (!$lockedProviderId || (int) $activeRequest->fetchColumn() > 0) {
                $pdo->rollBack();
                page_error('That provider is now handling another unfinished request. Please choose another provider.', 409);
            }
            $insert = $pdo->prepare(
                "INSERT INTO bookings
                 (customer_id, provider_id, category_id, teaching_level_id, status, details, service_location, session_location_type, scheduled_for,
                  session_hours, booked_service_price, laundry_baskets, student_count)
                 VALUES (?, ?, ?, ?, 'requested', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                current_user()['id'], $providerId, $serviceId,
                $booking['module'] === 'tutoring' ? ($selectedLevel ?: null) : null,
                $details ?: null, $location, $booking['module'] === 'tutoring' ? $locationType : 'other', $scheduledAt,
                $sessionHours, $booking['module'] === 'home_services' ? $booking['service_price'] : null,
                $booking['module'] === 'home_services' ? $laundryBaskets : 1, $studentCount,
            ]);
            $bookingId = (int) $pdo->lastInsertId();
            $history = $pdo->prepare("INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, 'requested', 'Booking request sent to selected provider.')");
            $history->execute([$bookingId, current_user()['id']]);
            $notify = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', ?, ?)");
            $subject = 'New ' . $booking['name'] . ' request';
            $message = current_user()['name'] . ' has requested ' . $booking['name'] . '. Sign in to accept or decline.';
            $notify->execute([$providerId, $bookingId, $subject, $message]);
            $pdo->commit();

            $providerEmailSent = send_app_email(
                (string) $booking['provider_email'],
                'New ' . $booking['name'] . ' request on LertFind',
                "Hello {$booking['provider_name']},\n\n{$message}\n\nSign in to your LertFind dashboard to review and respond to this request."
            );
            $customerName = (string) current_user()['name'];
            $customerEmailSent = send_app_email(
                (string) current_user()['email'],
                'Your LertFind request was sent',
                "Hello {$customerName},\n\nYour request for {$booking['name']} has been sent to {$booking['provider_name']}.\nRequest number: #{$bookingId}.\n\nYou can track its status in your LertFind dashboard."
            );
            foreach ([[$providerEmailSent, $providerId, 'New ' . $booking['name'] . ' request', $message],
                [$customerEmailSent, (int) current_user()['id'], 'Your LertFind request was sent', 'Your request for ' . $booking['name'] . ' was sent to ' . $booking['provider_name'] . '. Request number: #' . $bookingId . '.']] as [$emailSent, $recipientId, $emailSubject, $emailBody]) {
                if (!$emailSent) {
                    error_log('LertFind booking email could not be sent for booking ID ' . $bookingId . ' to user ID ' . $recipientId . '. Check the server mail transport and sender configuration.');
                    continue;
                }
                try {
                    $emailNotice = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body, sent_at) VALUES (?, ?, 'email', ?, ?, NOW())");
                    $emailNotice->execute([$recipientId, $bookingId, $emailSubject, $emailBody]);
                } catch (PDOException $exception) {
                    error_log('LertFind could not record a sent booking email notification for booking ID ' . $bookingId . '.');
                }
            }
            flash($providerEmailSent ? 'success' : 'info', $providerEmailSent
                ? 'Your request was sent and the provider has been notified by email. You can follow its status in your dashboard.'
                : 'Your request is saved, but we could not email the provider. They can still see it in their dashboard; contact support if email delivery does not resume.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            page_error($exception->getMessage(), 503);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            page_error('We could not save your request. Please try again.');
        }
    }
}

$pageTitle = 'Request a booking';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <section class="booking-card">
        <div class="breadcrumb"><a href="<?= e(url('Pages/providers.php?service=' . (int) $serviceId)) ?>">← Back to providers</a></div>
        <div class="eyebrow">YOUR NEXT STEP</div>
        <h1>Request a booking.</h1>
        <div class="booking-summary"><span><b><?= e($booking['name']) ?></b><small>with <?= e($booking['provider_name']) ?> · <?= e($booking['provider_location']) ?></small><?php if ($booking['module'] === 'tutoring' && $booking['service_hourly_rate'] !== null): ?><small class="booking-hourly-rate">KES <?= number_format((float) $booking['service_hourly_rate'], 2) ?> per hour per student · set by LertFind</small><?php elseif ($booking['module'] === 'home_services' && $booking['service_price'] !== null): ?><small class="booking-hourly-rate">KES <?= number_format((float) $booking['service_price'], 2) ?> <?= $booking['slug'] === 'laundry' ? 'per laundry basket' : 'per service' ?> · set by LertFind</small><?php endif; ?></span></div>
        <p class="auth-intro">Tell the provider a little more. Your request is not confirmed until they accept it.</p>
        <?php if ($booking['module'] === 'tutoring' && $booking['service_hourly_rate'] === null): ?><p class="notice notice-info">This subject is unavailable until an administrator sets its hourly price.</p><?php endif; ?>
        <?php if ($booking['module'] === 'home_services' && $booking['service_price'] === null): ?><p class="notice notice-info">This service is unavailable until an administrator sets its price.</p><?php endif; ?>
        <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="service" value="<?= (int) $serviceId ?>">
            <input type="hidden" name="provider" value="<?= (int) $providerId ?>">
            <?php if ($booking['module'] === 'tutoring'): ?><input type="hidden" name="teaching_level_id" value="<?= (int) ($teachingLevelId ?: ($_POST['teaching_level_id'] ?? $defaults['teaching_level_id'] ?? 0)) ?>"><?php endif; ?>
            <?php if ($booking['module'] === 'tutoring'): ?><label>Where will the session happen?
                <select name="location_type" required><option value="home" <?= ($_POST['location_type'] ?? $defaults['location_type'] ?? '') === 'home' ? 'selected' : '' ?>>Student’s home</option><option value="hall" <?= ($_POST['location_type'] ?? $defaults['location_type'] ?? '') === 'hall' ? 'selected' : '' ?>>A specific hall</option><option value="other" <?= ($_POST['location_type'] ?? $defaults['location_type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other place</option></select>
            </label><?php endif; ?>
            <label><?= $booking['module'] === 'tutoring' ? 'Home address / hall or other place' : 'Service location' ?><input name="location" value="<?= e((string) ($_POST['location'] ?? $defaults['location'] ?? $booking['provider_location'])) ?>" maxlength="180" required placeholder="<?= $booking['module'] === 'tutoring' ? 'Enter the address, hall name, or other place' : 'Town, neighbourhood, or address' ?>"></label>
            <?php if ($booking['module'] === 'tutoring' && $booking['service_hourly_rate'] !== null): ?>
                <label>Session length (hours)
                    <input data-session-hours type="number" name="session_hours" min="0.5" max="12" step="0.5" value="<?= e((string) ($_POST['session_hours'] ?? $defaults['session_hours'] ?? '1')) ?>" data-hourly-rate="<?= e((string) $booking['service_hourly_rate']) ?>" required>
                    <span class="form-footnote">Estimated session total: <strong data-session-total>KES <?= number_format((float) $booking['service_hourly_rate'], 2) ?></strong>. The tutor confirms the schedule when accepting.</span>
                </label>
                <label>Number of students
                    <input data-student-count type="number" name="student_count" min="1" max="30" step="1" value="<?= e((string) ($_POST['student_count'] ?? $defaults['student_count'] ?? '1')) ?>" required>
                    <span class="form-footnote">Price is calculated per student, per hour.</span>
                </label>
            <?php endif; ?>
            <?php if ($booking['module'] === 'home_services' && $booking['slug'] === 'laundry' && $booking['service_price'] !== null): ?>
                <label>Number of laundry baskets
                    <input data-service-quantity data-unit-price="<?= e((string) $booking['service_price']) ?>" type="number" name="laundry_baskets" min="1" max="10" step="1" value="<?= e((string) ($_POST['laundry_baskets'] ?? $defaults['laundry_baskets'] ?? '1')) ?>" required>
                    <span class="form-footnote">Maximum 10 baskets. Estimated service total: <strong data-service-total>KES <?= number_format((float) $booking['service_price'] * (int) ($_POST['laundry_baskets'] ?? $defaults['laundry_baskets'] ?? 1), 2) ?></strong>.</span>
                </label>
            <?php endif; ?>
            <label>Preferred date and time <span class="optional">(optional)</span><input type="datetime-local" name="scheduled_for" value="<?= e((string) ($_POST['scheduled_for'] ?? $defaults['scheduled_for'] ?? '')) ?>"></label>
            <label>What do you need? <?php if ($booking['module'] === 'home_services'): ?><span class="optional">(required)</span><?php else: ?><span class="optional">(optional)</span><?php endif; ?><textarea name="details" rows="4" maxlength="5000" <?= $booking['module'] === 'home_services' ? 'required' : '' ?> placeholder="<?= $booking['module'] === 'home_services' ? 'Describe the work, approximate size or quantity, and any special requirements. Do not include phone numbers.' : 'Share your goals or any helpful details…' ?>"><?= e((string) ($_POST['details'] ?? $defaults['details'] ?? '')) ?></textarea></label>
            <?php if ($booking['module'] === 'home_services'): ?><p class="form-footnote">Your total uses the fixed price set by LertFind<?= $booking['slug'] === 'laundry' ? ' multiplied by the number of baskets' : '' ?>. The provider will accept the request before payment is due.</p><?php endif; ?>
            <button type="submit" class="button button-primary button-full" <?= ($booking['module'] === 'tutoring' && $booking['service_hourly_rate'] === null) || ($booking['module'] === 'home_services' && $booking['service_price'] === null) ? 'disabled' : '' ?>>Send request</button>
        </form>
        <p class="form-footnote">No payment is taken at this stage. You’ll see a clear price and commission breakdown before payment.</p>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
