<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$adminLogin = $adminLogin ?? false;
if (current_user()) {
    if ($adminLogin) {
        unset($_SESSION['pending_service_id'], $_SESSION['pending_provider_id'], $_SESSION['pending_booking_details'], $_SESSION['return_to']);
        header('Location: ' . url('Pages/dashboard.php'));
        exit;
    }
    redirect_after_login();
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!$adminLogin) {
        $pendingService = filter_var($_POST['pending_service_id'] ?? null, FILTER_VALIDATE_INT);
        $pendingProvider = filter_var($_POST['pending_provider_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pendingService && $pendingProvider) {
            $_SESSION['pending_service_id'] = (int) $pendingService;
            $_SESSION['pending_provider_id'] = (int) $pendingProvider;
        }
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter your email address and password.';
    } else {
        try {
            $query = db()->prepare('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1');
            $query->execute([$email]);
            $user = $query->fetch();
            if (!$user || !password_verify($password, $user['password_hash']) || !(int) $user['is_active']) {
                $error = 'Those details do not match an active account.';
            } elseif ($adminLogin && !in_array($user['role_slug'], ['super_admin', 'admin1', 'admin2'], true)) {
                $error = 'This sign-in is for administrators only. Use the regular LertFind login instead.';
            } elseif (!$user['email_verified_at']) {
                $error = 'Verify your email before logging in. Check your inbox or request a new verification link below.';
            } else {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $update = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $update->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
                login_user($user);
                flash('success', 'Welcome back, ' . $user['full_name'] . '.');
                if ($adminLogin) {
                    unset($_SESSION['pending_service_id'], $_SESSION['pending_provider_id'], $_SESSION['pending_booking_details'], $_SESSION['return_to']);
                    header('Location: ' . url('Pages/dashboard.php'));
                    exit;
                }
                redirect_after_login();
            }
        } catch (RuntimeException $exception) {
            page_error($exception->getMessage(), 503);
        }
    }
}

$pageTitle = $adminLogin ? 'Administrator login' : 'Log in';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap auth-wrap">
    <section class="auth-aside">
        <div class="eyebrow eyebrow-light">YOUR NEXT STEP STARTS HERE</div>
        <h1>Good to have<br>you <span>back.</span></h1>
        <p>Your trusted people, new opportunities, and every booking — all in one place.</p>
        <div class="aside-note">One platform. One account.</div>
    </section>
    <section class="auth-card">
        <div class="eyebrow"><?= $adminLogin ? 'ADMINISTRATOR ACCESS' : 'WELCOME BACK' ?></div>
        <h2><?= $adminLogin ? 'Admin sign in' : 'Log in to LertFind' ?></h2>
        <p class="auth-intro"><?= $adminLogin ? 'Sign in with your Super Admin, Admin 1, or Admin 2 account.' : 'Pick up right where you left off.' ?></p>
        <?php if ($error): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack" data-login-form>
            <?= csrf_field() ?>
            <input type="hidden" name="pending_service_id" value="">
            <input type="hidden" name="pending_provider_id" value="">
            <label>Email address<input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required></label>
            <label>Password<span class="password-input-wrap"><input type="password" name="password" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true" data-eye-open><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg viewBox="0 0 24 24" aria-hidden="true" data-eye-closed hidden><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.4 3.8-.9"/></svg></button></span></label>
            <button class="button button-primary button-full" type="submit">Log in</button>
        </form>
        <?php if ($adminLogin): ?>
            <p class="auth-switch"><a href="<?= e(url('Pages/login.php')) ?>">Go to regular LertFind login</a></p>
        <?php else: ?>
            <p class="auth-switch">New to LertFind? <a href="<?= e(url('Pages/register.php')) ?>">Create an account</a></p>
            <p class="form-footnote"><a href="<?= e(url('Pages/resend_verification.php')) ?>">Need a new verification email?</a></p>
            <p class="form-footnote">Your booking selection is saved when you sign in.</p>
        <?php endif; ?>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
