<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');
$modules = [];
foreach (['tutoring', 'home_services'] as $module) {
    if (admin_has_permission($module, 'handle_complaints')) $modules[] = $module;
}
if (current_user()['role'] === 'super_admin') $modules = ['tutoring', 'home_services'];
if (!$modules) page_error('Your administrator account is not assigned to complaint management.', 403);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT);
    $status = (string) ($_POST['status'] ?? '');
    $note = trim((string) ($_POST['resolution_note'] ?? ''));
    if (!$complaintId || !in_array($status, ['in_review', 'resolved', 'closed'], true) || mb_strlen($note) > 5000) {
        flash('error', 'Choose a valid complaint status and keep the note under 5,000 characters.');
        header('Location: ' . url('Admin/complaints.php'));
        exit;
    }
    try {
        $pdo = db();
        $marks = implode(',', array_fill(0, count($modules), '?'));
        $check = $pdo->prepare(
            "SELECT cp.id FROM complaints cp
             LEFT JOIN bookings b ON b.id = cp.booking_id
             LEFT JOIN service_categories c ON c.id = b.category_id
             WHERE cp.id = ? AND (cp.module = 'general' OR COALESCE(c.module, cp.module) IN ({$marks}))"
        );
        $check->execute([$complaintId, ...$modules]);
        if (!$check->fetchColumn()) page_error('You cannot manage that complaint.', 403);
        $update = $pdo->prepare('UPDATE complaints SET status = ?, resolution_note = ?, assigned_to = ? WHERE id = ?');
        $update->execute([$status, $note ?: null, current_user()['id'], $complaintId]);
        flash('success', 'Complaint status updated.');
        header('Location: ' . url('Admin/complaints.php'));
        exit;
    } catch (RuntimeException $exception) {
        page_error($exception->getMessage(), 503);
    } catch (PDOException $exception) {
        page_error('We could not update that complaint. Please try again.');
    }
}

try {
    $marks = implode(',', array_fill(0, count($modules), '?'));
    $query = db()->prepare(
        "SELECT cp.*, b.id AS booking_number, c.name AS service_name, COALESCE(c.module, cp.module) AS module,
                submitter.full_name AS submitter_name, submitter.email AS submitter_email
         FROM complaints cp LEFT JOIN bookings b ON b.id = cp.booking_id
         LEFT JOIN service_categories c ON c.id = b.category_id
         JOIN users submitter ON submitter.id = cp.submitted_by
         WHERE cp.module = 'general' OR COALESCE(c.module, cp.module) IN ({$marks})
         ORDER BY FIELD(cp.status, 'open','in_review','resolved','closed'), cp.created_at DESC LIMIT 100"
    );
    $query->execute($modules);
    $complaints = $query->fetchAll();
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$pageTitle = 'Manage complaints';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">CUSTOMER CARE</div><h1>Support complaints.</h1><p>Review booking-related and general complaints in your assigned modules.</p></div><span class="count-pill"><?= count($complaints) ?> cases</span></div>
    <?php if (!$complaints): ?><div class="empty-state compact"><h3>No open issues.</h3><p>New booking complaints will appear here.</p></div><?php else: ?>
        <section class="complaint-list"><?php foreach ($complaints as $complaint): ?>
            <article class="complaint-card">
                <div class="booking-card-top"><span class="booking-category"><?= e($complaint['service_name'] ?: 'General support') ?> · <?= e(ucwords(str_replace('_', ' ', $complaint['module']))) ?><?= $complaint['booking_number'] ? ' · Booking #' . (int) $complaint['booking_number'] : '' ?></span><span class="status status-<?= e($complaint['status']) ?>"><?= e(str_replace('_', ' ', $complaint['status'])) ?></span></div>
                <h2><?= e($complaint['subject']) ?></h2><p class="complaint-meta">Reported by <?= e($complaint['submitter_name']) ?> · <a href="mailto:<?= e($complaint['submitter_email']) ?>"><?= e($complaint['submitter_email']) ?></a> · <?= e(date('M j, Y · g:i a', strtotime($complaint['created_at']))) ?></p>
                <p class="complaint-body"><?= nl2br(e($complaint['message'])) ?></p>
                <form method="post" class="complaint-update"><?= csrf_field() ?><input type="hidden" name="complaint_id" value="<?= (int) $complaint['id'] ?>"><label>Status<select name="status"><option value="in_review" <?= $complaint['status'] === 'in_review' ? 'selected' : '' ?>>In review</option><option value="resolved" <?= $complaint['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option><option value="closed" <?= $complaint['status'] === 'closed' ? 'selected' : '' ?>>Closed</option></select></label><label>Resolution note<textarea name="resolution_note" rows="2" maxlength="5000"><?= e($complaint['resolution_note']) ?></textarea></label><button class="button button-outline button-small" type="submit">Update case</button></form>
            </article>
        <?php endforeach; ?></section>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
