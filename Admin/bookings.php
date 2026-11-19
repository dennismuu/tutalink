<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');
$modules = [];
foreach (['tutoring', 'home_services'] as $module) {
    if (admin_has_permission($module, 'manage_bookings')) $modules[] = $module;
}
if (is_role('super_admin')) $modules = ['tutoring', 'home_services'];
if (!$modules) page_error('Your administrator account is not assigned to booking management.', 403);

$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$statuses = ['requested', 'accepted', 'declined', 'awaiting_payment', 'paid', 'on_the_way', 'in_progress', 'completed', 'cancelled'];
if ($status !== '' && !in_array($status, $statuses, true)) $status = '';
try {
    $moduleMarks = implode(',', array_fill(0, count($modules), '?'));
    $where = ["c.module IN ({$moduleMarks})"];
    $params = $modules;
    if ($status !== '') {
        $where[] = 'b.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(CAST(b.id AS CHAR) = ? OR customer.full_name LIKE ? OR provider.full_name LIKE ? OR c.name LIKE ?)';
        $params[] = $search;
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $query = db()->prepare(
        'SELECT b.id, b.status, b.created_at, b.scheduled_for, b.service_location,
                b.session_location_type, b.quoted_amount, c.name AS service_name, c.module,
                customer.full_name AS customer_name, provider.full_name AS provider_name
         FROM bookings b
         JOIN service_categories c ON c.id = b.category_id
         JOIN users customer ON customer.id = b.customer_id
         JOIN users provider ON provider.id = b.provider_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY b.created_at DESC LIMIT 300'
    );
    $query->execute($params);
    $bookings = $query->fetchAll();
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
$pageTitle = 'Bookings';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="page-heading"><div><div class="eyebrow">BOOKING OPERATIONS</div><h1>Bookings.</h1><p>Review and filter requests across the modules assigned to your admin account.</p></div><span class="count-pill"><?= count($bookings) ?> shown</span></div>
    <form method="get" class="booking-filter-form">
        <label>Search by booking ID, customer, provider, or service<input name="q" value="<?= e($search) ?>" maxlength="180" placeholder="Search bookings"></label>
        <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($statuses as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $option))) ?></option><?php endforeach; ?></select></label>
        <button class="button button-primary button-small" type="submit">Filter bookings</button>
        <?php if ($search !== '' || $status !== ''): ?><a class="button button-outline button-small" href="<?= e(url('Admin/bookings.php')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if (!$bookings): ?><section class="empty-state compact"><h2>No bookings match these filters.</h2><p>Try clearing a filter or search term.</p></section>
    <?php else: ?><div class="admin-booking-table-wrap"><table class="admin-booking-table"><thead><tr><th>Booking</th><th>Service</th><th>Customer</th><th>Provider</th><th>Status</th><th>Location / schedule</th><th>Cost</th></tr></thead><tbody><?php foreach ($bookings as $booking): ?><tr><td>#<?= (int) $booking['id'] ?><small><?= e(date('M j, Y · g:i a', strtotime($booking['created_at']))) ?></small></td><td><?= e($booking['service_name']) ?><small><?= $booking['module'] === 'tutoring' ? 'Tutoring' : 'Home services' ?></small></td><td><?= e($booking['customer_name']) ?></td><td><?= e($booking['provider_name']) ?></td><td><span class="status status-<?= e($booking['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $booking['status']))) ?></span></td><td><?= e($booking['service_location']) ?><?php if ($booking['module'] === 'tutoring'): ?><small><?= e(ucfirst($booking['session_location_type'])) ?> session</small><?php endif; ?><?php if ($booking['scheduled_for']): ?><small><?= e(date('M j, Y · g:i a', strtotime($booking['scheduled_for']))) ?></small><?php endif; ?></td><td><?= $booking['quoted_amount'] === null ? '—' : 'KES ' . number_format((float) $booking['quoted_amount'], 2) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
