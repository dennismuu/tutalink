<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/paystack.php';
$reference = trim((string) ($_GET['reference'] ?? $_GET['trxref'] ?? ''));
if (!preg_match('/^TLR-[0-9]{6}-[a-f0-9]{24}$/', $reference)) {
    flash('error', 'We could not confirm this material payment.');
    header('Location: ' . url('Pages/materials.php'));
    exit;
}
try {
    $result = paystack_verify_transaction($reference);
    $data = $result['data'] ?? [];
    $pdo = db();
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT id, amount, status FROM revision_material_orders WHERE payment_reference = ? FOR UPDATE');
    $query->execute([$reference]);
    $order = $query->fetch();
    if ($order && in_array($order['status'], ['awaiting_payment', 'paid'], true)
        && ($data['status'] ?? '') === 'success'
        && ($data['currency'] ?? '') === 'KES'
        && (int) ($data['amount'] ?? 0) === (int) round((float) $order['amount'] * 100)
        && ($data['reference'] ?? '') === $reference) {
        if ($order['status'] === 'awaiting_payment') {
            $pdo->prepare("UPDATE revision_material_orders SET status = 'paid', paid_at = NOW(), gateway_reference = ? WHERE id = ?")
                ->execute([(string) ($data['id'] ?? $reference), $order['id']]);
        }
        $pdo->commit();
        flash('success', 'Payment confirmed. Your revision material is ready to download.');
    } else {
        $pdo->rollBack();
        flash('error', 'Payment could not be verified. If funds were deducted, contact support with reference ' . $reference . '.');
    }
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    page_error('We could not update your material purchase.');
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Revision material payment verification failed.');
    flash('error', 'We could not confirm your payment yet. Check the materials page again shortly.');
}
header('Location: ' . url('Pages/materials.php'));
exit;
