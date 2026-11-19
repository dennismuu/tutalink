<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if (current_user()) {
    header('Location: ' . url('Pages/dashboard.php'));
    exit;
}

$errors = [];
$values = ['full_name' => '', 'phone' => '', 'email' => '', 'role' => '', 'qualifications' => '', 'location' => '', 'service_mode' => 'both'];
$verificationUrl = null;
$mailSent = false;
$selectedCategories = [];
$selectedTeachingLevels = [];
$storedDocuments = [];
try {
    $categoryOptions = db()->query("SELECT id, module, name FROM service_categories WHERE is_active = 1 AND is_coming_soon = 0 AND NOT (module = 'tutoring' AND LOWER(name) IN ('primary','junior','secondary','kcse','pp1','pp2','grade 1','grade 2','grade 3','grade 4','grade 5','grade 6','grade 7','grade 8','grade 9','senior school')) ORDER BY sort_order, name")->fetchAll();
    $teachingLevels = db()->query('SELECT id, name FROM tutor_teaching_levels WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    foreach ($values as $key => $_) {
        $values[$key] = trim((string) ($_POST[$key] ?? $values[$key]));
    }
    if (isset($_POST['categories']) && is_array($_POST['categories'])) {
        foreach ($_POST['categories'] as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT);
            if ($id) $selectedCategories[] = (int) $id;
        }
        $selectedCategories = array_values(array_unique($selectedCategories));
    }
    if (isset($_POST['teaching_levels']) && is_array($_POST['teaching_levels'])) {
        foreach ($_POST['teaching_levels'] as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT);
            if ($id) $selectedTeachingLevels[] = (int) $id;
        }
        $selectedTeachingLevels = array_values(array_unique($selectedTeachingLevels));
    }

    $roleSlugs = ['student_parent', 'tutor', 'service_provider', 'customer'];
    $phone = preg_replace('/[\s()+-]/', '', $values['phone']);
    if ($values['full_name'] === '' || mb_strlen($values['full_name']) > 160) $errors[] = 'Enter your full name.';
    if (!$phone || !preg_match('/^\d{7,15}$/', $phone)) $errors[] = 'Enter a valid phone number, including your country code if needed.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    else {
        try {
            $emailCheck = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            $emailCheck->execute([strtolower($values['email'])]);
            if ($emailCheck->fetchColumn()) $errors[] = 'An account already uses that email address. Log in or use a different email.';
        } catch (PDOException | RuntimeException $exception) {
            page_error('We could not validate your email address right now. Please try again.', 503);
        }
    }
    if (!in_array($values['role'], $roleSlugs, true)) $errors[] = 'Choose an account type.';
    if (strlen((string) ($_POST['password'] ?? '')) < 10) $errors[] = 'Your password must be at least 10 characters.';
    if (!hash_equals((string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirm'] ?? ''))) $errors[] = 'Your passwords do not match.';
    if (in_array($values['role'], ['tutor', 'service_provider'], true)) {
        if ($values['qualifications'] === '' || mb_strlen($values['qualifications']) > 5000) $errors[] = 'Tell us about your qualifications or professional experience.';
        if ($values['location'] === '' || mb_strlen($values['location']) > 180) $errors[] = 'Enter your town or service area.';
        if ($values['role'] === 'service_provider' && $values['service_mode'] !== 'physical') {
            $errors[] = 'Home service providers can only offer physical services.';
        } elseif ($values['role'] === 'tutor' && !in_array($values['service_mode'], ['online', 'physical', 'both'], true)) {
            $errors[] = 'Choose how you offer your services.';
        }
        $expectedModule = $values['role'] === 'tutor' ? 'tutoring' : 'home_services';
        $validIds = array_map(
            'intval',
            array_column(
                array_filter($categoryOptions, static fn(array $category): bool => $category['module'] === $expectedModule),
                'id'
            )
        );
        if (!$selectedCategories || array_diff($selectedCategories, $validIds)) $errors[] = 'Select at least one service or subject you offer.';
        if ($values['role'] === 'tutor') {
            $validLevelIds = array_map('intval', array_column($teachingLevels, 'id'));
            if (!$selectedTeachingLevels || array_diff($selectedTeachingLevels, $validLevelIds)) $errors[] = 'Select at least one level you can teach.';
        }
    }
    if (!$errors && in_array($values['role'], ['tutor', 'service_provider'], true)) {
        foreach (['national_id' => 'National ID', 'certificate' => 'Qualification certificate'] as $documentType => $label) {
            try {
                $storedDocuments[] = ['document_type' => $documentType] + store_provider_document_upload($_FILES[$documentType] ?? []);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $label . ': ' . $exception->getMessage();
            } catch (RuntimeException $exception) {
                discard_provider_document_uploads($storedDocuments);
                page_error($exception->getMessage(), 503);
            }
            if ($errors) break;
        }
        if ($errors) {
            discard_provider_document_uploads($storedDocuments);
            $storedDocuments = [];
        }
    }
    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
            $roleQuery->execute([$values['role']]);
            $roleId = $roleQuery->fetchColumn();
            if (!$roleId) throw new RuntimeException('Account types are not set up. Import the LertFind database schema first.');

            $create = $pdo->prepare('INSERT INTO users (role_id, `role`, full_name, phone, email, password_hash) VALUES (?, ?, ?, ?, ?, ?)');
            $create->execute([(int) $roleId, $values['role'], $values['full_name'], $phone, strtolower($values['email']), password_hash((string) $_POST['password'], PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();

            if (in_array($values['role'], ['tutor', 'service_provider'], true)) {
                $profile = $pdo->prepare('INSERT INTO provider_profiles (user_id, qualifications, location, service_mode) VALUES (?, ?, ?, ?)');
                $profile->execute([$userId, $values['qualifications'], $values['location'], $values['service_mode']]);
                $assignService = $pdo->prepare('INSERT INTO provider_services (provider_id, category_id) VALUES (?, ?)');
                foreach ($selectedCategories as $categoryId) $assignService->execute([$userId, $categoryId]);
                if ($values['role'] === 'tutor') {
                    $assignLevel = $pdo->prepare('INSERT INTO provider_teaching_levels (provider_id, level_id) VALUES (?, ?)');
                    foreach ($selectedTeachingLevels as $levelId) $assignLevel->execute([$userId, $levelId]);
                }
                $insertDocument = $pdo->prepare(
                    "INSERT INTO provider_documents
                     (provider_id, document_type, stored_filename, original_filename, mime_type, file_size)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($storedDocuments as $document) {
                    $insertDocument->execute([
                        $userId, $document['document_type'], $document['stored_filename'],
                        $document['original_filename'], $document['mime_type'], $document['file_size'],
                    ]);
                }
            }

            $token = bin2hex(random_bytes(32));
            $verification = $pdo->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
            $verification->execute([$userId, hash('sha256', $token)]);
            $verificationUrl = app_absolute_url('Pages/verify.php?token=' . rawurlencode($token));
            $pdo->commit();

            $mailSent = send_app_email(
                strtolower($values['email']),
                'Verify your LertFind account',
                "Hello {$values['full_name']},\n\nVerify your email address to activate your LertFind account:\n{$verificationUrl}\n\nThis link expires in 24 hours."
            );
            if (!$mailSent) {
                error_log('LertFind verification email could not be sent for user ID ' . $userId . '. Check the server mail transport and sender configuration.');
            }
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            discard_provider_document_uploads($storedDocuments);
            $storedDocuments = [];
            if ($exception->getCode() === '23000') {
                $errors[] = 'An account already uses that email address.';
            } else {
                page_error('We could not save your registration. Please try again or contact support.');
            }
        } catch (RuntimeException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            discard_provider_document_uploads($storedDocuments);
            $storedDocuments = [];
            page_error($exception->getMessage(), 503);
        }
    }
}

$pageTitle = 'Create your account';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <?php if ($verificationUrl): ?>
        <section class="auth-card success-card">
            <div class="eyebrow">ONE LAST STEP</div>
            <h1>Account created.</h1>
            <p><?= $mailSent ? 'We sent a verification link to your email address. Open it to verify your account before logging in.' : 'Your account was created, but the verification email could not be sent. Check the email service configuration, then request a new verification email.' ?>
                <?php if (in_array($values['role'], ['tutor', 'service_provider'], true)): ?> Your provider profile is waiting for admin approval. After you verify your email and an administrator approves your submitted documents and profile, you can access your provider dashboard.<?php endif; ?></p>
            <a class="button button-primary button-full" href="<?= e(url('Pages/resend_verification.php')) ?>">Request a new verification email</a>
            <p class="form-footnote">The verification link expires in 24 hours. After verifying, return to the login page. An administrator account can only be created by a Super Admin.</p>
        </section>
    <?php else: ?>
        <section class="auth-card">
            <div class="eyebrow">JOIN LERTFIND</div>
            <h1>Create your account</h1>
            <p class="auth-intro">One account for learning, trusted home help, and everything in between.</p>
            <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" class="form-stack" data-register-form enctype="multipart/form-data">
                <?= csrf_field() ?>
                <label>Full name<input name="full_name" value="<?= e($values['full_name']) ?>" autocomplete="name" required maxlength="160"></label>
                <div class="form-row">
                    <label>Phone number<input name="phone" type="tel" value="<?= e($values['phone']) ?>" autocomplete="tel" placeholder="+254 7XX XXX XXX" required></label>
                    <label>Email address<input name="email" type="email" value="<?= e($values['email']) ?>" autocomplete="email" required maxlength="254"></label>
                </div>
                <label>I'm joining as
                    <select name="role" required data-role-select>
                        <option value="">Choose your account type</option>
                        <option value="student_parent" <?= $values['role'] === 'student_parent' ? 'selected' : '' ?>>Student / Parent</option>
                        <option value="customer" <?= $values['role'] === 'customer' ? 'selected' : '' ?>>Home services customer</option>
                        <option value="tutor" <?= $values['role'] === 'tutor' ? 'selected' : '' ?>>Tutor</option>
                        <option value="service_provider" <?= $values['role'] === 'service_provider' ? 'selected' : '' ?>>Home service provider</option>
                    </select>
                </label>
                <div class="provider-fields" data-provider-fields <?= in_array($values['role'], ['tutor', 'service_provider'], true) ? '' : 'hidden' ?>>
                    <label>Qualifications or experience<textarea name="qualifications" rows="3" maxlength="5000"><?= e($values['qualifications']) ?></textarea></label>
                    <div class="form-row">
                        <label>Town / service area<input name="location" value="<?= e($values['location']) ?>" maxlength="180"></label>
                        <label>Service mode<select name="service_mode" data-service-mode>
                            <?php if ($values['role'] === 'service_provider'): ?>
                                <option value="physical" selected>Physical only</option>
                            <?php else: ?>
                                <option value="both" <?= $values['service_mode'] === 'both' ? 'selected' : '' ?>>Online & physical</option>
                                <option value="online" <?= $values['service_mode'] === 'online' ? 'selected' : '' ?>>Online only</option>
                                <option value="physical" <?= $values['service_mode'] === 'physical' ? 'selected' : '' ?>>Physical only</option>
                            <?php endif; ?>
                        </select></label>
                    </div>
                    <?php foreach (['tutoring' => 'Subjects & learning support', 'home_services' => 'Home services offered'] as $module => $label): ?>
                        <fieldset class="category-picker" data-role-category="<?= e($module) ?>" <?= ($values['role'] === 'tutor' && $module === 'tutoring') || ($values['role'] === 'service_provider' && $module === 'home_services') ? '' : 'hidden' ?>>
                            <legend><?= e($label) ?> <span class="optional">(select all that apply)</span></legend>
                            <div class="category-checkbox-grid">
                                <?php foreach ($categoryOptions as $option): ?>
                                    <?php if ($option['module'] === $module): ?>
                                        <label class="check-row"><input type="checkbox" name="categories[]" value="<?= (int) $option['id'] ?>" <?= in_array((int) $option['id'], $selectedCategories, true) ? 'checked' : '' ?>><span><?= e($option['name']) ?></span></label>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <fieldset class="category-picker" data-tutor-levels <?= $values['role'] === 'tutor' ? '' : 'hidden' ?>>
                                    <legend>Levels you can teach <span class="optional">(select all that apply)</span></legend>
                                    <div class="category-checkbox-grid">
                                        <?php foreach ($teachingLevels as $level): ?>
                                            <label class="check-row"><input type="checkbox" name="teaching_levels[]" value="<?= (int) $level['id'] ?>" <?= in_array((int) $level['id'], $selectedTeachingLevels, true) ? 'checked' : '' ?>><span><?= e($level['name']) ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                    <div class="form-row">
                        <label>National ID (PDF, JPG, or PNG; max 10 MB)<input type="file" name="national_id" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></label>
                        <label>Qualification certificate (PDF, JPG, or PNG; max 10 MB)<input type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></label>
                    </div>
                    <p class="form-footnote">Both documents are required for provider registration. They are stored privately and must be approved before your profile can be listed.</p>
                </div>
                <div class="form-row">
                    <label>Password<span class="password-input-wrap"><input type="password" name="password" autocomplete="new-password" minlength="10" required><button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true" data-eye-open><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg viewBox="0 0 24 24" aria-hidden="true" data-eye-closed hidden><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.4 3.8-.9"/></svg></button></span></label>
                    <label>Confirm password<span class="password-input-wrap"><input type="password" name="password_confirm" autocomplete="new-password" minlength="10" required><button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true" data-eye-open><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg viewBox="0 0 24 24" aria-hidden="true" data-eye-closed hidden><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.4 0 2.7-.4 3.8-.9"/></svg></button></span></label>
                </div>
                <p class="form-footnote">At least 10 characters. Your password is securely hashed, never stored as plain text.</p>
                <button class="button button-primary button-full" type="submit">Create account</button>
            </form>
            <p class="auth-switch">Already with us? <a href="<?= e(url('Pages/login.php')) ?>">Log in</a></p>
        </section>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
