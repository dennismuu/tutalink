<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
$inlineConversation = ($_GET['inline'] ?? '') === '1';
if (!is_role('student_parent', 'customer', 'tutor', 'service_provider')) {
    page_error('Messaging is only available to customers and service providers.', 403);
}
$requestData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$bookingId = filter_var($requestData['booking'] ?? null, FILTER_VALIDATE_INT);
if (!$bookingId) page_error('Choose a booking first.', 404);
$errors = [];
$body = trim((string) ($_POST['body'] ?? ''));
try {
    $pdo = db();
    $bookingQuery = $pdo->prepare(
        'SELECT b.id, b.customer_id, b.provider_id, b.status, c.name AS service_name,
                customer.full_name AS customer_name, provider.full_name AS provider_name
         FROM bookings b JOIN service_categories c ON c.id = b.category_id
         JOIN users customer ON customer.id = b.customer_id
         JOIN users provider ON provider.id = b.provider_id
         WHERE b.id = ? AND (b.customer_id = ? OR b.provider_id = ?)'
    );
    $bookingQuery->execute([$bookingId, current_user()['id'], current_user()['id']]);
    $booking = $bookingQuery->fetch();
    if (!$booking) page_error('You can only view conversations for your own bookings.', 404);
    $paidQuery = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'paid'");
    $paidQuery->execute([$bookingId]);
    if (!in_array($booking['status'], ['requested', 'paid', 'on_the_way', 'in_progress', 'completed'], true) || (int) $paidQuery->fetchColumn() === 0) {
        page_error('The conversation becomes available after the booking payment is confirmed.', 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        reject_invalid_csrf();
        if ($body === '' || mb_strlen($body) > 4000) {
            $errors[] = 'Write a message up to 4,000 characters.';
        } elseif (in_array($booking['status'], ['cancelled'], true)) {
            $errors[] = 'This booking conversation is closed.';
        } else {
            $recipient = (int) $booking['customer_id'] === (int) current_user()['id']
                ? (int) $booking['provider_id']
                : (int) $booking['customer_id'];
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO messages (booking_id, sender_id, body) VALUES (?, ?, ?)')
                ->execute([$bookingId, current_user()['id'], $body]);
            $subject = 'New message about ' . $booking['service_name'];
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', ?, ?)")
                ->execute([$recipient, $bookingId, $subject, current_user()['name'] . ': ' . $body]);
            $emailQuery = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $emailQuery->execute([$recipient]);
            $recipientEmail = $emailQuery->fetchColumn();
            $pdo->commit();
            mail((string) $recipientEmail, 'New LertFind booking message', current_user()['name'] . ' sent a message about ' . $booking['service_name'] . ":\n\n" . $body, "From: LertFind <no-reply@tutalink.co.ke>\r\n");
            header('Location: ' . url('Pages/messages.php?booking=' . (int) $bookingId . ($inlineConversation ? '&inline=1' : '')));
            exit;
        }
    }

    $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND booking_id = ? AND channel = \'in_app\'')
        ->execute([current_user()['id'], $bookingId]);
    $messageQuery = $pdo->prepare(
        'SELECT m.body, m.created_at, m.sender_id, u.full_name AS sender_name
         FROM messages m JOIN users u ON u.id = m.sender_id
         WHERE m.booking_id = ? ORDER BY m.created_at, m.id LIMIT 200'
    );
    $messageQuery->execute([$bookingId]);
    $messages = $messageQuery->fetchAll();
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    page_error($exception->getMessage(), 503);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    page_error('We could not load this conversation. Please try again.');
}

$pageTitle = 'Booking conversation';
if ($inlineConversation): ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($pageTitle) ?></title>
        <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
        <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
    </head>
    <body class="inline-message-page">
<?php else:
    require dirname(__DIR__) . '/includes/header.php';
endif;
?>
<main class="<?= $inlineConversation ? 'inline-conversation-wrap' : 'container page-wrap narrow-wrap' ?>">
    <section class="conversation-card<?= $inlineConversation ? ' inline-conversation-card' : '' ?>">
        <?php if (!$inlineConversation): ?><div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div><?php endif; ?>
        <div class="eyebrow">BOOKING CONVERSATION</div>
        <h1><?= e($booking['service_name']) ?></h1>
        <p class="auth-intro"><?= e($booking['customer_name']) ?> <span>↔</span> <?= e($booking['provider_name']) ?> · <?= e(str_replace('_', ' ', $booking['status'])) ?></p>
        <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="message-thread" aria-live="polite">
            <?php if (!$messages): ?><div class="thread-empty"><p>Start the conversation. Keep messages clear and related to this booking.</p></div><?php endif; ?>
            <?php foreach ($messages as $message): $mine = (int) $message['sender_id'] === (int) current_user()['id']; ?>
                <article class="message-bubble <?= $mine ? 'message-mine' : 'message-theirs' ?>">
                    <span><?= $mine ? 'You' : e($message['sender_name']) ?></span>
                    <p><?= nl2br(e($message['body'])) ?></p>
                    <time><?= e(date('M j · g:i a', strtotime($message['created_at']))) ?></time>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!in_array($booking['status'], ['cancelled'], true)): ?>
            <form method="post" class="message-compose"><?= csrf_field() ?><input type="hidden" name="booking" value="<?= (int) $bookingId ?>"><label class="sr-only" for="message-body">Your message</label><textarea id="message-body" name="body" rows="3" maxlength="4000" required placeholder="Write a message…"><?= e($body) ?></textarea><button class="button button-primary" type="submit">Send message</button></form>
        <?php else: ?><p class="form-footnote">This conversation is closed because the booking was cancelled.</p><?php endif; ?>
    </section>
</main>
<?php if ($inlineConversation): ?>
    </body>
    </html>
<?php else: require dirname(__DIR__) . '/includes/footer.php'; endif; ?>
