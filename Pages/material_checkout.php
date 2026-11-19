<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/paystack.php';
require_roles('student_parent');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('Pages/materials.php'));
    exit;
}
reject_invalid_csrf();
$materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
if (!$materialId || $materialId < 1) page_error('Choose a valid revision material.', 400);

try {
    $pdo = db();
    $pdo->beginTransaction();
    $materialQuery = $pdo->prepare('SELECT id, price FROM revision_materials WHERE id = ? AND is_active = 1 FOR UPDATE');
    $materialQuery->execute([$materialId]);
    $material = $materialQuery->fetch();
    if (!$material || (float) $material['price'] <= 0) {
        $pdo->rollBack();
        page_error('This revision material is not available for purchase.', 404);
    }
    $owned = $pdo->prepare("SELECT id FROM revision_material_orders WHERE material_id = ? AND customer_id = ? AND status = 'paid' LIMIT 1");
    $owned->execute([$materialId, current_user()['id']]);
    if ($owned->fetchColumn()) {
        $pdo->rollBack();
        header('Location: ' . url('Pages/material_download.php?id=' . $materialId));
        exit;
    }
    $amountCents = (int) round((float) $material['price'] * 100);
    $reference = 'TLR-' . date('ymd') . '-' . bin2hex(random_bytes(12));
    $order = $pdo->prepare("INSERT INTO revision_material_orders (material_id, customer_id, amount, payment_reference) VALUES (?, ?, ?, ?)");
    $order->execute([$materialId, current_user()['id'], $amountCents / 100, $reference]);
    $orderId = (int) $pdo->lastInsertId();
    if (APP_ENV === 'local' && !paystack_checkout_ready(null, false)) {
        $demo = (string) ($_POST['demo_method'] ?? '');
        if (!in_array($demo, ['mpesa', 'card', 'bank_transfer'], true)) {
            $pdo->rollBack();
            page_error('Choose a simulated payment method.', 400);
        }
        $pdo->prepare("UPDATE revision_material_orders SET status = 'paid', gateway_reference = ?, paid_at = NOW() WHERE id = ?")
            ->execute(['DEMO-' . strtoupper($demo), $orderId]);
        $pdo->commit();
        flash('info', 'Simulated payment recorded. No money was transferred.');
        header('Location: ' . url('Pages/material_download.php?id=' . $materialId));
        exit;
    }
    $customer = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $customer->execute([current_user()['id']]);
    $email = (string) $customer->fetchColumn();
    $pdo->commit();
    $response = paystack_request('POST', 'transaction/initialize', [
        'email' => $email,
        'amount' => $amountCents,
        'currency' => 'KES',
        'reference' => $reference,
        'callback_url' => paystack_app_url() . '/Pages/material_paystack_callback.php',
        'channels' => ['card', 'mobile_money'],
        'metadata' => ['revision_material_order_id' => $orderId, 'platform' => 'LertFind Kenya'],
    ]);
    $checkoutUrl = $response['data']['authorization_url'] ?? '';
    if (!is_string($checkoutUrl) || !str_starts_with($checkoutUrl, 'https://checkout.paystack.com/')) {
        throw new RuntimeException('Paystack returned an invalid checkout address.');
    }
    $pdo->prepare('UPDATE revision_material_orders SET gateway_reference = ? WHERE id = ? AND status = \'awaiting_payment\'')
        ->execute([$reference, $orderId]);
    header('Location: ' . $checkoutUrl, true, 303);
    exit;
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    page_error('We could not start this material purchase. Please try again.');
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    flash('error', $exception->getMessage());
    header('Location: ' . url('Pages/materials.php'));
    exit;
}
