<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');
$modules = [];
foreach (['tutoring', 'home_services'] as $module) {
    if (admin_has_permission($module, 'manage_providers')) $modules[] = $module;
}
if (current_user()['role'] === 'super_admin') $modules = ['tutoring', 'home_services'];
if (!$modules) page_error('Your administrator account is not assigned to provider management.', 403);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $providerId = filter_var($_POST['provider_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    if (!$providerId) {
        $errors[] = 'Choose a valid provider.';
    } else {
        try {
            $pdo = db();
            $marks = implode(',', array_fill(0, count($modules), '?'));
            $check = $pdo->prepare(
                "SELECT u.id, u.is_active, u.email_verified_at
                 FROM users u JOIN provider_profiles p ON p.user_id = u.id
                 JOIN provider_services ps ON ps.provider_id = u.id
                 JOIN service_categories c ON c.id = ps.category_id
                 WHERE u.id = ? AND c.module IN ({$marks}) LIMIT 1"
            );
            $check->execute([$providerId, ...$modules]);
            $provider = $check->fetch();
            if (!$provider) page_error('You cannot manage this provider.', 403);

            if ($action === 'review-document') {
                $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
                $reviewStatus = (string) ($_POST['review_status'] ?? '');
                $reviewNote = trim((string) ($_POST['review_note'] ?? ''));
                if (!$documentId || !in_array($reviewStatus, ['approved', 'rejected'], true) || mb_strlen($reviewNote) > 500) {
                    $errors[] = 'Choose a valid document decision and keep the note under 500 characters.';
                } else {
                    $pdo->beginTransaction();
                    $userLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                    $userLock->execute([$providerId]);
                    if (!$userLock->fetchColumn()) {
                        $pdo->rollBack();
                        page_error('You cannot manage this provider.', 403);
                    }
                    $moduleLock = $pdo->prepare(
                        "SELECT p.user_id FROM provider_profiles p
                         JOIN provider_services ps ON ps.provider_id = p.user_id
                         JOIN service_categories c ON c.id = ps.category_id
                         WHERE p.user_id = ? AND c.module IN ({$marks}) LIMIT 1 FOR UPDATE"
                    );
                    $moduleLock->execute([$providerId, ...$modules]);
                    if (!$moduleLock->fetchColumn()) {
                        $pdo->rollBack();
                        page_error('You cannot manage this provider.', 403);
                    }
                    $documentQuery = $pdo->prepare(
                        "SELECT id FROM provider_documents
                         WHERE id = ? AND provider_id = ? AND review_status != 'superseded' FOR UPDATE"
                    );
                    $documentQuery->execute([$documentId, $providerId]);
                    if (!$documentQuery->fetchColumn()) {
                        $pdo->rollBack();
                        $errors[] = 'That provider document could not be found.';
                    } else {
                        $pdo->prepare(
                            'UPDATE provider_documents SET review_status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
                             WHERE id = ? AND provider_id = ?'
                        )->execute([$reviewStatus, $reviewNote ?: null, current_user()['id'], $documentId, $providerId]);
                        if ($reviewStatus === 'rejected') {
                            $pdo->prepare(
                                "UPDATE provider_profiles SET approval_status = 'pending', approved_at = NULL, is_available = 0
                                 WHERE user_id = ?"
                            )->execute([$providerId]);
                        }
                        $pdo->commit();
                        flash('success', $reviewStatus === 'approved' ? 'Document marked as verified.' : 'Document rejected; the provider remains unavailable.');
                        header('Location: ' . url('Admin/providers.php'));
                        exit;
                    }
                }
            } elseif ($action === 'provider-status') {
                $status = (string) ($_POST['approval_status'] ?? '');
                $active = isset($_POST['is_available']) ? 1 : 0;
                if (!in_array($status, ['pending', 'approved', 'suspended'], true)) {
                    $errors[] = 'Choose a valid provider status.';
                }
                if (!$errors) {
                    $pdo->beginTransaction();
                    $userLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                    $userLock->execute([$providerId]);
                    if (!$userLock->fetchColumn()) {
                        $pdo->rollBack();
                        page_error('You cannot manage this provider.', 403);
                    }
                    $moduleLock = $pdo->prepare(
                        "SELECT p.user_id FROM provider_profiles p
                         JOIN provider_services ps ON ps.provider_id = p.user_id
                         JOIN service_categories c ON c.id = ps.category_id
                         WHERE p.user_id = ? AND c.module IN ({$marks}) LIMIT 1 FOR UPDATE"
                    );
                    $moduleLock->execute([$providerId, ...$modules]);
                    if (!$moduleLock->fetchColumn()) {
                        $pdo->rollBack();
                        page_error('You cannot manage this provider.', 403);
                    }
                    $lockedProviderQuery = $pdo->prepare(
                        'SELECT u.is_active, u.email_verified_at, p.approval_status
                         FROM users u JOIN provider_profiles p ON p.user_id = u.id WHERE u.id = ?'
                    );
                    $lockedProviderQuery->execute([$providerId]);
                    $lockedProvider = $lockedProviderQuery->fetch();
                    if ($status === 'approved') {
                        $verifiedDocuments = $pdo->prepare(
                            "SELECT COUNT(DISTINCT document_type) FROM provider_documents
                             WHERE provider_id = ? AND review_status = 'approved'"
                        );
                        $verifiedDocuments->execute([$providerId]);
                        if ((int) $verifiedDocuments->fetchColumn() !== 2) {
                            $errors[] = 'Approve the provider’s National ID and qualification certificate before approving the profile.';
                        } elseif (!$lockedProvider || !(int) $lockedProvider['is_active'] || $lockedProvider['email_verified_at'] === null) {
                            $errors[] = 'The provider must have an active account and verified email before approval.';
                        }
                    }
                    if ($errors) {
                        $pdo->rollBack();
                    } else {
                        $approvedAt = $status === 'approved' ? 'COALESCE(approved_at, NOW())' : 'NULL';
                        $availability = $status !== 'approved'
                            ? 0
                            : ($lockedProvider['approval_status'] === 'approved' ? $active : 1);
                        $update = $pdo->prepare(
                            "UPDATE provider_profiles SET approval_status = ?, approved_at = {$approvedAt},
                             is_available = ? WHERE user_id = ?"
                        );
                        $update->execute([$status, $availability, $providerId]);
                        $pdo->commit();
                        flash('success', $status === 'approved'
                            ? ($availability ? 'Provider approved and made available for bookings.' : 'Provider profile updated but hidden from new booking requests.')
                            : 'Provider status updated.');
                        header('Location: ' . url('Admin/providers.php'));
                        exit;
                    }
                }
            } else {
                $errors[] = 'That provider action is not available.';
            }
        } catch (RuntimeException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Provider changes could not be saved.';
        }
    }
}

