<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$token = (string) ($_GET['token'] ?? '');
$verified = false;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $query = $pdo->prepare('SELECT id, user_id FROM email_verifications WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
        $query->execute([hash('sha256', $token)]);
        $record = $query->fetch();
        if ($record) {
            $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')->execute([$record['user_id']]);
            $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$record['id']]);
            $verified = true;
        }
        $pdo->commit();
    } catch (PDOException | RuntimeException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        page_error('We could not verify your email. Please try again or ask for a new verification link.');
    }
}
$pageTitle = 'Verify your email';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <section class="auth-card success-card">
        <div class="eyebrow"><?= $verified ? 'YOU’RE ALL SET' : 'LINK NOT AVAILABLE' ?></div>
        <h1><?= $verified ? 'Email verified.' : 'This link has expired.' ?></h1>
        <p><?= $verified ? 'Your email has been verified. You can now log in to continue to LertFind.' : 'Verification links can only be used once and expire after 24 hours. Request a fresh link to verify your account.' ?></p>
        <a class="button button-primary button-full" href="<?= e(url($verified ? 'Pages/login.php' : 'Pages/resend_verification.php')) ?>"><?= $verified ? 'Proceed to login' : 'Request a new verification email' ?></a>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
