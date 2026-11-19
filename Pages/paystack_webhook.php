<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/paystack.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$rawBody = file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_PAYSTACK_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
if (!is_string($rawBody) || $rawBody === '' || $signature === '') {
    http_response_code(400);
    exit;
}

try {
    $secretKey = paystack_secret_key();
    $webhookSecret = trim((string) getenv('PAYSTACK_WEBHOOK_SECRET'));
    if (!paystack_verified_signature($rawBody, $signature, $secretKey, $webhookSecret)) {
        http_response_code(401);
        exit;
    }
    $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    if (($event['event'] ?? '') === 'charge.success') {
        $reference = (string) ($event['data']['reference'] ?? '');
        if (preg_match('/^TLR-[0-9]{6}-[a-f0-9]{24}$/', $reference)) {
            $verified = paystack_verify_transaction($reference);
            $data = $verified['data'] ?? [];
            $pdo = db();
            $pdo->beginTransaction();
            $orderQuery = $pdo->prepare('SELECT id, amount, status FROM revision_material_orders WHERE payment_reference = ? FOR UPDATE');
            $orderQuery->execute([$reference]);
            $materialOrder = $orderQuery->fetch();
            if ($materialOrder && ($data['status'] ?? '') === 'success'
                && ($data['currency'] ?? '') === 'KES'
                && (int) ($data['amount'] ?? 0) === (int) round((float) $materialOrder['amount'] * 100)
                && ($data['reference'] ?? '') === $reference) {
                if ($materialOrder['status'] === 'awaiting_payment') {
                    $pdo->prepare("UPDATE revision_material_orders SET status = 'paid', paid_at = NOW(), gateway_reference = ? WHERE id = ?")
                        ->execute([(string) ($data['id'] ?? $reference), $materialOrder['id']]);
                }
                $pdo->commit();
            } else {
                $pdo->rollBack();
                error_log('Paystack material payment could not be matched to the expected amount for ' . $reference . '.');
            }
            http_response_code(200);
            exit;
        }
        if (!preg_match('/^TLK-[0-9]{6}-[a-f0-9]{24}$/', $reference)) {
            http_response_code(200);
            exit;
        }
        $verified = paystack_verify_transaction($reference);
        if (!paystack_settle_reference(db(), $reference, $verified)) {
            error_log('Paystack charge.success could not be matched to the expected LertFind amount for ' . $reference . '.');
        }
    }
    http_response_code(200);
} catch (JsonException $exception) {
    http_response_code(400);
} catch (RuntimeException $exception) {
    error_log('Paystack webhook processing is temporarily unavailable.');
    http_response_code(500);
} catch (PDOException $exception) {
    error_log('Database error processing Paystack webhook.');
    http_response_code(500);
}
