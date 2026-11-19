<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');

$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$documentId) page_error('This provider document is not available.', 404);

try {
    $query = db()->prepare(
        'SELECT d.provider_id, d.stored_filename, d.original_filename, d.mime_type
         FROM provider_documents d WHERE d.id = ?'
    );
    $query->execute([$documentId]);
    $document = $query->fetch();
    if (!$document) page_error('This provider document is not available.', 404);

    $moduleQuery = db()->prepare(
        'SELECT DISTINCT c.module
         FROM provider_services ps JOIN service_categories c ON c.id = ps.category_id
         WHERE ps.provider_id = ?'
    );
    $moduleQuery->execute([$document['provider_id']]);
    $modules = $moduleQuery->fetchAll(PDO::FETCH_COLUMN);
    $authorized = is_role('super_admin');
    foreach ($modules as $module) {
        if (admin_has_permission((string) $module, 'manage_providers')) $authorized = true;
    }
    if (!$authorized) page_error('You do not have permission to view this provider document.', 403);

    $contentTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($document['mime_type'], $contentTypes, true)) {
        page_error('This provider document has an unsupported file type.', 500);
    }
    $path = provider_document_file_path((string) $document['stored_filename']);
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
} catch (PDOException $exception) {
    error_log('LertFind provider document download lookup failed.');
    page_error('This provider document could not be loaded. Please try again.', 503);
}

$extension = pathinfo((string) $document['stored_filename'], PATHINFO_EXTENSION);
$downloadName = 'provider-document-' . (int) $documentId . '.' . $extension;
header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$handle = fopen($path, 'rb');
if ($handle === false) page_error('This provider document could not be read.', 503);
fpassthru($handle);
fclose($handle);
exit;
