<?php
$pageTitle = $pageTitle ?? 'LertFind Kenya';
$activePage = $activePage ?? '';
$notice = take_flash();
$currentUser = current_user();
$cartCount = is_array($_SESSION['booking_cart'] ?? null) ? count($_SESSION['booking_cart']) : 0;
$isAdminUser = in_array($currentUser['role'] ?? '', ['super_admin', 'admin1', 'admin2'], true);
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isAdminLoginPage = (bool) preg_match('~/Admin/login\.php$~i', $scriptName) || !empty($adminLogin);
$isAdminRoute = (bool) preg_match('~/Admin/(?!login\.php$)~i', $scriptName);
$isAdminDashboard = $isAdminUser && preg_match('~/Pages/dashboard\.php$~i', $scriptName);
$isAdminProfile = $isAdminUser && preg_match('~/Pages/profile\.php$~i', $scriptName);
$isAdminShell = $isAdminUser && ($isAdminRoute || $isAdminDashboard || $isAdminProfile);
$isUserAppShell = !$isAdminUser && $currentUser && in_array(basename($scriptName), [
    'booking.php',
    'cart.php',
    'complaint.php',
    'dashboard.php',
    'materials.php',
    'messages.php',
    'pay.php',
    'pay_checkout.php',
    'profile.php',
    'providers.php',
    'services.php',
], true);
$isUserDashboard = !$isAdminUser && (bool) preg_match('~/Pages/dashboard\.php$~i', $scriptName);
$isAppShell = $isAdminShell || $isUserAppShell;
$adminLinks = [];
$userLinks = [];
if ($isAdminShell) {
    $adminLinks[] = ['Dashboard', 'Pages/dashboard.php', true, 0];
    $messageModules = [];
    foreach (['tutoring', 'home_services'] as $module) {
        if ($currentUser['role'] === 'super_admin' || admin_has_permission($module, 'manage_bookings')) $messageModules[] = $module;
    }
    if ($messageModules) {
        $moduleMarks = implode(',', array_fill(0, count($messageModules), '?'));
        $unreadMessages = db()->prepare(
            "SELECT COUNT(*)
             FROM messages m
             JOIN bookings b ON b.id = m.booking_id
             JOIN service_categories c ON c.id = b.category_id
             LEFT JOIN admin_message_reads r ON r.admin_id = ? AND r.booking_id = b.id
             WHERE c.module IN ({$moduleMarks}) AND m.id > COALESCE(r.last_read_message_id, 0)"
        );
        $unreadMessages->execute([$currentUser['id'], ...$messageModules]);
        $adminLinks[] = ['Messages', 'Admin/messages.php', false, (int) $unreadMessages->fetchColumn()];
    }
    if ($currentUser['role'] === 'super_admin' || admin_has_permission('tutoring', 'manage_providers') || admin_has_permission('home_services', 'manage_providers')) {
        $adminLinks[] = ['Providers', 'Admin/providers.php', false, 0];
    }
    if ($currentUser['role'] === 'super_admin' || admin_has_permission('tutoring', 'manage_bookings') || admin_has_permission('home_services', 'manage_bookings')) {
        $adminLinks[] = ['Bookings', 'Admin/bookings.php', false, 0];
        $adminLinks[] = ['Booking actions', 'Pages/dashboard.php?view=requests', false, 0];
    }
    if ($currentUser['role'] === 'super_admin' || admin_has_permission('tutoring', 'manage_services') || admin_has_permission('home_services', 'manage_services')) {
        $adminLinks[] = ['Services & subjects', 'Admin/services.php', false, 0];
    }
    if ($currentUser['role'] === 'super_admin' || admin_has_permission('tutoring', 'manage_services')) {
        $adminLinks[] = ['Revision materials', 'Admin/materials.php', false, 0];
    }
    if ($currentUser['role'] === 'super_admin') {
        $adminLinks[] = ['Manage users', 'Admin/manage_users.php', false, 0];
        $adminLinks[] = ['Admin accounts', 'Admin/users.php', false, 0];
    }
    if ($currentUser['role'] === 'super_admin' || admin_has_permission('tutoring', 'handle_complaints') || admin_has_permission('home_services', 'handle_complaints')) {
        $adminLinks[] = ['Complaints', 'Admin/complaints.php', false, 0];
    }
    if ($currentUser['role'] === 'super_admin' || admin_has_permission('tutoring', 'manage_payments') || admin_has_permission('home_services', 'manage_payments')) {
        $adminLinks[] = ['Payments', 'Admin/payments.php', false, 0];
    }
    if ($currentUser['role'] === 'super_admin' || admin_has_permission('tutoring', 'view_reports') || admin_has_permission('home_services', 'view_reports')) {
        $adminLinks[] = ['Reports', 'Admin/reports.php', false, 0];
    }
}
if ($isUserAppShell) {
    $userLinks = [
        ['Dashboard', 'Pages/dashboard.php', 'dashboard'],
        ['My requests', 'Pages/dashboard.php?view=requests', 'requests'],
        ['Find a tutor', 'Pages/services.php?module=tutoring', 'tutors'],
        ['Home services', 'Pages/services.php?module=home_services', 'services'],
    ];
    if ($currentUser['role'] === 'student_parent') {
        array_splice($userLinks, 3, 0, [['Tutoring services', 'Pages/services.php?module=tutoring&view=catalogue', 'tutor-services']]);
        $userLinks[] = ['Revision materials', 'Pages/materials.php', 'materials'];
    }
    $userLinks[] = ['Notifications', 'Pages/dashboard.php#notifications', 'notifications'];
    $userLinks[] = ['My profile', 'Pages/profile.php', 'profile'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102f4e">
    <title><?= e($pageTitle) ?> · LertFind</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="<?= e($activePage === 'home' ? 'home-page' : '') ?><?= $isAppShell ? ' admin-app' : '' ?><?= $isUserAppShell ? ' user-app-shell' : '' ?><?= $isAdminLoginPage ? ' admin-login-page' : '' ?>">
<?php if ($isAppShell): ?>
<div class="admin-app-shell">
    <aside class="admin-sidebar">
        <a class="brand admin-brand" href="<?= e(url('Pages/dashboard.php')) ?>" aria-label="LertFind dashboard">
            <span class="brand-mark">L<span>·</span></span>
            <span class="brand-name">Lert<span>Find</span><small><?= $isAdminShell ? 'ADMIN' : 'WORKSPACE' ?></small></span>
        </a>
        <div class="admin-sidebar-label">WORKSPACE</div>
        <?php if ($isAdminShell): ?>
            <nav class="admin-nav" aria-label="Admin navigation">
                <?php foreach ($adminLinks as [$label, $path, $isDashboard, $badgeCount]): ?>
                    <?php $isRequestActions = $path === 'Pages/dashboard.php?view=requests';
                    $isActive = $isRequestActions
                        ? (bool) $isAdminDashboard && ($_GET['view'] ?? '') === 'requests'
                        : ($isDashboard
                            ? (bool) $isAdminDashboard && ($_GET['view'] ?? '') !== 'requests'
                            : str_ends_with($scriptName, '/' . basename($path))); ?>
                    <a class="admin-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= e(url($path)) ?>"><?= e($label) ?><?php if ($badgeCount > 0): ?><span class="admin-nav-badge" aria-label="<?= number_format($badgeCount) ?> unread messages"><?= $badgeCount > 99 ? '99+' : number_format($badgeCount) ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            </nav>
        <?php else: ?>
            <nav class="admin-nav" aria-label="Dashboard navigation">
                <?php foreach ($userLinks as [$label, $path, $activeKey]): ?>
                    <?php $isActive = match ($activeKey) {
                        'dashboard' => $isUserDashboard && ($_GET['view'] ?? '') !== 'requests',
                        'requests' => $isUserDashboard && ($_GET['view'] ?? '') === 'requests',
                        'tutors', 'services', 'tutor-services' => in_array(basename($scriptName), ['services.php', 'providers.php'], true) && $activePage === $activeKey,
                        'materials' => $activePage === 'materials',
                        'profile' => basename($scriptName) === 'profile.php',
                        default => false,
                    }; ?>
                    <a class="admin-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= e(url($path)) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <a class="admin-public-link" href="<?= e(url('index.php')) ?>">View public site</a>
    </aside>
    <div class="admin-workspace">
        <header class="admin-topbar">
            <div><span class="admin-topbar-label"><?= $isAdminShell ? 'LertFind admin' : e(ucwords(str_replace('_', ' ', $currentUser['role']))) ?></span><strong><?= e($pageTitle) ?></strong></div>
            <div class="admin-topbar-actions">
                <span><?= e($currentUser['name']) ?></span>
                <a href="<?= e(url('Pages/profile.php')) ?>">My profile</a>
                <form method="post" action="<?= e(url('Pages/logout.php')) ?>"><?= csrf_field() ?><button type="submit">Sign out</button></form>
            </div>
        </header>
        <?php if ($notice): ?>
            <div class="flash flash-<?= e($notice['type']) ?>" role="<?= $notice['type'] === 'error' ? 'alert' : 'status' ?>" aria-live="<?= $notice['type'] === 'error' ? 'assertive' : 'polite' ?>" data-toast><?= e($notice['message']) ?></div>
        <?php endif; ?>
<?php elseif ($isAdminLoginPage): ?>
<header class="site-header admin-login-header">
    <a class="brand" href="<?= e(url('index.php')) ?>" aria-label="LertFind home">
        <span class="brand-mark">L<span>·</span></span>
        <span class="brand-name">Lert<span>Find</span><small>ADMIN</small></span>
    </a>
    <span class="admin-login-label">Administrator access</span>
    <a class="text-link" href="<?= e(url('index.php')) ?>">Public site</a>
</header>
<?php else: ?>
<header class="site-header">
    <a class="brand" href="<?= e(url('index.php')) ?>" aria-label="LertFind home">
        <span class="brand-mark">L<span>·</span></span>
        <span class="brand-name">Lert<span>Find</span><small>KENYA</small></span>
    </a>
    <nav class="main-nav" aria-label="Main navigation">
        <a class="<?= $activePage === 'home' ? 'active' : '' ?>" href="<?= e(url('index.php')) ?>">Home</a>
        <a class="<?= $activePage === 'tutors' ? 'active' : '' ?>" href="<?= e(url('Pages/services.php?module=tutoring')) ?>">Tutoring</a>
        <a class="<?= $activePage === 'services' ? 'active' : '' ?>" href="<?= e(url('Pages/services.php?module=home_services')) ?>">Home services</a>
        <?php if (is_role('student_parent')): ?><a class="<?= $activePage === 'materials' ? 'active' : '' ?>" href="<?= e(url('Pages/materials.php')) ?>">Revision materials</a><?php endif; ?>
        <?php if ($activePage === 'home'): ?>
            <a href="#about">About us</a>
            <a href="#how-it-works">How it works</a>
        <?php endif; ?>
    </nav>
    <div class="header-actions">
        <a class="text-link cart-link" href="<?= e(url('Pages/cart.php')) ?>">Cart<?= $cartCount ? ' (' . $cartCount . ')' : '' ?></a>
        <?php if (current_user()): ?>
            <a class="text-link account-link" href="<?= e(url('Pages/profile.php')) ?>">My profile</a>
            <a class="text-link account-link" href="<?= e(url('Pages/dashboard.php')) ?>">My dashboard</a>
            <form method="post" action="<?= e(url('Pages/logout.php')) ?>" class="header-logout"><?= csrf_field() ?><button class="button button-small button-outline" type="submit">Sign out</button></form>
        <?php else: ?>
            <a class="text-link" href="<?= e(url('Pages/login.php')) ?>">Log in</a>
            <a class="button button-small button-primary" href="<?= e(url('Pages/register.php')) ?>">Get started</a>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>
<?php if ($notice && !$isAppShell): ?>
    <div class="flash flash-<?= e($notice['type']) ?>" role="<?= $notice['type'] === 'error' ? 'alert' : 'status' ?>" aria-live="<?= $notice['type'] === 'error' ? 'assertive' : 'polite' ?>" data-toast><?= e($notice['message']) ?></div>
<?php endif; ?>
