<?php
declare(strict_types=1);

function paystack_secret_key(): string
{
    $key = trim((string) getenv('PAYSTACK_SECRET_KEY'));
    if ($key === '' || !preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $key)) {
        throw new RuntimeException('Paystack is not configured. Set a valid PAYSTACK_SECRET_KEY on the server.');
    }
    return $key;
}

function paystack_app_url(): string
{
    $base = rtrim((string) getenv('TUTALINK_APP_URL'), '/');
    if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Set TUTALINK_APP_URL to the public HTTPS address of this website before enabling Paystack.');
    }
    $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || (APP_ENV === 'production' && $scheme !== 'https')) {
        throw new RuntimeException('The production Paystack callback URL must use HTTPS.');
    }
    return $base;
}

function paystack_checkout_ready(?string $subaccountCode, bool $requireSubaccount = true): bool
{
    $secretKey = trim((string) getenv('PAYSTACK_SECRET_KEY'));
    $baseUrl = trim((string) getenv('TUTALINK_APP_URL'));
    $validKey = (bool) preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $secretKey);
    $localKeyIsTest = APP_ENV !== 'local' || str_starts_with($secretKey, 'sk_test_');
    $baseReady = $validKey && $localKeyIsTest
        && (bool) filter_var($baseUrl, FILTER_VALIDATE_URL)
        && (APP_ENV !== 'production' || strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) === 'https');
    return $baseReady && (!$requireSubaccount || (bool) preg_match('/^ACCT_[A-Za-z0-9]+$/', (string) $subaccountCode));
}

function paystack_request(string $method, string $endpoint, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Paystack payments.');
    }
    $url = 'https://api.paystack.co/' . ltrim($endpoint, '/');
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Paystack connection.');
    }

    $headers = ['Authorization: Bearer ' . paystack_secret_key(), 'Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($payload !== null) {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $options[CURLOPT_POSTFIELDS] = $json;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Paystack could not be reached. Please try again.');
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        error_log('Paystack returned a non-JSON response with HTTP status ' . $status . '.');
        throw new RuntimeException('Paystack returned an unexpected response. Please try again.');
    }
    if ($status < 200 || $status >= 300 || empty($decoded['status'])) {
        error_log('Paystack API request failed with HTTP status ' . $status . ': ' . ($decoded['message'] ?? $curlError));
        throw new RuntimeException('Paystack could not process this request. Please try again or contact support.');
    }
    return $decoded;
}

function paystack_verify_transaction(string $reference): array
{
    return paystack_request('GET', 'transaction/verify/' . rawurlencode($reference));
}

