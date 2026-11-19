<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/paystack.php';
$reference = trim((string) ($_GET['reference'] ?? $_GET['trxref'] ?? ''));
if (!preg_match('/^TLK-[0-9]{6}-[a-f0-9]{24}$/', $reference)) {
    flash('error', 'We could not confirm this payment. Check your booking dashboard or contact support.');
    header('Location: ' . url(current_user() ? 'Pages/dashboard.php' : 'Pages/login.php'));
    exit;
}

try {
    $verified = paystack_verify_transaction($reference);
    $paid = paystack_settle_reference(db(), $reference, $verified);
    if ($paid) {
        flash('success', 'Payment confirmed. Your booking has been updated and the provider has been notified.');
    } else {
        flash('error', 'Payment was not confirmed. If funds were deducted, contact support with reference ' . $reference . '.');
    }
} catch (RuntimeException $exception) {
    error_log('Paystack callback verification failed for reference ' . $reference . '.');
    flash('error', 'We could not confirm your payment yet. Check your dashboard again in a moment.');
} catch (PDOException $exception) {
    error_log('Database error processing Paystack callback for reference ' . $reference . '.');
    flash('error', 'We could not update your booking yet. Contact support if your payment was deducted.');
}

header('Location: ' . url(current_user() ? 'Pages/dashboard.php' : 'Pages/login.php'));
exit;
