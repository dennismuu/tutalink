<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');

$modules = [];
foreach (['tutoring', 'home_services'] as $module) {
    if (admin_has_permission($module, 'manage_bookings')) $modules[] = $module;
}
if (current_user()['role'] === 'super_admin') $modules = ['tutoring', 'home_services'];
if (!$modules) page_error('Your administrator account is not assigned to booking conversations.', 403);

$bookingId = filter_var($_GET['booking'] ?? null, FILTER_VALIDATE_INT);
if (isset($_GET['booking']) && (!$bookingId || $bookingId < 1)) {
    page_error('Choose a valid conversation.', 404);
}
$conversation = null;
$messages = [];

try {
    $moduleMarks = implode(',', array_fill(0, count($modules), '?'));
    if ($bookingId) {
        $conversationQuery = db()->prepare(
            "SELECT b.id, b.status, c.module, c.name AS service_name,
                    customer.full_name AS customer_name, provider.full_name AS provider_name
             FROM bookings b
             JOIN service_categories c ON c.id = b.category_id
             JOIN users customer ON customer.id = b.customer_id
             JOIN users provider ON provider.id = b.provider_id
             WHERE b.id = ? AND c.module IN ({$moduleMarks})
               AND EXISTS (SELECT 1 FROM messages m WHERE m.booking_id = b.id)"
        );
        $conversationQuery->execute([$bookingId, ...$modules]);
        $conversation = $conversationQuery->fetch();
        if (!$conversation) page_error('That conversation was not found or is outside your assigned modules.', 404);

        db()->prepare(
            'INSERT INTO admin_message_reads (admin_id, booking_id, last_read_message_id)
             SELECT ?, ?, COALESCE(MAX(id), 0) FROM messages WHERE booking_id = ?
             ON DUPLICATE KEY UPDATE
                last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([current_user()['id'], $bookingId, $bookingId]);

        $messagesQuery = db()->prepare(
            'SELECT m.body, m.created_at, m.sender_id, u.full_name AS sender_name
             FROM messages m JOIN users u ON u.id = m.sender_id
             WHERE m.booking_id = ? ORDER BY m.id'
        );
        $messagesQuery->execute([$bookingId]);
        $messages = $messagesQuery->fetchAll();
    } else {
        $inboxQuery = db()->prepare(
            "SELECT b.id AS booking_id, b.status, c.module, c.name AS service_name,
                    customer.full_name AS customer_name, provider.full_name AS provider_name,
                    latest.body AS latest_body, latest.created_at AS latest_created_at,
                    latest.sender_id AS latest_sender_id,
                    (SELECT COUNT(*) FROM messages unread
                     WHERE unread.booking_id = b.id
                       AND unread.id > COALESCE(r.last_read_message_id, 0)) AS unread_count
             FROM bookings b
             JOIN service_categories c ON c.id = b.category_id
             JOIN users customer ON customer.id = b.customer_id
             JOIN users provider ON provider.id = b.provider_id
             JOIN messages latest ON latest.id = (
                 SELECT MAX(m.id) FROM messages m WHERE m.booking_id = b.id
             )
             LEFT JOIN admin_message_reads r
               ON r.admin_id = ? AND r.booking_id = b.id
             WHERE c.module IN ({$moduleMarks})
             ORDER BY latest.id DESC
             LIMIT 100"
        );
        $inboxQuery->execute([current_user()['id'], ...$modules]);
        $conversations = $inboxQuery->fetchAll();
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
} catch (PDOException $exception) {
    page_error('We could not load booking messages. Please try again.');
}

$pageTitle = $conversation ? 'Conversation · ' . $conversation['service_name'] : 'Messages';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <?php if ($conversation): ?>
        <div class="breadcrumb"><a href="<?= e(url('Admin/messages.php')) ?>">← All messages</a></div>
        <div class="page-heading"><div><div class="eyebrow"><?= $conversation['module'] === 'tutoring' ? 'TUTORING' : 'HOME SERVICES' ?> · BOOKING #<?= (int) $conversation['id'] ?></div><h1><?= e($conversation['service_name']) ?></h1><p><?= e($conversation['customer_name']) ?> ↔ <?= e($conversation['provider_name']) ?> · <?= e(ucwords(str_replace('_', ' ', $conversation['status']))) ?></p></div></div>
        <section class="admin-conversation">
            <?php if (!$messages): ?><p class="muted-copy">There are no messages in this conversation.</p><?php endif; ?>
            <?php foreach ($messages as $message): ?>
                <article class="admin-message">
                    <div><strong><?= e($message['sender_name']) ?></strong><time><?= e(date('M j, Y · g:i a', strtotime($message['created_at']))) ?></time></div>
                    <p><?= nl2br(e($message['body'])) ?></p>
                </article>
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <div class="page-heading"><div><div class="eyebrow">BOOKING CONVERSATIONS</div><h1>Messages.</h1><p>Customer and provider conversations in the booking modules you manage. Opening a conversation marks its current messages as read for your account.</p></div></div>
        <section class="admin-inbox">
            <?php if (!$conversations): ?>
                <div class="empty-state"><h3>No conversations yet.</h3><p>Booking conversations will appear here when customers and providers exchange messages.</p></div>
            <?php else: ?>
                <?php foreach ($conversations as $item): ?>
                    <a class="admin-inbox-item<?= (int) $item['unread_count'] > 0 ? ' unread' : '' ?>" href="<?= e(url('Admin/messages.php?booking=' . (int) $item['booking_id'])) ?>">
                        <span class="admin-inbox-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($item['customer_name'], 0, 1))) ?></span>
                        <span class="admin-inbox-copy">
                            <span class="admin-inbox-heading"><strong><?= e($item['service_name']) ?> · <?= e($item['customer_name']) ?> &amp; <?= e($item['provider_name']) ?></strong><time><?= e(date('M j · g:i a', strtotime($item['latest_created_at']))) ?></time></span>
                            <span class="admin-inbox-module"><?= $item['module'] === 'tutoring' ? 'Tutoring' : 'Home services' ?> · Booking #<?= (int) $item['booking_id'] ?></span>
                            <span class="admin-inbox-preview"><?= e($item['latest_body']) ?></span>
                        </span>
                        <?php if ((int) $item['unread_count'] > 0): ?><span class="admin-inbox-badge" aria-label="<?= (int) $item['unread_count'] ?> unread messages"><?= (int) $item['unread_count'] > 99 ? '99+' : number_format((int) $item['unread_count']) ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