function paystack_verified_signature(string $rawBody, string $header, string $secretKey, string $webhookSecret): bool
{
    if (strlen($rawBody) > 1048576) {
        return false;
    }

    if (preg_match('/^[a-f0-9]{128}$/i', $header)) {
        $legacy = hash_hmac('sha512', $rawBody, $secretKey);
        if (hash_equals(strtolower($legacy), strtolower($header))) {
            return true;
        }
    }

    if ($webhookSecret === '' || !str_starts_with($webhookSecret, 'whsec_')) {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $separator = strpos($part, '=');
        if ($separator === false) {
            continue;
        }
        $key = trim(substr($part, 0, $separator));
        $value = trim(substr($part, $separator + 1));
        if ($key === 't') {
            if ($timestamp !== null || !ctype_digit($value)) {
                return false;
            }
            $timestamp = (int) $value;
        } elseif ($key === 'v1') {
            $separator = strpos($value, ':');
            if ($separator !== false) {
                $signatures[] = substr($value, $separator + 1);
            }
        }
    }
    if ($timestamp === null || abs(time() - $timestamp) > 300 || !$signatures) {
        return false;
    }

    $expected = hash_hmac('sha512', $timestamp . '.' . $rawBody, $webhookSecret);
    foreach ($signatures as $signature) {
        if (preg_match('/^[a-f0-9]{128}$/', $signature) && hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}

function paystack_settle_reference(PDO $pdo, string $reference, array $transaction): bool
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            'SELECT p.id, p.booking_id, p.amount, p.commission_amount, p.provider_amount, p.status,
                    b.customer_id, b.provider_id, b.checkout_order_id, b.status AS booking_status,
                    c.module, c.name AS service_name, customer.email AS customer_email, provider.email AS provider_email
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN service_categories c ON c.id = b.category_id
             JOIN users customer ON customer.id = b.customer_id
             JOIN users provider ON provider.id = b.provider_id
             WHERE p.reference = ? AND p.gateway = \'paystack\'
             FOR UPDATE'
        );
        $query->execute([$reference]);
        $payments = $query->fetchAll();
        $data = $transaction['data'] ?? null;
        $amountCents = 0;
        $commissionCents = 0;
        $providerCents = 0;
        $orderId = null;
        $orderAlreadySettled = false;
        $emailNotifications = [];
        foreach ($payments as $payment) {
            $amountCents += (int) round((float) $payment['amount'] * 100);
            $commissionCents += (int) round((float) $payment['commission_amount'] * 100);
            $providerCents += (int) round((float) $payment['provider_amount'] * 100);
            if ($payment['checkout_order_id'] !== null) {
                if ($orderId !== null && $orderId !== (int) $payment['checkout_order_id']) {
                    $pdo->rollBack();
                    return false;
                }
                $orderId = (int) $payment['checkout_order_id'];
            } elseif ($orderId !== null || count($payments) > 1) {
                $pdo->rollBack();
                return false;
            }
        }
        if ($orderId !== null) {
            $orderQuery = $pdo->prepare(
                'SELECT status, total_amount, payment_reference
                 FROM checkout_orders WHERE id = ? FOR UPDATE'
            );
            $orderQuery->execute([$orderId]);
            $order = $orderQuery->fetch();
            if (!$order || $order['payment_reference'] !== $reference) {
                $pdo->rollBack();
                return false;
            }
            if ($order['status'] === 'awaiting_payment') {
                if ($amountCents !== (int) round((float) $order['total_amount'] * 100)) {
                    $pdo->rollBack();
                    return false;
                }
            } elseif ($order['status'] === 'paid') {
                $alreadySettled = true;
                foreach ($payments as $payment) {
                    if (!in_array($payment['status'], ['paid', 'refunded'], true)) {
                        $alreadySettled = false;
                        break;
                    }
                }
                if ($alreadySettled) {
                    $orderAlreadySettled = true;
                } elseif (count($payments) === 1 && $payments[0]['booking_status'] === 'awaiting_payment') {
                $refundedPayment = $pdo->prepare(
                    "SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'refunded'"
                );
                $refundedPayment->execute([$payments[0]['booking_id']]);
                if ((int) $refundedPayment->fetchColumn() === 0) {
                    $pdo->rollBack();
                    return false;
                }
                } else {
                    $pdo->rollBack();
                    return false;
                }
            } else {
                $pdo->rollBack();
                return false;
            }
        }
        if (!$payments || !is_array($data)
            || ($data['reference'] ?? '') !== $reference
            || ($data['currency'] ?? '') !== 'KES'
            || !isset($data['amount'])
            || $amountCents !== $commissionCents + $providerCents
            || (int) $data['amount'] !== $amountCents) {
            $pdo->rollBack();
            return false;
        }

        if (($data['status'] ?? '') !== 'success') {
            if (!in_array($data['status'] ?? '', ['failed', 'abandoned'], true)) {
                $pdo->commit();
                return false;
            }
            foreach ($payments as $payment) {
                if ($payment['status'] !== 'paid') {
                    $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ? AND status != 'paid'")
                        ->execute([$payment['id']]);
                }
            }
            $pdo->commit();
            return false;
        }

        if ($orderAlreadySettled) {
            $pdo->commit();
            return true;
        }

        $allAttemptsSettled = true;
        foreach ($payments as $payment) {
            if (!in_array($payment['status'], ['paid', 'refunded'], true)
                || in_array($payment['booking_status'], ['awaiting_payment', 'cancelled'], true)) {
                $allAttemptsSettled = false;
                break;
            }
        }
        if ($allAttemptsSettled) {
            $pdo->commit();
            return true;
        }
        foreach ($payments as $payment) {
            if (!in_array($payment['status'], ['pending', 'initiated', 'failed'], true)
                || $payment['booking_status'] !== 'awaiting_payment') {
                $pdo->rollBack();
                return false;
            }
        }

        $pdo->prepare(
            "UPDATE payments SET status = 'paid', paid_at = NOW(), paystack_transaction_id = ?
             WHERE reference = ? AND gateway = 'paystack' AND status IN ('pending','initiated','failed')"
        )->execute([(string) ($data['id'] ?? ''), $reference]);
        foreach ($payments as $payment) {
            $nextStatus = $payment['checkout_order_id'] === null ? 'paid' : 'requested';
            $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ? AND status = \'awaiting_payment\'')
                ->execute([$nextStatus, $payment['booking_id']]);
            if ($orderId === null && $payment['module'] === 'tutoring') {
                $classOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $pdo->prepare(
                    'UPDATE bookings SET class_otp = ?, class_otp_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                     WHERE id = ? AND class_started_at IS NULL AND class_otp IS NULL'
                )->execute([$classOtp, $payment['booking_id']]);
            }
            $note = $orderId === null
                ? 'Paystack payment verified by the server.'
                : 'Paystack payment verified. Provider request sent after successful checkout.';
            $pdo->prepare('INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, NULL, ?, ?)')
                ->execute([$payment['booking_id'], $nextStatus, $note]);
            $body = $orderId === null
                ? 'Your KES ' . number_format((float) $payment['amount'], 2) . ' payment was verified. The provider will deliver the service.'
                : 'Your KES ' . number_format((float) $payment['amount'], 2) . ' payment was verified. Your selected provider has been asked to confirm the service.';
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Payment confirmed', ?)")
                ->execute([$payment['customer_id'], $payment['booking_id'], $body]);
            $providerMessage = $orderId === null
                ? 'Payment for booking #' . $payment['booking_id'] . ' is confirmed. Your earnings are KES ' . number_format((float) $payment['provider_amount'], 2) . '.'
                : 'Payment for booking #' . $payment['booking_id'] . ' is confirmed. Please accept or decline the customer request in your dashboard.';
            $pdo->prepare(
                "INSERT INTO notifications (user_id, booking_id, channel, subject, body)
                 VALUES (?, ?, 'in_app', 'Booking payment confirmed', ?)"
            )->execute([$payment['provider_id'], $payment['booking_id'], $providerMessage]);
            $emailNotifications[] = [
                (string) $payment['provider_email'],
                'New paid LertFind service request',
                'A paid request for ' . $payment['service_name'] . ' is ready for your review. Sign in to accept or decline.',
            ];
            $emailNotifications[] = [
                (string) $payment['customer_email'],
                'Your LertFind payment is confirmed',
                $body,
            ];
        }
        if ($orderId !== null) {
            $pdo->prepare("UPDATE checkout_orders SET status = 'paid' WHERE id = ? AND status = 'awaiting_payment'")
                ->execute([$orderId]);
        }
        $pdo->commit();
        if (APP_ENV !== 'local') {
            foreach ($emailNotifications as [$recipient, $subject, $body]) {
                if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)
                    || !mail($recipient, $subject, $body, "From: LertFind <no-reply@tutalink.co.ke>\r\n")) {
                    error_log('LertFind payment notification email could not be sent for checkout reference ' . $reference . '.');
                }
            }
        }
        return true;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
