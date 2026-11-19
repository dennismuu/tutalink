<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/paystack.php';
require_login();
if (!is_role('student_parent', 'customer')) page_error('This checkout is not available for your account.', 403);

$orderId = filter_var($_GET['order'] ?? $_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId) page_error('Choose a valid checkout.', 404);

try {
    $pdo = db();
    $orderQuery = $pdo->prepare(
        'SELECT o.*, provider.full_name AS provider_name, provider.email AS provider_email
         FROM checkout_orders o
         JOIN users provider ON provider.id = o.provider_id
         WHERE o.id = ? AND o.customer_id = ?'
    );
    $orderQuery->execute([$orderId, current_user()['id']]);
    $order = $orderQuery->fetch();
    if (!$order) page_error('This checkout could not be found.', 404);
    if ($order['module'] === 'tutoring') {
        page_error('Tutors must accept tutoring requests before payment is available. Pay accepted bookings from your dashboard.', 409);
    }
    if ($order['module'] === 'home_services') {
        flash('error', 'Home-service requests must be accepted by the provider before payment is available.');
        header('Location: ' . url('Pages/dashboard.php'));
        exit;
    }
    if ($order['status'] !== 'awaiting_payment') {
        flash('info', 'This checkout has already been paid or closed.');
        header('Location: ' . url('Pages/dashboard.php'));
        exit;
    }

    $itemsQuery = $pdo->prepare(
        'SELECT b.id, b.status, b.quoted_amount, b.commission_amount, b.provider_amount,
                b.laundry_baskets, b.booked_service_price, c.name AS service_name, c.slug
         FROM bookings b JOIN service_categories c ON c.id = b.category_id
         WHERE b.checkout_order_id = ? AND b.customer_id = ?
         ORDER BY b.id'
    );
    $itemsQuery->execute([$orderId, current_user()['id']]);
    $items = $itemsQuery->fetchAll();
    if (!$items || count(array_filter($items, static fn(array $item): bool => $item['status'] !== 'awaiting_payment')) > 0) {
        page_error('The bookings in this checkout are no longer waiting for payment.', 409);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        reject_invalid_csrf();
        $amountCents = (int) round((float) $order['total_amount'] * 100);
        $commissionCents = (int) round((float) $order['commission_amount'] * 100);
        $providerCents = (int) round((float) $order['provider_amount'] * 100);
        $itemsAmountCents = array_sum(array_map(static fn(array $item): int => (int) round((float) $item['quoted_amount'] * 100), $items));
        if ($amountCents <= 0 || $amountCents !== $itemsAmountCents
            || $commissionCents < 0 || $providerCents <= 0
            || $commissionCents + $providerCents !== $amountCents) {
            page_error('This checkout amount could not be validated. Contact LertFind support.', 409);
        }

        if (APP_ENV === 'local' && !paystack_checkout_ready(null, false)) {
            $demoMethod = (string) ($_POST['demo_method'] ?? '');
            $demoMethods = [
                'mpesa' => 'M-Pesa/mobile money',
                'card' => 'card',
                'bank_transfer' => 'bank transfer',
            ];
            if (!isset($demoMethods[$demoMethod])) {
                page_error('Choose a simulated payment method.', 400);
            }
            $reference = 'TLK-DEMO-' . date('ymd') . '-' . bin2hex(random_bytes(12));
            $pdo->beginTransaction();
            $lockedOrder = $pdo->prepare('SELECT status FROM checkout_orders WHERE id = ? AND customer_id = ? FOR UPDATE');
            $lockedOrder->execute([$orderId, current_user()['id']]);
            if ($lockedOrder->fetchColumn() !== 'awaiting_payment') {
                $pdo->rollBack();
                flash('info', 'This checkout has already been paid or closed.');
                header('Location: ' . url('Pages/dashboard.php'));
                exit;
            }
            $insertPayment = $pdo->prepare(
                "INSERT INTO payments (booking_id, gateway, gateway_reference, reference, amount, commission_amount, provider_amount, status, paid_at, payout_mode)
                 VALUES (?, 'demo', ?, ?, ?, ?, ?, 'paid', NOW(), ?)"
            );
            $updateBooking = $pdo->prepare("UPDATE bookings SET status = 'requested' WHERE id = ? AND status = 'awaiting_payment'");
            $history = $pdo->prepare(
                "INSERT INTO booking_status_history (booking_id, changed_by, status, note)
                 VALUES (?, ?, 'requested', ?)"
            );
            $notify = $pdo->prepare(
                "INSERT INTO notifications (user_id, booking_id, channel, subject, body)
                 VALUES (?, ?, 'in_app', ?, ?), (?, ?, 'in_app', ?, ?)"
            );
            foreach ($items as $item) {
                $itemAmount = (int) round((float) $item['quoted_amount'] * 100);
                $itemCommission = (int) round((float) $item['commission_amount'] * 100);
                $itemProvider = (int) round((float) $item['provider_amount'] * 100);
                $insertPayment->execute([
                    $item['id'], 'DEMO-' . strtoupper($demoMethod), $reference,
                    $itemAmount / 100, $itemCommission / 100, $itemProvider / 100,
                    'platform_held',
                ]);
                $updateBooking->execute([$item['id']]);
                if ($updateBooking->rowCount() !== 1) {
                    $pdo->rollBack();
                    page_error('A booking in this checkout changed before payment could be recorded.', 409);
                }
                $history->execute([
                    $item['id'], current_user()['id'],
                    'Local simulated ' . $demoMethods[$demoMethod] . ' payment recorded. Provider request sent; no money was transferred.',
                ]);
                $notify->execute([
                    current_user()['id'], $item['id'], 'Demo payment recorded',
                    'A simulated ' . $demoMethods[$demoMethod] . ' payment of KES ' . number_format($itemAmount / 100, 2) . ' was recorded. No money was transferred.',
                    $order['provider_id'], $item['id'], 'New booking request',
                    'A paid local demo request for ' . $item['service_name'] . ' is ready for your review. No money was transferred.',
                ]);
            }
            $pdo->prepare("UPDATE checkout_orders SET status = 'paid' WHERE id = ?")->execute([$orderId]);
            $pdo->commit();
            $_SESSION['booking_cart'] = [];
            flash('info', 'Simulated ' . $demoMethods[$demoMethod] . ' checkout recorded. No money was transferred.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }

        if ($amountCents < 300) page_error('Paystack requires a minimum KES 3.00 checkout.');
        if ($order['payment_reference']) {
            $existing = $pdo->prepare(
                "SELECT COUNT(*) FROM payments WHERE reference = ? AND gateway = 'paystack'
                 AND status IN ('pending','initiated')"
            );
            $existing->execute([$order['payment_reference']]);
            if ((int) $existing->fetchColumn() > 0) {
                try {
                    $verified = paystack_verify_transaction((string) $order['payment_reference']);
                    $paid = paystack_settle_reference($pdo, (string) $order['payment_reference'], $verified);
                    if ($paid) {
                        flash('success', 'Your checkout payment has already been confirmed.');
                        header('Location: ' . url('Pages/dashboard.php'));
                        exit;
                    }
                    $status = (string) ($verified['data']['status'] ?? '');
                    if (!in_array($status, ['failed', 'abandoned'], true)) {
                        $checkoutUrl = (string) $order['checkout_url'];
                        if (str_starts_with($checkoutUrl, 'https://checkout.paystack.com/')) {
                            header('Location: ' . $checkoutUrl, true, 303);
                            exit;
                        }
                        flash('info', 'Your previous Paystack checkout is still being confirmed. Please try again shortly.');
                        header('Location: ' . url('Pages/dashboard.php'));
                        exit;
                    }
                } catch (RuntimeException $exception) {
                    flash('info', 'We are checking your previous Paystack attempt before allowing another payment. Please try again shortly.');
                    header('Location: ' . url('Pages/dashboard.php'));
                    exit;
                }
            }
        }

        $reference = 'TLK-' . date('ymd') . '-' . bin2hex(random_bytes(12));
        $pdo->beginTransaction();
        $lockOrder = $pdo->prepare('SELECT status FROM checkout_orders WHERE id = ? AND customer_id = ? FOR UPDATE');
        $lockOrder->execute([$orderId, current_user()['id']]);
        if ($lockOrder->fetchColumn() !== 'awaiting_payment') {
            $pdo->rollBack();
            flash('info', 'This checkout has already been paid or closed.');
            header('Location: ' . url('Pages/dashboard.php'));
            exit;
        }
        $insertPayment = $pdo->prepare(
            "INSERT INTO payments (booking_id, gateway, reference, amount, commission_amount, provider_amount, status, payout_mode)
             VALUES (?, 'paystack', ?, ?, ?, ?, 'pending', ?)"
        );
        foreach ($items as $item) {
            $insertPayment->execute([
                $item['id'], $reference, $item['quoted_amount'], $item['commission_amount'], $item['provider_amount'],
                'platform_held',
            ]);
        }
        $pdo->prepare('UPDATE checkout_orders SET payment_reference = ?, checkout_url = NULL WHERE id = ?')
            ->execute([$reference, $orderId]);
        $pdo->commit();

        $paymentPayload = [
            'email' => current_user()['email'],
            'amount' => $amountCents,
            'currency' => 'KES',
            'reference' => $reference,
            'callback_url' => paystack_app_url() . '/Pages/paystack_callback.php',
            'channels' => ['card', 'mobile_money'],
            'metadata' => [
                'checkout_order_id' => (int) $orderId,
                'platform' => 'LertFind Kenya',
            ],
        ];
        $response = paystack_request('POST', 'transaction/initialize', $paymentPayload);
        $checkoutUrl = $response['data']['authorization_url'] ?? '';
        if (!is_string($checkoutUrl) || !str_starts_with($checkoutUrl, 'https://checkout.paystack.com/')) {
            throw new RuntimeException('Paystack returned an invalid checkout address.');
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE payments SET status = 'initiated', gateway_reference = ? WHERE reference = ? AND status = 'pending'")
            ->execute([$reference, $reference]);
        $pdo->prepare('UPDATE checkout_orders SET checkout_url = ? WHERE id = ? AND payment_reference = ?')
            ->execute([$checkoutUrl, $orderId, $reference]);
        $pdo->commit();
        header('Location: ' . $checkoutUrl, true, 303);
        exit;
    }
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($reference) && preg_match('/^TLK-[0-9]{6}-[a-f0-9]{24}$/', $reference)) {
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE reference = ? AND gateway = 'paystack' AND status = 'pending'")
            ->execute([$reference]);
    }
    flash('error', $exception->getMessage());
    header('Location: ' . url('Pages/dashboard.php'));
    exit;
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    page_error('We could not start this payment. Please try again.');
}

$pageTitle = 'Confirm checkout';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <section class="booking-card">
        <div class="eyebrow">SECURE CHECKOUT</div>
        <h1>Review and pay.</h1>
        <p class="auth-intro">Your selected provider is notified only after payment is verified.</p>
        <div class="booking-summary"><span><b><?= e($order['provider_name']) ?></b><small><?= $order['module'] === 'tutoring' ? 'Tutoring' : 'Home services' ?> · <?= count($items) ?> service<?= count($items) === 1 ? '' : 's' ?></small></span></div>
        <ul class="checkout-item-list"><?php foreach ($items as $item): ?><li><span><?= e($item['service_name']) ?><?php if ($item['slug'] === 'laundry'): ?><small><?= (int) $item['laundry_baskets'] ?> basket<?= (int) $item['laundry_baskets'] === 1 ? '' : 's' ?> × KES <?= number_format((float) $item['booked_service_price'], 2) ?></small><?php endif; ?></span><strong>KES <?= number_format((float) $item['quoted_amount'], 2) ?></strong></li><?php endforeach; ?></ul>
        <div class="price-breakdown"><b>Checkout total · KES <?= number_format((float) $order['total_amount'], 2) ?></b><span>Platform commission <strong>KES <?= number_format((float) $order['commission_amount'], 2) ?></strong></span><span>Provider share <strong>KES <?= number_format((float) $order['provider_amount'], 2) ?></strong></span></div>
        <p class="form-footnote">Paystack supports the payment channels enabled for this merchant account. Direct Safaricom STK Push and bank-transfer APIs are not configured in this checkout.</p>
        <?php if (APP_ENV === 'local' && !paystack_checkout_ready(null, false)): ?>
            <form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $orderId ?>"><label>Simulated payment method<select name="demo_method" required><option value="">Choose a demo method</option><option value="mpesa">M-Pesa / mobile money (simulated)</option><option value="card">Card (simulated)</option><option value="bank_transfer">Bank transfer (simulated)</option></select></label><small class="demo-payment-note">Demo only: no payment request is sent and no money is transferred. Do not enter real card, PIN, or bank details.</small><button class="button button-pay button-full" type="submit">Record simulated payment <span>KES <?= number_format((float) $order['total_amount'], 2) ?></span></button></form>
        <?php elseif (paystack_checkout_ready(null, false)): ?>
            <form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $orderId ?>"><button class="button button-pay button-full" type="submit">Continue to payment <span>KES <?= number_format((float) $order['total_amount'], 2) ?></span></button><small><?= APP_ENV === 'local' ? 'Paystack test checkout' : 'Secure checkout through Paystack' ?></small></form>
        <?php else: ?>
            <p class="notice notice-info">Secure online checkout is not ready. Contact LertFind support before payment.</p>
        <?php endif; ?>
        <p class="form-footnote"><a href="<?= e(url('Pages/dashboard.php')) ?>">Return to your dashboard</a></p>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