try {
    $marks = implode(',', array_fill(0, count($modules), '?'));
    $query = db()->prepare(
        "SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.email_verified_at, p.qualifications, p.location,
                p.service_mode, p.approval_status, p.is_available,
                c.module, GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ') AS categories
         FROM users u JOIN provider_profiles p ON p.user_id = u.id
         JOIN provider_services ps ON ps.provider_id = u.id
         JOIN service_categories c ON c.id = ps.category_id
         WHERE c.module IN ({$marks})
         GROUP BY u.id, u.full_name, u.email, u.phone, u.is_active, u.email_verified_at, p.qualifications,
                  p.location, p.service_mode, p.approval_status, p.is_available, c.module
         ORDER BY FIELD(p.approval_status, 'pending','approved','suspended'), u.created_at DESC LIMIT 200"
    );
    $query->execute($modules);
    $providers = $query->fetchAll();
    $documentsByProvider = [];
    $providerIds = array_values(array_unique(array_map('intval', array_column($providers, 'id'))));
    if ($providerIds) {
        $documentMarks = implode(',', array_fill(0, count($providerIds), '?'));
        $documentQuery = db()->prepare(
            "SELECT id, provider_id, document_type, original_filename, review_status, review_note, created_at
             FROM provider_documents WHERE provider_id IN ({$documentMarks}) ORDER BY provider_id, id DESC"
        );
        $documentQuery->execute($providerIds);
        foreach ($documentQuery->fetchAll() as $document) {
            $documentsByProvider[$document['provider_id']][] = $document;
        }
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$pageTitle = 'Manage providers';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">TRUST & QUALITY</div><h1>Provider community.</h1><p>Review identity and qualification documents before publishing profiles for new requests.</p></div><span class="count-pill"><?= count($providers) ?> profiles</span></div>
    <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$providers): ?><div class="empty-state compact"><h3>No provider profiles yet.</h3><p>New tutor and home-service provider registrations will be listed here for review.</p></div><?php else: ?>
        <section class="provider-admin-list"><?php foreach ($providers as $provider): ?>
            <article class="provider-admin-card">
                <div class="provider-admin-heading"><div class="provider-avatar"><?= e(mb_strtoupper(mb_substr($provider['full_name'], 0, 1))) ?></div><div><h2><?= e($provider['full_name']) ?></h2><p><?= e($provider['email']) ?> · <?= e($provider['phone']) ?></p><p>Email <?= $provider['email_verified_at'] ? 'verified' : 'not verified' ?></p></div><span class="status status-<?= e($provider['approval_status']) ?>"><?= e($provider['approval_status']) ?></span></div>
                <div class="provider-admin-facts"><span><b>Module</b><?= $provider['module'] === 'tutoring' ? 'Tutoring' : 'Home services' ?></span><span><b>Location</b><?= e($provider['location']) ?> · <?= e(ucfirst($provider['service_mode'])) ?></span></div>
                <p class="provider-admin-categories"><?= e($provider['categories']) ?></p><p class="provider-admin-qualifications"><?= e($provider['qualifications']) ?></p>
                <section class="provider-document-list"><h3>Identity & qualification documents</h3>
                    <?php $providerDocuments = $documentsByProvider[$provider['id']] ?? []; ?>
                    <?php if (!$providerDocuments): ?><p class="form-footnote">No documents uploaded yet. The provider cannot be approved.</p>
                    <?php else: foreach ($providerDocuments as $document): ?>
                        <article class="provider-document-item">
                            <div><b><?= $document['document_type'] === 'national_id' ? 'National ID' : 'Qualification certificate' ?></b><span><?= e($document['original_filename']) ?> · <?= e(ucfirst($document['review_status'])) ?></span><?php if ($document['review_note']): ?><small><?= e($document['review_note']) ?></small><?php endif; ?></div>
                            <a class="button button-outline button-small" href="<?= e(url('Admin/provider_document.php?id=' . (int) $document['id'])) ?>">Download securely</a>
                            <?php if ($document['review_status'] !== 'superseded'): ?>
                                <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="review-document"><input type="hidden" name="provider_id" value="<?= (int) $provider['id'] ?>"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><input type="text" name="review_note" maxlength="500" placeholder="Optional review note"><?php if ($document['review_status'] === 'approved'): ?><button class="button button-outline button-small" type="button" disabled>Approved</button><?php else: ?><button class="button button-outline button-small" type="submit" name="review_status" value="approved">Approve document</button><?php endif; ?><button class="button button-outline button-small" type="submit" name="review_status" value="rejected">Reject</button></form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; endif; ?>
                </section>
                <form method="post" class="provider-admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="provider-status"><input type="hidden" name="provider_id" value="<?= (int) $provider['id'] ?>">
                    <label>Profile status<select name="approval_status"><option value="pending" <?= $provider['approval_status'] === 'pending' ? 'selected' : '' ?>>Needs review</option><option value="approved" <?= $provider['approval_status'] === 'approved' ? 'selected' : '' ?>>Approved</option><option value="suspended" <?= $provider['approval_status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></label>
                    <label class="check-row provider-availability"><input type="checkbox" name="is_available" value="1" <?= $provider['is_available'] ? 'checked' : '' ?>><span>Available for new requests</span></label>
                    <?php if ($provider['approval_status'] === 'approved' && !$provider['is_available']): ?><p class="form-footnote">This approved profile is hidden from booking results. Enable availability and save to list it.</p><?php elseif ($provider['approval_status'] !== 'approved'): ?><p class="form-footnote">Approving the profile makes it available for bookings by default. You can turn availability off afterward.</p><?php endif; ?>
                    <button class="button button-outline button-small" type="submit">Save provider</button>
                </form>
            </article>
        <?php endforeach; ?></section>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
