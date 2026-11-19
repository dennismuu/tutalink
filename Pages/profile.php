<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
$user = current_user();
$isProvider = in_array($user['role'], ['tutor', 'service_provider'], true);
$values = ['full_name' => '', 'phone' => '', 'qualifications' => '', 'location' => '', 'service_mode' => 'both', 'bio' => ''];
$selectedCategories = [];
$selectedTeachingLevels = [];
$documentsByType = [];
$storedDocuments = [];
$errors = [];
try {
    $pdo = db();
    $query = $pdo->prepare(
        'SELECT u.full_name, u.phone, p.qualifications, p.location, p.service_mode, p.bio
         FROM users u LEFT JOIN provider_profiles p ON p.user_id = u.id WHERE u.id = ?'
    );
    $query->execute([$user['id']]);
    $values = array_replace($values, $query->fetch() ?: []);
    if ($isProvider) {
        $categoryQuery = $pdo->prepare('SELECT category_id FROM provider_services WHERE provider_id = ?');
        $categoryQuery->execute([$user['id']]);
        $selectedCategories = array_map('intval', $categoryQuery->fetchAll(PDO::FETCH_COLUMN));
        if ($user['role'] === 'tutor') {
            $levelQuery = $pdo->prepare('SELECT level_id FROM provider_teaching_levels WHERE provider_id = ?');
            $levelQuery->execute([$user['id']]);
            $selectedTeachingLevels = array_map('intval', $levelQuery->fetchAll(PDO::FETCH_COLUMN));
        }
        $documentQuery = $pdo->prepare(
            'SELECT document_type, review_status, original_filename, created_at
             FROM provider_documents WHERE provider_id = ? ORDER BY id DESC'
        );
        $documentQuery->execute([$user['id']]);
        foreach ($documentQuery->fetchAll() as $document) {
            if (!isset($documentsByType[$document['document_type']])) {
                $documentsByType[$document['document_type']] = $document;
            }
        }
    }
    $originalValues = $values;
    $originalCategories = $selectedCategories;
    $originalTeachingLevels = $selectedTeachingLevels;
    $teachingLevels = $user['role'] === 'tutor'
        ? $pdo->query('SELECT id, name FROM tutor_teaching_levels WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll()
        : [];
    $categoryOptions = $isProvider
        ? $pdo->prepare("SELECT id, name FROM service_categories         WHERE module = ? AND is_active = 1 AND is_coming_soon = 0 AND (? <> 'tutoring' OR LOWER(name) NOT IN ('primary','junior','secondary','kcse','pp1','pp2','grade 1','grade 2','grade 3','grade 4','grade 5','grade 6','grade 7','grade 8','grade 9','senior school')) ORDER BY sort_order, name")
        : null;
    if ($categoryOptions) {
        $categoryOptions->execute([$user['role'] === 'tutor' ? 'tutoring' : 'home_services', $user['role'] === 'tutor' ? 'tutoring' : 'home_services']);
        $categoryOptions = $categoryOptions->fetchAll();
    } else {
        $categoryOptions = [];
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
} catch (PDOException $exception) {
    page_error('We could not load your profile. Please try again.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    foreach (['full_name', 'phone', 'qualifications', 'location', 'service_mode', 'bio'] as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $selectedCategories = [];
    $selectedTeachingLevels = [];
    if (isset($_POST['categories']) && is_array($_POST['categories'])) {
        foreach ($_POST['categories'] as $category) {
            $id = filter_var($category, FILTER_VALIDATE_INT);
            if ($id) $selectedCategories[] = (int) $id;
        }
        $selectedCategories = array_values(array_unique($selectedCategories));
    }
    if ($user['role'] === 'tutor' && isset($_POST['teaching_levels']) && is_array($_POST['teaching_levels'])) {
        foreach ($_POST['teaching_levels'] as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT);
            if ($id) $selectedTeachingLevels[] = (int) $id;
        }
        $selectedTeachingLevels = array_values(array_unique($selectedTeachingLevels));
    }
    $phone = preg_replace('/[\s()+-]/', '', $values['phone']);
    if ($values['full_name'] === '' || mb_strlen($values['full_name']) > 160) $errors[] = 'Enter your full name.';
    if (!preg_match('/^\d{7,15}$/', (string) $phone)) $errors[] = 'Enter a valid phone number.';
    if ($isProvider) {
        if ($values['qualifications'] === '' || mb_strlen($values['qualifications']) > 5000) $errors[] = 'Enter your qualifications or professional experience.';
        if ($values['location'] === '' || mb_strlen($values['location']) > 180) $errors[] = 'Enter your town or service area.';
        if (mb_strlen($values['bio']) > 2000) $errors[] = 'Keep your introduction under 2,000 characters.';
        if (!in_array($values['service_mode'], ['online', 'physical', 'both'], true)) $errors[] = 'Choose a valid service mode.';
        $validIds = array_map('intval', array_column($categoryOptions, 'id'));
        if (!$selectedCategories || array_diff($selectedCategories, $validIds)) $errors[] = 'Select at least one service or subject.';
        if ($user['role'] === 'tutor') {
            $validLevelIds = array_map('intval', array_column($teachingLevels, 'id'));
            if (!$selectedTeachingLevels || array_diff($selectedTeachingLevels, $validLevelIds)) $errors[] = 'Select at least one level you can teach.';
        }
    }
    if (!$errors && $isProvider) {
        foreach (['national_id' => 'National ID', 'certificate' => 'Qualification certificate'] as $documentType => $label) {
            $upload = $_FILES[$documentType] ?? [];
            $hasUpload = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if (!$hasUpload && ($documentsByType[$documentType]['review_status'] ?? null) !== 'approved') {
                $errors[] = 'Upload an approved ' . $label . ' document.';
                continue;
            }
            if ($hasUpload) {
                try {
                    $storedDocuments[] = ['document_type' => $documentType] + store_provider_document_upload($upload);
                } catch (InvalidArgumentException $exception) {
                    $errors[] = $label . ': ' . $exception->getMessage();
                } catch (RuntimeException $exception) {
                    discard_provider_document_uploads($storedDocuments);
                    page_error($exception->getMessage(), 503);
                }
            }
        }
        if ($errors) {
            discard_provider_document_uploads($storedDocuments);
            $storedDocuments = [];
        }
    }
    if (!$errors) {
        sort($originalCategories);
        $newCategories = $selectedCategories;
        sort($newCategories);
        $newTeachingLevels = $selectedTeachingLevels;
        sort($originalTeachingLevels);
        sort($newTeachingLevels);
        $providerNeedsReview = $isProvider && (
            $values['qualifications'] !== $originalValues['qualifications']
            || $values['location'] !== $originalValues['location']
            || $values['service_mode'] !== $originalValues['service_mode']
            || $values['bio'] !== (string) $originalValues['bio']
            || $newCategories !== $originalCategories
            || ($user['role'] === 'tutor' && $newTeachingLevels !== $originalTeachingLevels)
            || $storedDocuments !== []
        );
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')
                ->execute([$values['full_name'], $phone, $user['id']]);
            if ($isProvider) {
                $profileUpdate = $providerNeedsReview
                    ? "UPDATE provider_profiles SET qualifications = ?, location = ?, service_mode = ?, bio = ?, approval_status = 'pending', approved_at = NULL WHERE user_id = ?"
                    : 'UPDATE provider_profiles SET qualifications = ?, location = ?, service_mode = ?, bio = ? WHERE user_id = ?';
                $pdo->prepare($profileUpdate)->execute([$values['qualifications'], $values['location'], $values['service_mode'], $values['bio'] ?: null, $user['id']]);
                $pdo->prepare('DELETE FROM provider_services WHERE provider_id = ?')->execute([$user['id']]);
                $insert = $pdo->prepare('INSERT INTO provider_services (provider_id, category_id) VALUES (?, ?)');
                foreach ($selectedCategories as $categoryId) $insert->execute([$user['id'], $categoryId]);
                if ($user['role'] === 'tutor') {
                    $pdo->prepare('DELETE FROM provider_teaching_levels WHERE provider_id = ?')->execute([$user['id']]);
                    $insertLevel = $pdo->prepare('INSERT INTO provider_teaching_levels (provider_id, level_id) VALUES (?, ?)');
                    foreach ($selectedTeachingLevels as $levelId) $insertLevel->execute([$user['id'], $levelId]);
                }
                $supersede = $pdo->prepare(
                    "UPDATE provider_documents SET review_status = 'superseded'
                     WHERE provider_id = ? AND document_type = ? AND review_status != 'superseded'"
                );
                $insertDocument = $pdo->prepare(
                    "INSERT INTO provider_documents
                     (provider_id, document_type, stored_filename, original_filename, mime_type, file_size)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($storedDocuments as $document) {
                    $supersede->execute([$user['id'], $document['document_type']]);
                    $insertDocument->execute([
                        $user['id'], $document['document_type'], $document['stored_filename'],
                        $document['original_filename'], $document['mime_type'], $document['file_size'],
                    ]);
                }
            }
            $pdo->commit();
            $storedDocuments = [];
            $_SESSION['user']['name'] = $values['full_name'];
            flash('success', $providerNeedsReview
                ? 'Your profile changes were saved. An administrator will review them before your profile appears publicly.'
                : 'Your account details have been updated.');
            header('Location: ' . url('Pages/profile.php'));
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            discard_provider_document_uploads($storedDocuments);
            page_error($exception->getMessage(), 503);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            discard_provider_document_uploads($storedDocuments);
            $storedDocuments = [];
            $errors[] = 'Your profile could not be saved.';
        }
    }
}

$pageTitle = 'Account profile';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <section class="auth-card profile-card">
        <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
        <div class="eyebrow">YOUR ACCOUNT</div><h1>Make it yours.</h1><p class="auth-intro">Keep your contact details and profile current.</p>
        <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" class="form-stack" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>Full name<input name="full_name" value="<?= e($values['full_name']) ?>" maxlength="160" required></label>
            <div class="form-row"><label>Phone<input name="phone" type="tel" value="<?= e($values['phone']) ?>" required></label><label>Email address<input value="<?= e($user['email']) ?>" disabled><span class="form-footnote">Contact support to change your verified email.</span></label></div>
            <?php if ($isProvider): ?>
                <label>Qualifications or experience<textarea name="qualifications" rows="3" maxlength="5000" required><?= e($values['qualifications']) ?></textarea></label>
                <label>Profile introduction<textarea name="bio" rows="3" maxlength="2000" placeholder="A brief introduction for customers…"><?= e($values['bio']) ?></textarea></label>
                <div class="form-row"><label>Town / service area<input name="location" value="<?= e($values['location']) ?>" maxlength="180" required></label><label>Service mode<select name="service_mode"><option value="both" <?= $values['service_mode'] === 'both' ? 'selected' : '' ?>>Online & physical</option><option value="online" <?= $values['service_mode'] === 'online' ? 'selected' : '' ?>>Online only</option><option value="physical" <?= $values['service_mode'] === 'physical' ? 'selected' : '' ?>>Physical only</option></select></label></div>
                <fieldset class="category-picker"><legend><?= $user['role'] === 'tutor' ? 'Subjects & learning support' : 'Home services offered' ?></legend><div class="category-checkbox-grid"><?php foreach ($categoryOptions as $category): ?><label class="check-row"><input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>" <?= in_array((int) $category['id'], $selectedCategories, true) ? 'checked' : '' ?>><span><?= e($category['name']) ?></span></label><?php endforeach; ?></div></fieldset>
                <?php if ($user['role'] === 'tutor'): ?><fieldset class="category-picker"><legend>Levels you can teach <span class="optional">(select all that apply)</span></legend><div class="category-checkbox-grid"><?php foreach ($teachingLevels as $level): ?><label class="check-row"><input type="checkbox" name="teaching_levels[]" value="<?= (int) $level['id'] ?>" <?= in_array((int) $level['id'], $selectedTeachingLevels, true) ? 'checked' : '' ?>><span><?= e($level['name']) ?></span></label><?php endforeach; ?></div></fieldset><?php endif; ?>
                <?php foreach (['national_id' => 'National ID', 'certificate' => 'Qualification certificate'] as $documentType => $label): ?>
                    <?php $document = $documentsByType[$documentType] ?? null; ?>
                    <label><?= e($label) ?> (PDF, JPG, or PNG; max 10 MB)
                        <input type="file" name="<?= e($documentType) ?>" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                        <span class="form-footnote"><?= $document ? 'Latest upload: ' . e($document['original_filename']) . ' · ' . e(ucfirst($document['review_status'])) : 'No document uploaded yet.' ?><?= $document && $document['review_status'] === 'approved' ? '' : ' Upload this document for admin review.' ?></span>
                    </label>
                <?php endforeach; ?>
                <p class="form-footnote">Changes to your professional profile require admin review before you appear in search results again.</p>
            <?php endif; ?>
            <button type="submit" class="button button-primary button-full">Save changes</button>
        </form>
    </section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
