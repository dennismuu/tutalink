<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');
$modules = [];
foreach (['tutoring', 'home_services'] as $module) {
    if (admin_has_permission($module, 'view_reports')) $modules[] = $module;
}
if (current_user()['role'] === 'super_admin') $modules = ['tutoring', 'home_services'];
if (!$modules) page_error('Your administrator account is not assigned to platform reports.', 403);

try {
    $marks = implode(',', array_fill(0, count($modules), '?'));
    $summaryQuery = db()->prepare(
        "SELECT c.module, COUNT(DISTINCT b.id) AS total_bookings,
                SUM(b.status = 'requested') AS pending_requests,
                SUM(b.status = 'completed') AS completed_bookings,
                COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'paid') THEN b.quoted_amount ELSE 0 END), 0) AS gross_volume,
                COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'paid') THEN b.commission_amount ELSE 0 END), 0) AS platform_commission,
                COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'paid') THEN b.provider_amount ELSE 0 END), 0) AS provider_earnings
         FROM service_categories c LEFT JOIN bookings b ON b.category_id = c.id
         WHERE c.module IN ({$marks}) GROUP BY c.module"
    );
    $summaryQuery->execute($modules);
    $summaries = $summaryQuery->fetchAll();
    $complaintsQuery = db()->prepare(
        "SELECT c.module, COUNT(*) AS open_complaints
         FROM complaints cp JOIN bookings b ON b.id = cp.booking_id
         JOIN service_categories c ON c.id = b.category_id
         WHERE c.module IN ({$marks}) AND cp.status IN ('open','in_review') GROUP BY c.module"
    );
    $complaintsQuery->execute($modules);
    $openComplaints = [];
    foreach ($complaintsQuery->fetchAll() as $row) $openComplaints[$row['module']] = (int) $row['open_complaints'];
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

if (($_GET['format'] ?? '') === 'csv') {
    $filename = 'lertfind-platform-report-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, private');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('The report file could not be created.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['LertFind platform report', 'Generated ' . date('Y-m-d H:i:s')], ',', '"', '');
    fputcsv($output, ['Reporting period', 'All time'], ',', '"', '');
    fputcsv($output, ['Module', 'Booking requests', 'Awaiting providers', 'Services completed', 'Verified payment volume (KES)', 'Platform commission (KES)', 'Provider earnings (KES)', 'Open complaints'], ',', '"', '');
    foreach ($summaries as $summary) {
        $moduleLabel = $summary['module'] === 'tutoring' ? 'Tutoring' : 'Home services';
        fputcsv($output, [
            $moduleLabel,
            (int) $summary['total_bookings'],
            (int) $summary['pending_requests'],
            (int) $summary['completed_bookings'],
            number_format((float) $summary['gross_volume'], 2, '.', ''),
            number_format((float) $summary['platform_commission'], 2, '.', ''),
            number_format((float) $summary['provider_earnings'], 2, '.', ''),
            $openComplaints[$summary['module']] ?? 0,
        ], ',', '"', '');
    }
    fclose($output);
    exit;
}

$pageTitle = 'Platform reports';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">PLATFORM PULSE</div><h1>Know what’s working.</h1><p>Booking progress, payment volume, provider earnings and customer issues. Local demo totals are not actual funds.</p></div><a class="button button-primary" href="<?= e(url('Admin/reports.php?format=csv')) ?>">Generate report (CSV)</a></div>
    <section class="report-grid"><?php foreach ($summaries as $summary): $module = $summary['module']; ?>
        <article class="report-card"><div class="report-card-heading"><span class="role-badge"><?= $module === 'tutoring' ? 'Tutoring' : 'Home services' ?></span></div><h2><?= number_format((int) $summary['total_bookings']) ?><small> booking requests</small></h2><div class="report-metrics"><span>Awaiting providers <strong><?= number_format((int) $summary['pending_requests']) ?></strong></span><span>Services completed <strong><?= number_format((int) $summary['completed_bookings']) ?></strong></span><span>Verified payment volume <strong>KES <?= number_format((float) $summary['gross_volume'], 2) ?></strong></span><span>Platform commission <strong>KES <?= number_format((float) $summary['platform_commission'], 2) ?></strong></span><span>Provider earnings <strong>KES <?= number_format((float) $summary['provider_earnings'], 2) ?></strong></span><span>Open complaints <strong><?= number_format($openComplaints[$module] ?? 0) ?></strong></span></div></article>
    <?php endforeach; ?></section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
