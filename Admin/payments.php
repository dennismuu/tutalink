<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');
$modules = [];
foreach (['tutoring', 'home_services'] as $module) {
    if (admin_has_permission($module, 'manage_payments')) $modules[] = $module;
}
if (current_user()['role'] === 'super_admin') $modules = ['tutoring', 'home_services'];
if (!$modules) page_error('Your administrator account is not assigned to payment records.', 403);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['approve_refund', 'reject_refund', 'mark_refunded'], true)) {
        if (!$modules) page_error('You do not have permission to manage refunds.', 403);
        $refundId = filter_var($_POST['refund_id'] ?? null, FILTER_VALIDATE_INT);
        $resolutionNote = trim((string) ($_POST['resolution_note'] ?? ''));
        if (!$refundId || mb_strlen($resolutionNote) > 2000) page_error('Choose a valid refund request and keep the note under 2,000 characters.', 400);
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $refundQuery = $pdo->prepare(
                "SELECT rr.id, rr.booking_id, rr.status, b.status AS booking_status,
                        c.module, payment.gateway,
                        (SELECT wr.status FROM tutor_withdrawal_requests wr WHERE wr.booking_id = b.id LIMIT 1) AS withdrawal_status
                 FROM refund_requests rr JOIN bookings b ON b.id = rr.booking_id
                 JOIN service_categories c ON c.id = b.category_id
                 LEFT JOIN payments payment ON payment.id = (
                    SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = 'paid'
                 )
                 WHERE rr.id = ? FOR UPDATE"
            );
            $refundQuery->execute([$refundId]);
            $refund = $refundQuery->fetch();
            if (!$refund || !in_array($refund['module'], $modules, true) || $refund['withdrawal_status'] !== null
                || !in_array($refund['booking_status'], ['paid', 'completed', 'declined'], true)) {
                $pdo->rollBack();
                page_error('This refund request is no longer eligible for review.', 409);
            }
            $nextStatus = match ($action) {
                'approve_refund' => 'approved',
                'reject_refund' => 'rejected',
                'mark_refunded' => 'refunded',
            };
            $validStatus = $action === 'approve_refund' || $action === 'reject_refund'
                ? $refund['status'] === 'pending'
                : $refund['status'] === 'approved';
            if (!$validStatus) {
                $pdo->rollBack();
                page_error('This refund request has already been reviewed or is not approved for processing.', 409);
            }
            $pdo->prepare(
                'UPDATE refund_requests SET status = ?, resolution_note = ?, reviewed_by = ?, reviewed_at = NOW(),
                 refunded_at = ' . ($nextStatus === 'refunded' ? 'NOW()' : 'NULL') . ' WHERE id = ?'
            )->execute([$nextStatus, $resolutionNote ?: null, current_user()['id'], $refundId]);
            if ($nextStatus === 'refunded' && in_array($refund['gateway'], ['paystack', 'demo'], true)) {
                $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE booking_id = ? AND status = 'paid' AND gateway = ?")
                    ->execute([$refund['booking_id'], $refund['gateway']]);
            }
            $requesterQuery = $pdo->prepare('SELECT requested_by FROM refund_requests WHERE id = ?');
            $requesterQuery->execute([$refundId]);
            $requesterId = (int) $requesterQuery->fetchColumn();
            $body = $nextStatus === 'approved'
                ? 'Your refund request for booking #' . $refund['booking_id'] . ' was approved. The admin will process the refund externally.'
                : ($nextStatus === 'refunded'
                    ? 'Your refund for booking #' . $refund['booking_id'] . ' was recorded as processed.'
                    : 'Your refund request for booking #' . $refund['booking_id'] . ' was declined. Contact support if you need help.');
            $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'Refund request update', ?)")
                ->execute([$requesterId, $refund['booking_id'], $body]);
            $pdo->commit();
            flash('success', $nextStatus === 'approved'
                ? 'Refund approved. Process the refund externally before recording it as completed.'
                : ($nextStatus === 'refunded' ? 'Refund recorded as processed.' : 'Refund request declined.'));
            header('Location: ' . url('Admin/payments.php'));
            exit;
        } catch (RuntimeException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            page_error($exception->getMessage(), 503);
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            page_error('We could not update that refund request. Please try again.');
        }
    }
    if (!in_array($action, ['approve_withdrawal', 'reject_withdrawal', 'release_withdrawal'], true)
        || !$modules) {
        page_error('You do not have permission to manage this withdrawal.', 403);
    }
    $requestId = filter_var($_POST['withdrawal_id'] ?? null, FILTER_VALIDATE_INT);
    $payoutReference = trim((string) ($_POST['payout_reference'] ?? ''));
    if (!$requestId || mb_strlen($payoutReference) > 120) {
        page_error('Enter a valid withdrawal and payout reference.', 400);
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $requestQuery = $pdo->prepare(
            "SELECT wr.id, wr.booking_id, wr.status, b.status AS booking_status,
                    b.provider_completed_at, b.customer_completed_at, c.module,
                    (SELECT p.gateway FROM payments p
                     WHERE p.booking_id = b.id AND p.status = 'paid'
                     ORDER BY p.id DESC LIMIT 1) AS paid_gateway,
                    (SELECT p.payout_mode FROM payments p
                     WHERE p.booking_id = b.id AND p.status = 'paid'
                     ORDER BY p.id DESC LIMIT 1) AS paid_payout_mode
             FROM tutor_withdrawal_requests wr
             JOIN bookings b ON b.id = wr.booking_id
             JOIN service_categories c ON c.id = b.category_id
             WHERE wr.id = ? FOR UPDATE"
        );
        $requestQuery->execute([$requestId]);
        $withdrawal = $requestQuery->fetch();
        $eligiblePayment = $withdrawal
            && (($withdrawal['paid_gateway'] === 'paystack' && $withdrawal['paid_payout_mode'] === 'platform_held')
                || (APP_ENV === 'local' && $withdrawal['paid_gateway'] === 'demo'));
        if (!$withdrawal || !in_array($withdrawal['status'], ['pending', 'approved', 'payout_requested'], true) || $withdrawal['booking_status'] !== 'completed'
            || $withdrawal['provider_completed_at'] === null || $withdrawal['customer_completed_at'] === null
            || !in_array($withdrawal['module'], $modules, true) || !$eligiblePayment) {
            $pdo->rollBack();
            page_error('This withdrawal is no longer eligible for release.', 409);
        }

        if ($action === 'approve_withdrawal' || $action === 'reject_withdrawal') {
            if ($withdrawal['status'] !== 'pending') {
                $pdo->rollBack();
                page_error('This withdrawal has already been reviewed.', 409);
            }
            $nextStatus = $action === 'approve_withdrawal' ? 'approved' : 'rejected';
            $pdo->prepare(
                'UPDATE tutor_withdrawal_requests SET status = ?, approved_by = ?, approved_at = NOW()
                 WHERE id = ? AND status = \'pending\''
            )->execute([$nextStatus, current_user()['id'], $requestId]);
            $providerQuery = $pdo->prepare('SELECT provider_id FROM bookings WHERE id = ?');
            $providerQuery->execute([$withdrawal['booking_id']]);
            $providerId = (int) $providerQuery->fetchColumn();
            $subject = $nextStatus === 'approved' ? 'Provider withdrawal approved' : 'Provider withdrawal declined';
            $body = $nextStatus === 'approved'
                ? ($withdrawal['paid_gateway'] === 'demo'
                    ? 'Your demo earnings for booking #' . $withdrawal['booking_id'] . ' are ready for the payout workflow. Use test-only details; no money will be transferred.'
                    : 'Your earnings for booking #' . $withdrawal['booking_id'] . ' are available. Add your mobile-money or bank details from your dashboard to request payout.')
                : 'Your withdrawal request for booking #' . $withdrawal['booking_id'] . ' was declined by an admin. Contact support if you need help.';
            $pdo->prepare(
                "INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', ?, ?)"
            )->execute([$providerId, $withdrawal['booking_id'], $subject, $body]);
            $pdo->commit();
            flash('success', $nextStatus === 'approved'
                ? ($withdrawal['paid_gateway'] === 'demo'
                    ? 'Demo withdrawal approved. The provider can submit test-only payout details; no money will be transferred.'
                    : 'Withdrawal approved. The provider can now submit payout details.')
                : 'Withdrawal request declined.');
            header('Location: ' . url('Admin/payments.php'));
            exit;
        }

        if ($withdrawal['status'] !== 'payout_requested') {
            $pdo->rollBack();
            page_error('The provider must submit payout details before you can record a release.', 409);
        }
        $update = $pdo->prepare(
            "UPDATE tutor_withdrawal_requests
             SET status = 'released', payout_reference = ?, released_by = ?, released_at = NOW()
             WHERE id = ? AND status = 'payout_requested'"
        );
        $update->execute([$payoutReference !== '' ? $payoutReference : null, current_user()['id'], $requestId]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            page_error('This withdrawal was already released or is not ready.', 409);
        }
        $pdo->prepare(
            "INSERT INTO booking_status_history (booking_id, changed_by, status, note)
             VALUES (?, ?, 'payout_released', ?)"
        )->execute([
            $withdrawal['booking_id'],
            current_user()['id'],
            ($withdrawal['paid_gateway'] === 'demo'
                ? 'Admin recorded demo provider payout status; no money was transferred'
                : 'Admin recorded manual provider payout release')
                . ($payoutReference !== '' ? ' (' . $payoutReference . ').' : '.'),
        ]);
        $providerQuery = $pdo->prepare('SELECT provider_id, provider_amount FROM bookings WHERE id = ?');
        $providerQuery->execute([$withdrawal['booking_id']]);
        $provider = $providerQuery->fetch();
        $pdo->prepare(
            "INSERT INTO notifications (user_id, booking_id, channel, subject, body)
             VALUES (?, ?, 'in_app', 'Withdrawal released', ?)"
        )->execute([
            $provider['provider_id'],
            $withdrawal['booking_id'],
            $withdrawal['paid_gateway'] === 'demo'
                ? 'An admin recorded the demo payout status for booking #' . $withdrawal['booking_id'] . '. No money was transferred.'
                : 'An admin recorded your KES ' . number_format((float) $provider['provider_amount'], 2)
                    . ' provider payout as released for booking #' . $withdrawal['booking_id'] . '.',
        ]);
        $pdo->commit();
        flash('success', $withdrawal['paid_gateway'] === 'demo'
            ? 'Demo payout status recorded. No money was transferred.'
            : 'Withdrawal release recorded. Confirm that the provider was paid externally.');
        header('Location: ' . url('Admin/payments.php'));
        exit;
    } catch (RuntimeException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        page_error($exception->getMessage(), 503);
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        page_error('We could not record the withdrawal release. Please try again.');
    }
}

