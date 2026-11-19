<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin');

$errors = [];
$accountCreateValues = [
    'full_name' => '',
    'phone' => '',
    'email' => '',
    'role' => 'student_parent',
    'qualifications' => '',
    'location' => '',
    'service_mode' => 'both',
];
$accountCreateCategories = [];
$accountCreateLevels = [];
$showCreateForm = false;
$selectedUserId = filter_var($_GET['user'] ?? null, FILTER_VALIDATE_INT);
$selectedUserId = $selectedUserId && $selectedUserId > 0 ? (int) $selectedUserId : null;
$editValues = null;
try {
    $accountCategoryOptions = db()->query(
        "SELECT id, module, name FROM service_categories
         WHERE is_active = 1 AND is_coming_soon = 0
           AND NOT (module = 'tutoring' AND LOWER(name) IN
             ('primary','junior','secondary','kcse','pp1','pp2','grade 1','grade 2','grade 3','grade 4','grade 5','grade 6','grade 7','grade 8','grade 9','senior school'))
         ORDER BY module, sort_order, name"
    )->fetchAll();
    $accountTeachingLevels = db()->query('SELECT id, name FROM tutor_teaching_levels WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $showCreateForm = true;
        foreach ($accountCreateValues as $key => $_) {
            $accountCreateValues[$key] = trim((string) ($_POST[$key] ?? $accountCreateValues[$key]));
        }
        foreach (is_array($_POST['categories'] ?? null) ? $_POST['categories'] : [] as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT);
            if ($id && $id > 0) $accountCreateCategories[] = (int) $id;
        }
        $accountCreateCategories = array_values(array_unique($accountCreateCategories));
        foreach (is_array($_POST['teaching_levels'] ?? null) ? $_POST['teaching_levels'] : [] as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT);
            if ($id && $id > 0) $accountCreateLevels[] = (int) $id;
        }
        $accountCreateLevels = array_values(array_unique($accountCreateLevels));
        $phone = preg_replace('/[\s()+-]/', '', $accountCreateValues['phone']);
        $publicRoles = ['student_parent', 'customer', 'tutor', 'service_provider'];
        if ($accountCreateValues['full_name'] === '' || mb_strlen($accountCreateValues['full_name']) > 160) $errors[] = 'Enter a name up to 160 characters.';
        if (!$phone || !preg_match('/^\d{7,15}$/', $phone)) $errors[] = 'Enter a valid phone number.';
        if (!filter_var($accountCreateValues['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($accountCreateValues['email']) > 254) $errors[] = 'Enter a valid email address.';
        if (!in_array($accountCreateValues['role'], $publicRoles, true)) $errors[] = 'Choose a valid account type.';
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 12) $errors[] = 'Use a temporary password of at least 12 characters.';
        $isProvider = in_array($accountCreateValues['role'], ['tutor', 'service_provider'], true);
        $expectedModule = $accountCreateValues['role'] === 'tutor' ? 'tutoring' : 'home_services';
        if ($isProvider) {
            if ($accountCreateValues['qualifications'] === '' || mb_strlen($accountCreateValues['qualifications']) > 5000) $errors[] = 'Enter qualifications or experience for the provider.';
            if ($accountCreateValues['location'] === '' || mb_strlen($accountCreateValues['location']) > 180) $errors[] = 'Enter the provider town or service area.';
            if ($accountCreateValues['role'] === 'service_provider' && $accountCreateValues['service_mode'] !== 'physical') $errors[] = 'Home service providers must be set to physical service.';
            if ($accountCreateValues['role'] === 'tutor' && !in_array($accountCreateValues['service_mode'], ['online', 'physical', 'both'], true)) $errors[] = 'Choose a valid tutor service mode.';
            $validCategoryIds = array_map(
                'intval',
                array_column(
                    array_filter($accountCategoryOptions, static fn(array $category): bool => $category['module'] === $expectedModule),
                    'id'
                )
            );
            if (!$accountCreateCategories || array_diff($accountCreateCategories, $validCategoryIds)) $errors[] = 'Select at least one valid service or subject for the account type.';
            if ($accountCreateValues['role'] === 'tutor') {
                $validLevelIds = array_map('intval', array_column($accountTeachingLevels, 'id'));
                if (!$accountCreateLevels || array_diff($accountCreateLevels, $validLevelIds)) $errors[] = 'Select at least one current tutoring level.';
            }
        }
        if (!$errors) {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
                $roleQuery->execute([$accountCreateValues['role']]);
                $roleId = $roleQuery->fetchColumn();
                if (!$roleId) throw new RuntimeException('The selected account type is not configured.');
                $pdo->prepare(
                    'INSERT INTO users (role_id, `role`, full_name, phone, email, password_hash, email_verified_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    (int) $roleId,
                    $accountCreateValues['role'],
                    $accountCreateValues['full_name'],
                    $phone,
                    strtolower($accountCreateValues['email']),
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
                $createdUserId = (int) $pdo->lastInsertId();
                if ($isProvider) {
                    $pdo->prepare('INSERT INTO provider_profiles (user_id, qualifications, location, service_mode) VALUES (?, ?, ?, ?)')
                        ->execute([$createdUserId, $accountCreateValues['qualifications'], $accountCreateValues['location'], $accountCreateValues['service_mode']]);
                    $insertCategory = $pdo->prepare('INSERT INTO provider_services (provider_id, category_id) VALUES (?, ?)');
                    foreach ($accountCreateCategories as $categoryId) $insertCategory->execute([$createdUserId, $categoryId]);
                    if ($accountCreateValues['role'] === 'tutor') {
                        $insertLevel = $pdo->prepare('INSERT INTO provider_teaching_levels (provider_id, level_id) VALUES (?, ?)');
                        foreach ($accountCreateLevels as $levelId) $insertLevel->execute([$createdUserId, $levelId]);
                    }
                }
                $pdo->commit();
                flash('success', $isProvider
                    ? 'Account created. Share the temporary password securely; the provider must submit documents before approval.'
                    : 'Account created and ready to use. Share the temporary password securely.');
                header('Location: ' . url('Admin/manage_users.php'));
                exit;
            } catch (PDOException $exception) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $exception->getCode() === '23000'
                    ? 'An account already uses that email address.'
                    : 'The account could not be created.';
            } catch (RuntimeException $exception) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                page_error($exception->getMessage(), 503);
            }
        }
    } else {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId === (int) current_user()['id']) {
            $errors[] = 'Choose a valid user other than your own account.';
        } else {
            try {
                $accountQuery = db()->prepare(
                    "SELECT u.id, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id
                     WHERE u.id = ?"
                );
                $accountQuery->execute([$userId]);
                $account = $accountQuery->fetch();
                if (!$account || $account['role'] === 'super_admin') {
                    $errors[] = 'That account was not found or is protected.';
                } elseif ($action === 'edit') {
                    $name = trim((string) ($_POST['full_name'] ?? ''));
                    $phone = trim((string) ($_POST['phone'] ?? ''));
                    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                    if ($name === '' || mb_strlen($name) > 160 || $phone === '' || mb_strlen($phone) > 32 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
                        $errors[] = 'Enter a name up to 160 characters, a phone number up to 32 characters, and a valid email address.';
                    } else {
                        db()->prepare('UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?')
                            ->execute([$name, $phone, $email, $userId]);
                        flash('success', 'User details updated.');
                        header('Location: ' . url('Admin/manage_users.php'));
                        exit;
                    }
                } elseif ($action === 'status') {
                    $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
                    if (!in_array($active, [0, 1], true)) {
                        $errors[] = 'Choose a valid account status.';
                    } else {
                        db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active, $userId]);
                        flash('success', $active ? 'User account activated.' : 'User account deactivated.');
                        header('Location: ' . url('Admin/manage_users.php'));
                        exit;
                    }
                } elseif ($action === 'delete') {
                    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                    flash('success', 'User account deleted.');
                    header('Location: ' . url('Admin/manage_users.php'));
                    exit;
                } else {
                    $errors[] = 'That action is not available.';
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors[] = $action === 'edit'
                        ? 'Another account already uses that email address.'
                        : 'This account has platform history that must be preserved. Deactivate it instead of deleting it.';
                } else {
                    $errors[] = 'The user update could not be saved.';
                }
            } catch (RuntimeException $exception) {
                page_error($exception->getMessage(), 503);
            }
            if ($errors && $userId) {
                $selectedUserId = (int) $userId;
                if ($action === 'edit') {
                    $editValues = [
                        'full_name' => (string) ($_POST['full_name'] ?? ''),
                        'email' => (string) ($_POST['email'] ?? ''),
                        'phone' => (string) ($_POST['phone'] ?? ''),
                    ];
                }
            }
        }
    }
}

