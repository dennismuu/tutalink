<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        $pdo = db();
        if ($action === 'create') {
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $phone = preg_replace('/[\s()+-]/', '', (string) ($_POST['phone'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $role = (string) ($_POST['role'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{7,15}$/', (string) $phone) || !in_array($role, ['admin1', 'admin2'], true) || strlen($password) < 12) {
                $errors[] = 'Enter a name, valid email and phone, choose an admin role, and use a password of at least 12 characters.';
            } else {
                $pdo->beginTransaction();
                $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
                $roleQuery->execute([$role]);
                $roleId = (int) $roleQuery->fetchColumn();
                $pdo->prepare('INSERT INTO users (role_id, `role`, full_name, phone, email, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$roleId, $role, $name, $phone, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $adminId = (int) $pdo->lastInsertId();
                foreach (['tutoring', 'home_services'] as $module) {
                    $permissions = [];
                    foreach (['manage_providers', 'manage_bookings', 'manage_payments', 'handle_complaints', 'manage_services', 'view_reports'] as $permission) {
                        $permissions[$permission] = isset($_POST['permissions'][$module][$permission]) ? 1 : 0;
                    }
                    $pdo->prepare('INSERT INTO admin_permissions (user_id, module, manage_providers, manage_bookings, manage_payments, handle_complaints, manage_services, view_reports) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$adminId, $module, ...array_values($permissions)]);
                }
                $pdo->commit();
                flash('success', 'Administrator account created with the selected permissions.');
                header('Location: ' . url('Admin/users.php'));
                exit;
            }
        } elseif ($action === 'permissions') {
            $adminId = filter_var($_POST['admin_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$adminId || $adminId === (int) current_user()['id']) {
                $errors[] = 'Choose a valid administrator account.';
            } else {
                $check = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.slug IN ('admin1','admin2')");
                $check->execute([$adminId]);
                if (!$check->fetchColumn()) {
                    $errors[] = 'That account is not an administrator.';
                } else {
                    $pdo->beginTransaction();
                    foreach (['tutoring', 'home_services'] as $module) {
                        $permissions = [];
                        foreach (['manage_providers', 'manage_bookings', 'manage_payments', 'handle_complaints', 'manage_services', 'view_reports'] as $permission) {
                            $permissions[$permission] = isset($_POST['permissions'][$module][$permission]) ? 1 : 0;
                        }
                        $pdo->prepare(
                            'INSERT INTO admin_permissions (user_id, module, manage_providers, manage_bookings, manage_payments, handle_complaints, manage_services, view_reports)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE manage_providers = VALUES(manage_providers), manage_bookings = VALUES(manage_bookings), manage_payments = VALUES(manage_payments), handle_complaints = VALUES(handle_complaints), manage_services = VALUES(manage_services), view_reports = VALUES(view_reports)'
                        )->execute([$adminId, $module, ...array_values($permissions)]);
                    }
                    $pdo->commit();
                    flash('success', 'Administrator permissions updated.');
                    header('Location: ' . url('Admin/users.php'));
                    exit;
                }
            }
        } elseif ($action === 'delete') {
            $adminId = filter_var($_POST['admin_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$adminId || $adminId === (int) current_user()['id']) {
                $errors[] = 'You cannot delete your own account.';
            } else {
                $delete = $pdo->prepare("DELETE u FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.slug IN ('admin1','admin2')");
                $delete->execute([$adminId]);
                flash($delete->rowCount() ? 'success' : 'error', $delete->rowCount() ? 'Administrator account deleted.' : 'That administrator account could not be found.');
                header('Location: ' . url('Admin/users.php'));
                exit;
            }
        } else {
            $errors[] = 'That action is not available.';
        }
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $exception->getCode() === '23000' ? 'An account already uses that email address.' : 'The administrator update could not be saved.';
    } catch (RuntimeException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        page_error($exception->getMessage(), 503);
    }
}

$permissionsList = ['manage_providers' => 'Manage providers', 'manage_bookings' => 'Manage bookings', 'manage_payments' => 'View payments', 'handle_complaints' => 'Handle complaints', 'manage_services' => 'Manage services', 'view_reports' => 'View reports'];
try {
    $admins = db()->query(
        "SELECT u.id, u.full_name, u.phone, u.email, u.is_active, r.slug AS role
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.slug IN ('admin1','admin2') ORDER BY u.created_at DESC"
    )->fetchAll();
    $permissionQuery = db()->query('SELECT * FROM admin_permissions');
    $allPermissions = [];
    foreach ($permissionQuery->fetchAll() as $permission) $allPermissions[$permission['user_id']][$permission['module']] = $permission;
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
$pageTitle = 'Manage administrators';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">SUPER ADMIN · ACCESS CONTROL</div><h1>Administrator access.</h1><p>Create admin accounts and give each person access only to the modules they manage.</p></div></div>
    <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="admin-columns">
        <section class="auth-card admin-create"><div class="eyebrow">NEW ADMINISTRATOR</div><h2>Create a restricted account</h2>
            <form method="post" class="form-stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="create">
                <label>Full name<input name="full_name" required maxlength="160"></label>
                <div class="form-row"><label>Phone<input name="phone" type="tel" required></label><label>Email<input name="email" type="email" required></label></div>
                <div class="form-row"><label>Admin role<select name="role" required><option value="admin1">Admin 1</option><option value="admin2">Admin 2</option></select></label><label>Temporary password<input name="password" type="password" minlength="12" required autocomplete="new-password"></label></div>
                <div class="permission-grid">
                    <?php foreach (['tutoring' => 'Tutoring', 'home_services' => 'Home services'] as $module => $label): ?>
                        <fieldset><legend><?= e($label) ?></legend><?php foreach ($permissionsList as $key => $permission): ?><label class="check-row"><input type="checkbox" name="permissions[<?= e($module) ?>][<?= e($key) ?>]" value="1"><span><?= e($permission) ?></span></label><?php endforeach; ?></fieldset>
                    <?php endforeach; ?>
                </div>
                <button class="button button-primary button-full" type="submit">Create admin account</button>
            </form>
        </section>
        <section class="admin-list"><div class="section-title"><div><div class="eyebrow">CURRENT ADMINS</div><h2>Access & permissions</h2></div><span class="count-pill"><?= count($admins) ?> admins</span></div>
            <?php if (!$admins): ?><div class="empty-state compact"><h3>No admin accounts yet.</h3><p>Create a restricted administrator using the form.</p></div><?php endif; ?>
            <?php foreach ($admins as $admin): ?>
                <article class="admin-user-card"><div class="admin-user-heading"><div class="provider-avatar"><?= e(mb_strtoupper(mb_substr($admin['full_name'], 0, 1))) ?></div><div><h3><?= e($admin['full_name']) ?></h3><p><?= e(ucwords($admin['role'])) ?> · <?= e($admin['email']) ?></p></div><span class="status status-accepted"><?= $admin['is_active'] ? 'Active' : 'Inactive' ?></span></div>
                    <form method="post" class="admin-permission-form"><?= csrf_field() ?><input type="hidden" name="action" value="permissions"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                        <div class="permission-grid"><?php foreach (['tutoring' => 'Tutoring', 'home_services' => 'Home services'] as $module => $label): ?><fieldset><legend><?= e($label) ?></legend><?php foreach ($permissionsList as $key => $permission): ?><label class="check-row"><input type="checkbox" name="permissions[<?= e($module) ?>][<?= e($key) ?>]" value="1" <?= !empty($allPermissions[$admin['id']][$module][$key]) ? 'checked' : '' ?>><span><?= e($permission) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?></div>
                        <div class="inline-actions"><button class="button button-outline button-small" type="submit">Save permissions</button></div>
                    </form>
                    <form method="post" class="delete-admin-form" data-confirm="Delete this administrator account? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>"><button class="text-danger" type="submit">Delete admin</button></form>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
