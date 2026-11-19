<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
if (!is_role('student_parent', 'customer', 'tutor', 'service_provider')) {
    page_error('Only customers and providers can contact LertFind support.', 403);
}

$requestData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$bookingId = filter_var($requestData['booking'] ?? null, FILTER_VALIDATE_INT);
$errors = [];
$subject = trim((string) ($_POST['subject'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$module = (string) ($_POST['module'] ?? 'general');
$booking = null;
try {
    $pdo = db();
    if ($bookingId) {
        $query = $pdo->prepare(
            'SELECT b.id, b.customer_id, b.provider_id, c.name, c.module
             FROM bookings b JOIN service_categories c ON c.id = b.category_id
             WHERE b.id = ? AND (b.customer_id = ? OR b.provider_id = ?)'
        );
        $query->execute([$bookingId, current_user()['id'], current_user()['id']]);
        $booking = $query->fetch();
        if (!$booking) page_error('You can only report an issue for your own booking.', 404);
        $module = $booking['module'];
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    if (!$booking && !in_array($module, ['general', 'tutoring', 'home_services'], true)) {
        $errors[] = 'Choose a valid support topic.';
    }
    if ($subject === '' || mb_strlen($subject) > 180) $errors[] = 'Enter a short subject (up to 180 characters).';
    if ($message === '' || mb_strlen($message) > 10000) $errors[] = 'Describe the issue in up to 10,000 characters.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO complaints (booking_id, module, submitted_by, subject, message) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$booking ? $booking['id'] : null, $module, current_user()['id'], $subject, $message]);
            $complaintId = (int) $pdo->lastInsertId();
            $moduleFilter = $module === 'general'
                ? "AND ((r.slug = 'super_admin') OR (r.slug IN ('admin1','admin2') AND (ap_tutor.handle_complaints = 1 OR ap_home.handle_complaints = 1)))"
                : "AND (r.slug = 'super_admin' OR (r.slug IN ('admin1','admin2') AND ap_tutor.handle_complaints = 1))";
            $adminQuery = $pdo->prepare(
                "SELECT DISTINCT u.id, u.email FROM users u JOIN roles r ON r.id = u.role_id
                 LEFT JOIN admin_permissions ap_tutor ON ap_tutor.user_id = u.id AND ap_tutor.module = 'tutoring'
                 LEFT JOIN admin_permissions ap_home ON ap_home.user_id = u.id AND ap_home.module = 'home_services'
                 WHERE u.is_active = 1 AND u.email_verified_at IS NOT NULL {$moduleFilter}"
            );
            $adminQuery->execute();
            $admins = $adminQuery->fetchAll();
            $bookingRef = $booking ? ' for booking #' . (int) $booking['id'] : '';
            $notify = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'A support complaint needs review', ?)");
            foreach ($admins as $admin) {
                $notify->execute([$admin['id'], $booking ? $booking['id'] : null, 'Complaint #' . $complaintId . $bookingRef . ': ' . $subject]);
            }
            $pdo->commit();
            foreach ($admins as $admin) {
                mail($admin['email'], 'LertFind complaint needs review', 'A support complaint #' . $complaintId . $bookingRef . ' was raised.', "From: LertFind <no-reply@tutalink.co.ke>\r\n");
            }
            flash('success', 'Your message has been sent to the LertFind support team.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            page_error($exception->getMessage(), 503);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            page_error('We could not submit your report. Please try again.');
        }
    }
}

$pageTitle = 'Contact support';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <section class="booking-card">
        <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
        <div class="eyebrow">WE’RE HERE TO HELP</div>
        <h1><?= $booking ? 'Tell us what happened.' : 'Contact LertFind support.' ?></h1>
        <?php if ($booking): ?><div class="booking-summary"><span class="summary-icon">!</span><span><b><?= e($booking['name']) ?></b><small>Booking #<?= (int) $booking['id'] ?></small></span></div><?php endif; ?>
        <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <?php if ($booking): ?><input type="hidden" name="booking" value="<?= (int) $bookingId ?>"><?php else: ?>
                <label>Topic<select name="module" required><option value="general" <?= $module === 'general' ? 'selected' : '' ?>>General support</option><option value="tutoring" <?= $module === 'tutoring' ? 'selected' : '' ?>>Tutoring</option><option value="home_services" <?= $module === 'home_services' ? 'selected' : '' ?>>Home services</option></select></label>
            <?php endif; ?>
            <label>Subject<input name="subject" value="<?= e($subject) ?>" maxlength="180" required></label>
            <label>Details<textarea name="message" rows="6" maxlength="10000" required><?= e($message) ?></textarea></label>
            <button type="submit" class="button button-primary button-full">Send to LertFind support</button>
        </form>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
