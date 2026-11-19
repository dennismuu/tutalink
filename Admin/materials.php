<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');
if (!is_role('super_admin') && !admin_has_permission('tutoring', 'manage_services')) {
    page_error('Your administrator account is not assigned to revision-material management.', 403);
}

$errors = [];
$uploadDirectory = private_storage_directory('revision-materials');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'visibility') {
        $id = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
        $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || !in_array($active, [0, 1], true)) $errors[] = 'Choose a valid material and visibility.';
        else {
            $exists = db()->prepare('SELECT id FROM revision_materials WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) $errors[] = 'This revision material could not be found.';
            else {
                db()->prepare('UPDATE revision_materials SET is_active = ? WHERE id = ?')->execute([$active, $id]);
                flash('success', 'Material visibility updated.');
                header('Location: ' . url('Admin/materials.php'));
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            $errors[] = 'Choose a valid material to delete.';
        } else {
            $pdo = db();
            $quarantinedPath = null;
            $originalPath = null;
            try {
                $pdo->beginTransaction();
                $materialQuery = $pdo->prepare('SELECT stored_filename FROM revision_materials WHERE id = ? FOR UPDATE');
                $materialQuery->execute([$id]);
                $material = $materialQuery->fetch();
                if (!$material) {
                    $pdo->rollBack();
                    $errors[] = 'This revision material could not be found.';
                } else {
                    $ordersQuery = $pdo->prepare('SELECT COUNT(*) FROM revision_material_orders WHERE material_id = ?');
                    $ordersQuery->execute([$id]);
                    if ((int) $ordersQuery->fetchColumn() > 0) {
                        $pdo->rollBack();
                        $errors[] = 'This material has purchase history and cannot be permanently deleted. Hide it to remove it from sale.';
                    } elseif (!preg_match('/^[a-f0-9]{64}\.(?:pdf|jpg|png)$/', (string) $material['stored_filename'])) {
                        $pdo->rollBack();
                        $errors[] = 'The material file reference is invalid; the record was not deleted.';
                    } else {
                        $originalPath = $uploadDirectory . DIRECTORY_SEPARATOR . $material['stored_filename'];
                        if (is_file($originalPath)) {
                            $quarantinedPath = $originalPath . '.delete-' . bin2hex(random_bytes(8));
                            if (!rename($originalPath, $quarantinedPath)) {
                                $quarantinedPath = null;
                                $pdo->rollBack();
                                $errors[] = 'The material file could not be prepared for deletion.';
                            }
                        }
                        if ($pdo->inTransaction()) {
                            $pdo->prepare('DELETE FROM revision_materials WHERE id = ?')->execute([$id]);
                            $pdo->commit();
                            if ($quarantinedPath && !unlink($quarantinedPath)) {
                                error_log('LertFind could not remove a deleted revision material file.');
                                flash('error', 'The material record was deleted, but its private file could not be removed. Contact support.');
                            } else {
                                flash('success', 'Revision material deleted.');
                            }
                            header('Location: ' . url('Admin/materials.php'));
                            exit;
                        }
                    }
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($quarantinedPath && $originalPath && is_file($quarantinedPath) && !rename($quarantinedPath, $originalPath)) {
                    error_log('LertFind could not restore a revision material file after a failed delete.');
                }
                $errors[] = $exception->getCode() === '23000'
                    ? 'This material has purchase history and cannot be permanently deleted. Hide it to remove it from sale.'
                    : 'The revision material could not be deleted.';
            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($quarantinedPath && $originalPath && is_file($quarantinedPath) && !rename($quarantinedPath, $originalPath)) {
                    error_log('LertFind could not restore a revision material file after a failed delete.');
                }
                $errors[] = $exception->getMessage();
            }
        }
    } elseif ($action === 'upload') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $file = $_FILES['material_file'] ?? [];
        if ($title === '' || mb_strlen($title) > 180) $errors[] = 'Enter a title up to 180 characters.';
        if (mb_strlen($description) > 5000) $errors[] = 'Keep the description under 5,000 characters.';
        if ($price === false || $price <= 0 || $price > 1000000) $errors[] = 'Enter a price between KES 1 and KES 1,000,000.';
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Choose a PDF, JPEG, or PNG material file.';
        if (!$errors) {
            $storedPath = null;
            try {
                if (!class_exists('finfo')) throw new RuntimeException('Enable PHP fileinfo before accepting material uploads.');
                if ((int) $file['size'] <= 0 || (int) $file['size'] > 25 * 1024 * 1024 || !is_uploaded_file((string) $file['tmp_name'])) {
                    throw new InvalidArgumentException('Materials must be valid files no larger than 25 MB.');
                }
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
                $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
                if (!is_string($mime) || !isset($extensions[$mime])) throw new InvalidArgumentException('Upload a PDF, JPEG, or PNG file.');
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0700, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Private material storage could not be created.');
                }
                if (!is_writable($uploadDirectory)) throw new RuntimeException('Private material storage is not writable.');
                $storedName = bin2hex(random_bytes(32)) . '.' . $extensions[$mime];
                $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
                if (!move_uploaded_file((string) $file['tmp_name'], $storedPath)) {
                    throw new RuntimeException('The uploaded material could not be saved.');
                }
                $originalName = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 255);
                db()->prepare('INSERT INTO revision_materials (title, description, stored_filename, original_filename, mime_type, file_size, price, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$title, $description ?: null, $storedName, $originalName, $mime, (int) $file['size'], $price, current_user()['id']]);
                flash('success', 'Revision material uploaded.');
                header('Location: ' . url('Admin/materials.php'));
                exit;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($storedPath && is_file($storedPath)) unlink($storedPath);
                $errors[] = $exception->getCode() === '23000'
                    ? 'This revision material could not be saved because of a conflicting record.'
                    : 'The revision material could not be saved.';
            } catch (RuntimeException $exception) {
                if ($storedPath && is_file($storedPath)) unlink($storedPath);
                $errors[] = $exception->getMessage();
            }
        }
    } elseif ($action === 'price') {
        $id = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!$id || $price === false || $price <= 0 || $price > 1000000) $errors[] = 'Enter a valid material price.';
        else {
            $exists = db()->prepare('SELECT id FROM revision_materials WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) $errors[] = 'This revision material could not be found.';
            else {
                db()->prepare('UPDATE revision_materials SET price = ? WHERE id = ?')->execute([$price, $id]);
                flash('success', 'Material price updated. Existing purchases keep their original price.');
                header('Location: ' . url('Admin/materials.php'));
                exit;
            }
        }
    } else $errors[] = 'That action is not available.';
}
try {
    $materials = db()->query('SELECT id, title, description, price, is_active, original_filename, file_size FROM revision_materials ORDER BY created_at DESC')->fetchAll();
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
$pageTitle = 'Revision materials';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap"><div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">STUDY RESOURCES</div><h1>Manage revision materials.</h1><p>Upload private resources, set/change prices, and control availability.</p></div></div>
    <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <section class="catalogue-management"><h2>Upload material</h2><form method="post" enctype="multipart/form-data" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="upload"><label>Title<input name="title" maxlength="180" required></label><label>Description<textarea name="description" rows="3" maxlength="5000"></textarea></label><div class="form-row"><label>Price (KES)<input name="price" type="number" min="1" max="1000000" step="0.01" required></label><label>File (PDF, JPG, PNG; max 25 MB)<input type="file" name="material_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label></div><button class="button button-primary" type="submit">Upload material</button></form></section>
    <section class="material-admin-list"><?php foreach ($materials as $material): ?><article class="material-card"><span class="status <?= $material['is_active'] ? 'status-accepted' : 'status-suspended' ?>"><?= $material['is_active'] ? 'Visible' : 'Hidden' ?></span><h2><?= e($material['title']) ?></h2><p><?= e($material['original_filename']) ?> · <?= number_format((int) $material['file_size'] / 1048576, 1) ?> MB</p><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="price"><input type="hidden" name="material_id" value="<?= (int) $material['id'] ?>"><label>Price (KES)<input type="number" name="price" min="1" max="1000000" step="0.01" value="<?= e((string) $material['price']) ?>" required></label><button class="button button-outline button-small" type="submit">Save price</button></form><div class="inline-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="visibility"><input type="hidden" name="material_id" value="<?= (int) $material['id'] ?>"><input type="hidden" name="is_active" value="<?= $material['is_active'] ? '0' : '1' ?>"><button class="button button-outline button-small" type="submit"><?= $material['is_active'] ? 'Hide' : 'Show' ?></button></form><form method="post" data-confirm="Permanently delete this material? Materials with purchase history can only be hidden."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="material_id" value="<?= (int) $material['id'] ?>"><button class="button button-outline button-small user-delete-button" type="submit">Delete</button></form></div></article><?php endforeach; ?></section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