try {
    $marks = implode(',', array_fill(0, count($modules), '?'));
    $query = db()->prepare(
        "SELECT p.id, p.gateway, p.reference, p.amount, p.commission_amount, p.provider_amount,
                p.status, p.created_at, p.paid_at, b.id AS booking_id, c.name AS service_name,
                c.module, customer.full_name AS customer_name, provider.full_name AS provider_name
         FROM payments p JOIN bookings b ON b.id = p.booking_id
         JOIN service_categories c ON c.id = b.category_id
         JOIN users customer ON customer.id = b.customer_id
         JOIN users provider ON provider.id = b.provider_id
         WHERE c.module IN ({$marks}) ORDER BY p.created_at DESC LIMIT 200"
    );
    $query->execute($modules);
    $payments = $query->fetchAll();
    $withdrawals = [];
    $refunds = [];
    if ($modules) {
        $withdrawalMarks = implode(',', array_fill(0, count($modules), '?'));
        $withdrawalQuery = db()->prepare(
            "SELECT wr.id, wr.booking_id, wr.amount, wr.commission_amount, wr.status,
                    wr.payout_reference, wr.requested_at, wr.released_at, wr.payout_method,
                    wr.payout_details_encrypted, wr.approved_at,
                    (SELECT p.gateway FROM payments p
                     WHERE p.booking_id = b.id AND p.status = 'paid'
                     ORDER BY p.id DESC LIMIT 1) AS paid_gateway,
                    c.module, provider.full_name AS provider_name, customer.full_name AS customer_name,
                    releaser.full_name AS admin_name
             FROM tutor_withdrawal_requests wr
             JOIN bookings b ON b.id = wr.booking_id
             JOIN service_categories c ON c.id = b.category_id
             JOIN users provider ON provider.id = b.provider_id
             JOIN users customer ON customer.id = b.customer_id
             LEFT JOIN users releaser ON releaser.id = wr.released_by
             WHERE c.module IN ({$withdrawalMarks})
             ORDER BY (wr.status = 'pending') DESC, wr.requested_at DESC LIMIT 200"
        );
        $withdrawalQuery->execute($modules);
        $withdrawals = $withdrawalQuery->fetchAll();
        foreach ($withdrawals as &$withdrawal) {
            $withdrawal['payout_destination'] = $withdrawal['payout_details_encrypted']
                ? decrypt_payout_details($withdrawal['payout_details_encrypted'])
                : null;
        }
        unset($withdrawal);
    }
    if ($modules) {
        $moduleMarks = implode(',', array_fill(0, count($modules), '?'));
        $refundQuery = db()->prepare(
            "SELECT rr.id, rr.booking_id, rr.reason, rr.status, rr.resolution_note, rr.requested_at,
                    rr.reviewed_at, rr.refunded_at, payment.gateway, payment.amount, c.module,
                    student.full_name AS student_name, tutor.full_name AS tutor_name
             FROM refund_requests rr
             JOIN bookings b ON b.id = rr.booking_id
             JOIN service_categories c ON c.id = b.category_id
             JOIN users student ON student.id = rr.requested_by
             JOIN users tutor ON tutor.id = b.provider_id
             LEFT JOIN payments payment ON payment.id = (
                SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id AND p2.status IN ('paid','refunded')
             )
             WHERE c.module IN ({$moduleMarks})
             ORDER BY (rr.status = 'pending') DESC, rr.requested_at DESC LIMIT 200"
        );
        $refundQuery->execute($modules);
        $refunds = $refundQuery->fetchAll();
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$pageTitle = 'Payment records';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">PAYMENT OPERATIONS</div><h1>Clear, traceable payments.</h1><p>Paystack transactions and local demo records are clearly identified; demo records are not real payments.</p></div><span class="count-pill"><?= count($payments) ?> records</span></div>
    <?php if ($modules): ?>
        <section class="admin-withdrawals">
            <div class="section-title"><div><div class="eyebrow">PROVIDER PAYOUTS</div><h2>Withdrawal requests</h2></div><span class="count-pill"><?= count($withdrawals) ?> requests</span></div>
            <p class="form-footnote">For verified payments, pay the provider's share outside the app before recording release. Local demo withdrawals can be reviewed and recorded through the same steps, but are status-only: no money is transferred. Use test-only payout details.</p>
            <?php if (!$withdrawals): ?><div class="empty-state compact"><h3>No withdrawal requests yet.</h3><p>Eligible requests appear after the provider and customer both confirm completion.</p></div><?php else: ?>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Booking / service</th><th>Provider / customer</th><th>Provider payout</th><th>Platform commission</th><th>Status</th><th>Requested / released</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($withdrawals as $withdrawal): ?><tr>
                    <td>#<?= (int) $withdrawal['booking_id'] ?><small><?= $withdrawal['module'] === 'tutoring' ? 'Tutoring' : 'Home services' ?></small><?php if ($withdrawal['paid_gateway'] === 'demo'): ?><small class="demo-payment-note">DEMO — no funds transferred</small><?php endif; ?></td>
                    <td><?= e($withdrawal['provider_name']) ?><small>Customer: <?= e($withdrawal['customer_name']) ?></small></td>
                    <td>KES <?= number_format((float) $withdrawal['amount'], 2) ?></td>
                    <td>KES <?= number_format((float) $withdrawal['commission_amount'], 2) ?></td>
                    <td><span class="status status-<?= e($withdrawal['status']) ?>"><?= e(str_replace('_', ' ', $withdrawal['status'])) ?></span>
                        <?php if ($withdrawal['payout_destination']): ?><small><?= e($withdrawal['payout_method'] === 'bank' ? 'Bank' : 'Mobile money') ?>: <?= e($withdrawal['payout_destination']) ?></small><?php endif; ?>
                        <?php if ($withdrawal['payout_reference']): ?><small>Ref: <?= e($withdrawal['payout_reference']) ?></small><?php endif; ?></td>
                    <td><?= e(date('M j, Y · g:i a', strtotime($withdrawal['requested_at']))) ?><?php if ($withdrawal['approved_at']): ?><small>Reviewed <?= e(date('M j · g:i a', strtotime($withdrawal['approved_at']))) ?></small><?php endif; ?><?php if ($withdrawal['released_at']): ?><small>Released <?= e(date('M j · g:i a', strtotime($withdrawal['released_at']))) ?></small><?php endif; ?></td>
                    <td><?php if ($withdrawal['paid_gateway'] === 'demo' && APP_ENV !== 'local'): ?><small>Demo withdrawal actions are available only in local mode.</small>
                        <?php elseif ($withdrawal['status'] === 'pending'): ?>
                        <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['id'] ?>"><button class="button button-primary button-small" name="action" value="approve_withdrawal" type="submit">Approve</button><button class="button button-outline button-small" name="action" value="reject_withdrawal" type="submit">Reject</button></form>
                        <?php elseif ($withdrawal['status'] === 'payout_requested'): ?>
                        <form method="post" class="withdrawal-release-form" data-confirm="<?= e($withdrawal['paid_gateway'] === 'demo' ? 'Demo only: record this payout status? No money will be transferred.' : 'Only continue after you have paid KES ' . number_format((float) $withdrawal['amount'], 2) . ' to the provider.') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="release_withdrawal"><input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['id'] ?>"><label class="sr-only" for="payout-reference-<?= (int) $withdrawal['id'] ?>"><?= $withdrawal['paid_gateway'] === 'demo' ? 'Demo reference (optional)' : 'Payout reference (optional)' ?></label><input id="payout-reference-<?= (int) $withdrawal['id'] ?>" type="text" name="payout_reference" maxlength="120" placeholder="<?= $withdrawal['paid_gateway'] === 'demo' ? 'Demo reference (optional)' : 'Payout reference (optional)' ?>"><button class="button button-primary button-small" type="submit"><?= $withdrawal['paid_gateway'] === 'demo' ? 'Record demo payout status' : 'Record paid payout' ?></button></form>
                        <?php elseif ($withdrawal['status'] === 'released'): ?><small><?= $withdrawal['paid_gateway'] === 'demo' ? 'Demo payout recorded; no funds transferred.' : 'Released by ' . e($withdrawal['admin_name'] ?: 'admin') ?><?= $withdrawal['released_at'] ? ' · ' . e(date('M j, Y', strtotime($withdrawal['released_at']))) : '' ?></small>
                        <?php elseif ($withdrawal['status'] === 'approved'): ?><small>Waiting for the provider to submit payout details.</small>
                        <?php else: ?><small>No further action.</small><?php endif; ?></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($modules): ?>
        <section class="admin-withdrawals">
            <div class="section-title"><div><div class="eyebrow">CUSTOMER CARE</div><h2>Refund requests</h2></div><span class="count-pill"><?= count($refunds) ?> requests</span></div>
            <p class="form-footnote">Refund requests are reviewed by an admin. An approved refund must be processed through the original payment provider before you mark it as processed. For a provider-declined paid checkout, record the refund before reassigning the booking. Demo transactions did not move money.</p>
            <?php if (!$refunds): ?><div class="empty-state compact"><h3>No refund requests.</h3></div><?php else: ?>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Booking / customer</th><th>Provider</th><th>Amount / method</th><th>Reason</th><th>Status</th><th>Admin action</th></tr></thead><tbody>
                <?php foreach ($refunds as $refund): ?><tr>
                    <td>#<?= (int) $refund['booking_id'] ?><small><?= e($refund['student_name']) ?></small></td>
                    <td><?= e($refund['tutor_name']) ?><small><?= $refund['module'] === 'tutoring' ? 'Tutoring' : 'Home services' ?></small></td>
                    <td>KES <?= number_format((float) $refund['amount'], 2) ?><small><?= e(strtoupper((string) $refund['gateway'])) ?></small></td>
                    <td><?= nl2br(e($refund['reason'])) ?></td>
                    <td><span class="status status-<?= e($refund['status']) ?>"><?= e($refund['status']) ?></span><?php if ($refund['resolution_note']): ?><small><?= e($refund['resolution_note']) ?></small><?php endif; ?></td>
                    <td><?php if ($refund['status'] === 'pending'): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="refund_id" value="<?= (int) $refund['id'] ?>"><input name="resolution_note" maxlength="2000" placeholder="Optional note"><button class="button button-primary button-small" name="action" value="approve_refund" type="submit">Approve</button><button class="button button-outline button-small" name="action" value="reject_refund" type="submit">Reject</button></form>
                        <?php elseif ($refund['status'] === 'approved'): ?><form method="post" class="inline-actions" data-confirm="Only mark this refunded after completing any real refund through the original payment provider."><?= csrf_field() ?><input type="hidden" name="refund_id" value="<?= (int) $refund['id'] ?>"><input name="resolution_note" maxlength="2000" placeholder="Optional processing reference"><button class="button button-primary button-small" name="action" value="mark_refunded" type="submit">Mark processed</button></form>
                        <?php else: ?><small><?= $refund['refunded_at'] ? 'Processed ' . e(date('M j, Y', strtotime($refund['refunded_at']))) : 'No further action.' ?></small><?php endif; ?></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <section class="payment-records">
        <div class="section-title"><div><div class="eyebrow">TRANSACTION HISTORY</div><h2>Payment records</h2></div></div>
    <?php if (!$payments): ?><div class="empty-state compact"><h3>No payments yet.</h3><p>Payment attempts in your assigned modules will appear here.</p></div><?php else: ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Transaction</th><th>Booking</th><th>Customer / provider</th><th>Total</th><th>Platform</th><th>Provider</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($payments as $payment): ?><tr><td><strong><?= $payment['gateway'] === 'demo' ? 'DEMO · NO TRANSFER' : e(strtoupper($payment['gateway'])) ?></strong><small><?= e($payment['reference'] ?: 'Pending reference') ?></small><small><?= e(date('M j, Y · g:i a', strtotime($payment['created_at']))) ?></small></td><td>#<?= (int) $payment['booking_id'] ?><small><?= e($payment['service_name']) ?></small></td><td><?= e($payment['customer_name']) ?><small>with <?= e($payment['provider_name']) ?></small></td><td>KES <?= number_format((float) $payment['amount'], 2) ?></td><td>KES <?= number_format((float) $payment['commission_amount'], 2) ?></td><td>KES <?= number_format((float) $payment['provider_amount'], 2) ?></td><td><span class="status status-<?= e($payment['status']) ?>"><?= e($payment['status']) ?></span><?php if ($payment['gateway'] === 'demo'): ?><small>No money transferred</small><?php elseif ($payment['paid_at']): ?><small><?= e(date('M j · g:i a', strtotime($payment['paid_at']))) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
