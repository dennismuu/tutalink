<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!current_user()) {
    $_SESSION['return_to'] = 'Pages/materials.php';
    header('Location: ' . url('Pages/login.php'));
    exit;
}
require_roles('student_parent');
$materialId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$materialId || $materialId < 1) page_error('Choose a valid revision material.', 400);
try {
    $query = db()->prepare(
        "SELECT m.stored_filename, m.original_filename, m.mime_type
         FROM revision_material_orders o JOIN revision_materials m ON m.id = o.material_id
         WHERE o.material_id = ? AND o.customer_id = ? AND o.status = 'paid'
         ORDER BY o.id DESC LIMIT 1"
    );
    $query->execute([$materialId, current_user()['id']]);
    $material = $query->fetch();
    if (!$material) page_error('Complete payment before downloading this material.', 403);
    $directory = private_storage_directory('revision-materials');
    $path = $directory . DIRECTORY_SEPARATOR . (string) $material['stored_filename'];
    if (!preg_match('/^[a-f0-9]{64}\.(pdf|jpg|png)$/', (string) $material['stored_filename']) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The purchased material is not available in private storage.');
    }
    header('Content-Type: ' . $material['mime_type']);
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename((string) $material['original_filename'])));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