$idFilter = trim((string) ($_GET['id'] ?? ''));
$nameFilter = trim((string) ($_GET['name'] ?? ''));
$emailFilter = trim((string) ($_GET['email'] ?? ''));
$phoneFilter = trim((string) ($_GET['phone'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$bookingsFilter = trim((string) ($_GET['bookings'] ?? ''));
$joinedFilter = trim((string) ($_GET['joined'] ?? ''));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? $page : 1;
$perPage = 30;
$filterValues = [
    'id' => $idFilter,
    'name' => $nameFilter,
    'email' => $emailFilter,
    'phone' => $phoneFilter,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'bookings' => $bookingsFilter,
    'joined' => $joinedFilter,
];
$filters = [];
$params = [];
if ($idFilter !== '') {
    $validId = filter_var($idFilter, FILTER_VALIDATE_INT);
    if ($validId === false || $validId < 1) {
        $errors[] = 'Enter a valid user ID.';
        $idFilter = '';
        $filterValues['id'] = '';
    } else {
        $filters[] = 'u.id = ?';
        $params[] = $validId;
    }
}
foreach (['name' => 'u.full_name', 'email' => 'u.email', 'phone' => 'u.phone'] as $filterKey => $column) {
    if ($filterValues[$filterKey] !== '') {
        $filters[] = $column . ' LIKE ?';
        $params[] = '%' . $filterValues[$filterKey] . '%';
    }
}
if ($bookingsFilter !== '') {
    $validBookings = filter_var($bookingsFilter, FILTER_VALIDATE_INT);
    if ($validBookings === false || $validBookings < 0) {
        $errors[] = 'Enter a non-negative whole number for bookings.';
        $bookingsFilter = '';
        $filterValues['bookings'] = '';
    } else {
        $filters[] = '((SELECT COUNT(*) FROM bookings b WHERE b.customer_id = u.id) +
                       (SELECT COUNT(*) FROM bookings b WHERE b.provider_id = u.id)) = ?';
        $params[] = $validBookings;
    }
}
if ($joinedFilter !== '') {
    $joinedDate = DateTime::createFromFormat('!Y-m-d', $joinedFilter);
    $dateErrors = DateTime::getLastErrors();
    if (!$joinedDate || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $joinedDate->format('Y-m-d') !== $joinedFilter) {
        $errors[] = 'Choose a valid joined date.';
        $joinedFilter = '';
        $filterValues['joined'] = '';
    } else {
        $filters[] = 'DATE(u.created_at) = ?';
        $params[] = $joinedFilter;
    }
}

try {
    $roleOptions = db()->query('SELECT slug, display_name FROM roles ORDER BY id')->fetchAll();
    $validRoleFilters = array_column($roleOptions, 'slug');
    if ($roleFilter !== '' && !in_array($roleFilter, $validRoleFilters, true)) {
        $errors[] = 'Choose a valid role filter.';
        $roleFilter = '';
        $filterValues['role'] = '';
    }
    if ($roleFilter !== '') {
        $filters[] = 'r.slug = ?';
        $params[] = $roleFilter;
    }
    if ($statusFilter !== '' && !in_array($statusFilter, ['active', 'inactive'], true)) {
        $errors[] = 'Choose a valid account status filter.';
        $statusFilter = '';
        $filterValues['status'] = '';
    }
    if ($statusFilter !== '') {
        $filters[] = 'u.is_active = ?';
        $params[] = $statusFilter === 'active' ? 1 : 0;
    }
    $where = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
    $countQuery = db()->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id' . $where);
    $countQuery->execute($params);
    $totalUsers = (int) $countQuery->fetchColumn();
    $totalPages = max(1, (int) ceil($totalUsers / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $usersQuery = db()->prepare(
        'SELECT u.id, u.full_name, u.phone, u.email, u.is_active, u.email_verified_at, u.created_at,
                r.slug AS role, r.display_name AS role_name,
                (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = u.id) +
                (SELECT COUNT(*) FROM bookings b WHERE b.provider_id = u.id) AS booking_count
         FROM users u JOIN roles r ON r.id = u.role_id' . $where .
        ' ORDER BY u.created_at DESC, u.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $usersQuery->execute($params);
    $users = $usersQuery->fetchAll();
    $selectedAccount = null;
    if ($selectedUserId) {
        $selectedQuery = db()->prepare(
            "SELECT u.id, u.full_name, u.phone, u.email, u.is_active, u.email_verified_at,
                    r.slug AS role, r.display_name AS role_name,
                    (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = u.id) +
                    (SELECT COUNT(*) FROM bookings b WHERE b.provider_id = u.id) AS booking_count
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?"
        );
        $selectedQuery->execute([$selectedUserId]);
        $selectedAccount = $selectedQuery->fetch();
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$pageTitle = 'Manage users';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">SUPER ADMIN · PLATFORM ACCOUNTS</div><h1>Manage users.</h1><p>Create student, parent, tutor, and home-service accounts, or manage existing users. Accounts with platform history should be deactivated rather than deleted.</p></div></div>
    <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <details class="admin-user-create" <?= $showCreateForm ? 'open' : '' ?>>
        <summary>Create a user account</summary>
        <section class="auth-card admin-create">
            <p class="form-footnote">The account is verified and can log in immediately. Share its temporary password securely. Provider profiles need documents and approval before appearing in listings.</p>
            <form method="post" class="form-stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="create">
                <label>Full name<input name="full_name" value="<?= e($accountCreateValues['full_name']) ?>" maxlength="160" required></label>
                <div class="form-row">
                    <label>Phone<input name="phone" type="tel" value="<?= e($accountCreateValues['phone']) ?>" required></label>
                    <label>Email<input name="email" type="email" value="<?= e($accountCreateValues['email']) ?>" maxlength="254" required></label>
                </div>
                <div class="form-row">
                    <label>Account type<select name="role" required>
                        <option value="student_parent" <?= $accountCreateValues['role'] === 'student_parent' ? 'selected' : '' ?>>Student / Parent</option>
                        <option value="customer" <?= $accountCreateValues['role'] === 'customer' ? 'selected' : '' ?>>Home services customer</option>
                        <option value="tutor" <?= $accountCreateValues['role'] === 'tutor' ? 'selected' : '' ?>>Tutor</option>
                        <option value="service_provider" <?= $accountCreateValues['role'] === 'service_provider' ? 'selected' : '' ?>>Home service provider</option>
                    </select></label>
                    <label>Temporary password<input name="password" type="password" minlength="12" autocomplete="new-password" required></label>
                </div>
                <details class="user-provider-create" <?= in_array($accountCreateValues['role'], ['tutor', 'service_provider'], true) ? 'open' : '' ?>>
                    <summary>Provider profile (for tutors and home service providers)</summary>
                    <p class="form-footnote">Complete this section only for a provider. Select subjects for tutors or home services for home providers; home providers must use physical service mode.</p>
                    <label>Qualifications or experience<textarea name="qualifications" rows="3" maxlength="5000"><?= e($accountCreateValues['qualifications']) ?></textarea></label>
                    <div class="form-row">
                        <label>Town / service area<input name="location" value="<?= e($accountCreateValues['location']) ?>" maxlength="180"></label>
                        <label>Service mode<select name="service_mode">
                            <option value="both" <?= $accountCreateValues['service_mode'] === 'both' ? 'selected' : '' ?>>Online & physical</option>
                            <option value="online" <?= $accountCreateValues['service_mode'] === 'online' ? 'selected' : '' ?>>Online only</option>
                            <option value="physical" <?= $accountCreateValues['service_mode'] === 'physical' ? 'selected' : '' ?>>Physical only</option>
                        </select></label>
                    </div>
                    <?php foreach (['tutoring' => 'Tutor subjects (tutors only)', 'home_services' => 'Home services (home providers only)'] as $module => $label): ?>
                        <fieldset class="category-picker"><legend><?= e($label) ?></legend><div class="category-checkbox-grid">
                            <?php foreach ($accountCategoryOptions as $category): ?>
                                <?php if ($category['module'] === $module): ?><label class="check-row"><input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>" <?= in_array((int) $category['id'], $accountCreateCategories, true) ? 'checked' : '' ?>><span><?= e($category['name']) ?></span></label><?php endif; ?>
                            <?php endforeach; ?>
                        </div></fieldset>
                    <?php endforeach; ?>
                    <fieldset class="category-picker"><legend>Levels the tutor can teach</legend><div class="category-checkbox-grid">
                        <?php foreach ($accountTeachingLevels as $level): ?><label class="check-row"><input type="checkbox" name="teaching_levels[]" value="<?= (int) $level['id'] ?>" <?= in_array((int) $level['id'], $accountCreateLevels, true) ? 'checked' : '' ?>><span><?= e($level['name']) ?></span></label><?php endforeach; ?>
                    </div></fieldset>
                </details>
                <button class="button button-primary" type="submit">Create account</button>
            </form>
        </section>
    </details>
    <section class="user-management">
        <?php if ($selectedUserId): ?>
            <?php if (!$selectedAccount): ?>
                <div class="notice notice-error">That user account could not be found.</div>
            <?php elseif ($selectedAccount['role'] === 'super_admin' || (int) $selectedAccount['id'] === (int) current_user()['id']): ?>
                <div class="notice">This account is protected and cannot be managed here.</div>
            <?php else: ?>
                <?php $formValues = $editValues ?? $selectedAccount; ?>
                <section class="user-manage-panel">
                    <div class="user-manage-panel-header">
                        <div><div class="eyebrow">USER ACCOUNT · #<?= (int) $selectedAccount['id'] ?></div><h2>Manage <?= e($selectedAccount['full_name']) ?></h2></div>
                        <a class="button button-outline button-small" href="<?= e(url('Admin/manage_users.php?' . http_build_query(array_merge($filterValues, ['page' => $page])))) ?>">Back to users</a>
                    </div>
                    <dl class="user-manage-facts">
                        <div><dt>Role</dt><dd><?= e($selectedAccount['role_name']) ?></dd></div>
                        <div><dt>Status</dt><dd><?= $selectedAccount['is_active'] ? 'Active' : 'Inactive' ?></dd></div>
                        <div><dt>Email verified</dt><dd><?= $selectedAccount['email_verified_at'] ? 'Yes' : 'No' ?></dd></div>
                        <div><dt>Booking history</dt><dd><?= number_format((int) $selectedAccount['booking_count']) ?> booking(s)</dd></div>
                    </dl>
                    <form method="post" class="user-edit-form">
                        <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="user_id" value="<?= (int) $selectedAccount['id'] ?>">
                        <label>Full name<input name="full_name" maxlength="160" required value="<?= e($formValues['full_name']) ?>"></label>
                        <label>Email<input name="email" type="email" maxlength="254" required value="<?= e($formValues['email']) ?>"></label>
                        <label>Phone<input name="phone" maxlength="32" required value="<?= e($formValues['phone']) ?>"></label>
                        <button class="button button-primary button-small" type="submit">Save changes</button>
                    </form>
                    <div class="user-management-actions">
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int) $selectedAccount['id'] ?>"><input type="hidden" name="is_active" value="<?= $selectedAccount['is_active'] ? '0' : '1' ?>"><button class="button button-outline button-small" type="submit"><?= $selectedAccount['is_active'] ? 'Deactivate account' : 'Activate account' ?></button></form>
                        <form method="post" data-confirm="Permanently delete this user? Accounts with related platform records cannot be deleted."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $selectedAccount['id'] ?>"><button class="button button-outline button-small user-delete-button" type="submit">Delete account</button></form>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
        <form class="user-filter-grid" method="get">
            <label for="user-id-filter">User ID<input id="user-id-filter" name="id" type="number" min="1" step="1" value="<?= e($idFilter) ?>"></label>
            <label for="user-name-filter">Name<input id="user-name-filter" name="name" type="search" maxlength="160" value="<?= e($nameFilter) ?>"></label>
            <label for="user-email-filter">Email<input id="user-email-filter" name="email" type="search" maxlength="254" value="<?= e($emailFilter) ?>"></label>
            <label for="user-phone-filter">Phone<input id="user-phone-filter" name="phone" type="search" maxlength="32" value="<?= e($phoneFilter) ?>"></label>
            <label for="user-role-filter">Role<select id="user-role-filter" name="role">
                    <option value="">All roles</option>
                    <?php foreach ($roleOptions as $roleOption): ?>
                        <option value="<?= e($roleOption['slug']) ?>" <?= $roleFilter === $roleOption['slug'] ? 'selected' : '' ?>><?= e($roleOption['display_name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label for="user-status-filter">Status<select id="user-status-filter" name="status">
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select></label>
            <label for="user-bookings-filter">Bookings<input id="user-bookings-filter" name="bookings" type="number" min="0" step="1" value="<?= e($bookingsFilter) ?>"></label>
            <label for="user-joined-filter">Joined<input id="user-joined-filter" name="joined" type="date" value="<?= e($joinedFilter) ?>"></label>
            <div class="user-filter-actions">
                <button class="button button-primary button-small" type="submit">Apply filters</button>
                <?php if (array_filter($filterValues, static fn(string $value): bool => $value !== '')): ?><a class="button button-outline button-small" href="<?= e(url('Admin/manage_users.php')) ?>">Clear filters</a><?php endif; ?>
                <span class="count-pill"><?= number_format($totalUsers) ?> users</span>
            </div>
        </form>
        <div class="table-wrap">
            <table class="data-table user-management-table">
                <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Bookings</th><th>Joined</th><th>Manage</th></tr></thead>
                <tbody>
                <?php foreach ($users as $account): ?>
                    <tr>
                        <td><strong><?= e($account['full_name']) ?></strong><small>#<?= (int) $account['id'] ?> · <?= e($account['email']) ?> · <?= e($account['phone']) ?></small></td>
                        <td><?= e($account['role_name']) ?></td>
                        <td><span class="status <?= $account['is_active'] ? 'status-accepted' : 'status-suspended' ?>"><?= $account['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td><?= number_format((int) $account['booking_count']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($account['created_at']))) ?></td>
                        <td>
                            <?php if ($account['role'] === 'super_admin' || (int) $account['id'] === (int) current_user()['id']): ?>
                                <span class="user-protected">Protected</span>
                            <?php else: ?>
                                <a class="user-manage-trigger" href="<?= e(url('Admin/manage_users.php?' . http_build_query(array_merge($filterValues, ['user' => (int) $account['id'], 'page' => $page])))) ?>">View & manage</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="6"><div class="empty-state compact"><h3>No users found.</h3><p>Try a different name, email or phone number.</p></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="user-pagination" aria-label="User list pages">
                <?php if ($page > 1): ?><a class="button button-outline button-small" href="<?= e(url('Admin/manage_users.php?' . http_build_query(array_merge($filterValues, ['page' => $page - 1])))) ?>">Previous</a><?php endif; ?>
                <span>Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?><a class="button button-outline button-small" href="<?= e(url('Admin/manage_users.php?' . http_build_query(array_merge($filterValues, ['page' => $page + 1])))) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
