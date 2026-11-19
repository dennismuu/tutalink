<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_user()) {
    redirect_after_login();
}

$email = '';
$submitted = false;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } else {
        try {
            $pdo = db();
            $query = $pdo->prepare('SELECT id, full_name FROM users WHERE email = ? AND email_verified_at IS NULL AND is_active = 1 LIMIT 1');
            $query->execute([$email]);
            $user = $query->fetch();
            if ($user) {
                $recentToken = $pdo->prepare(
                    'SELECT COUNT(*) FROM email_verifications
                     WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
                );
                $recentToken->execute([$user['id']]);
                if ((int) $recentToken->fetchColumn() === 0) {
                    $token = bin2hex(random_bytes(32));
                    $verificationUrl = app_absolute_url('Pages/verify.php?token=' . rawurlencode($token));
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                        ->execute([$user['id']]);
                    $pdo->prepare(
                        'INSERT INTO email_verifications (user_id, token_hash, expires_at)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
                    )->execute([$user['id'], hash('sha256', $token)]);
                    $pdo->commit();
                    $sent = send_app_email(
                        $email,
                        'Verify your LertFind account',
                        "Hello {$user['full_name']},\n\nVerify your email address to activate your LertFind account:\n{$verificationUrl}\n\nThis link expires in 24 hours."
                    );
                    if (!$sent) {
                        error_log('LertFind verification email could not be resent for user ID ' . $user['id'] . '. Check the server mail transport and sender configuration.');
                    }
                }
            }
            $submitted = true;
        } catch (PDOException | RuntimeException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            page_error('We could not process your verification request. Please try again later.', 503);
        }
    }
}

$pageTitle = 'Resend verification email';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <section class="auth-card">
        <div class="eyebrow">EMAIL VERIFICATION</div>
        <h1>Get a new verification link.</h1>
        <?php if ($submitted): ?>
            <p class="notice notice-info">If an active, unverified account uses that email, we attempted to send a fresh verification link. Check your inbox and spam folder. If it does not arrive, contact support or request another link after one minute.</p>
        <?php else: ?>
            <p class="auth-intro">Enter the email address used when you registered.</p>
            <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" class="form-stack">
                <?= csrf_field() ?>
                <label>Email address<input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required maxlength="254"></label>
                <button class="button button-primary button-full" type="submit">Send verification link</button>
            </form>
        <?php endif; ?>
        <p class="auth-switch"><a href="<?= e(url('Pages/login.php')) ?>">Return to login</a></p>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
