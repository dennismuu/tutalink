<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application environment
|--------------------------------------------------------------------------
*/

define('APP_ROOT', dirname(__DIR__));

$appEnvironment = strtolower(trim((string) (getenv('TUTALINK_ENV') ?: 'local')));
define('APP_ENV', $appEnvironment);

date_default_timezone_set('Africa/Nairobi');

/*
|--------------------------------------------------------------------------
| Session configuration
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (
        (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (APP_ENV === 'production')
    );

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| URL helpers
|--------------------------------------------------------------------------
*/

function url(string $path = ''): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if (
        str_ends_with($base, '/Pages')
        || str_ends_with($base, '/Tutor')
        || str_ends_with($base, '/Homeservices')
        || str_ends_with($base, '/Admin')
    ) {
        $base = rtrim(str_replace('\\', '/', dirname($base)), '/');
    }

    return ($base === '' || $base === '/' ? '' : $base)
        . '/'
        . ltrim($path, '/');
}

function app_absolute_url(string $path): string
{
    $base = trim((string) getenv('TUTALINK_APP_URL'));
    $parts = parse_url($base);

    if (
        $base === ''
        || !is_array($parts)
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
    ) {
        throw new RuntimeException(
            'Set TUTALINK_APP_URL to the public base URL of this website before sending account emails.'
        );
    }

    if (
        APP_ENV === 'production'
        && strtolower((string) $parts['scheme']) !== 'https'
    ) {
        throw new RuntimeException(
            'Account email links must use HTTPS in production.'
        );
    }

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/*
|--------------------------------------------------------------------------
| Email
|--------------------------------------------------------------------------
*/

function send_app_email(string $recipient, string $subject, string $body): bool
{
    $fromAddress = trim(
        (string) (getenv('TUTALINK_MAIL_FROM') ?: 'no-reply@tutalink.co.ke')
    );

    $fromName = trim(
        (string) (getenv('TUTALINK_MAIL_FROM_NAME') ?: 'LertFind')
    );

    if (
        !filter_var($recipient, FILTER_VALIDATE_EMAIL)
        || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $subject)
        || preg_match('/[\r\n]/', $fromName)
    ) {
        return false;
    }

    $headers = [
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromAddress . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return mail(
        $recipient,
        mb_encode_mimeheader($subject, 'UTF-8'),
        $body,
        implode("\r\n", $headers)
    );
}

/*
|--------------------------------------------------------------------------
| Output escaping
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Private file storage
|--------------------------------------------------------------------------
*/

function private_storage_directory(string $name): string
{
    if (!in_array($name, ['provider-documents', 'revision-materials'], true)) {
        throw new RuntimeException(
            'The requested private storage location is not available.'
        );
    }

    $configuredRoot = trim(
        (string) getenv('TUTALINK_PRIVATE_STORAGE_DIR')
    );

    if ($configuredRoot === '') {
        if (APP_ENV === 'production') {
            throw new RuntimeException(
                'Private file storage is not configured. Set TUTALINK_PRIVATE_STORAGE_DIR to a persistent private storage location.'
            );
        }

        $configuredRoot = dirname(APP_ROOT, 2)
            . DIRECTORY_SEPARATOR
            . 'lertfind-private';
    } elseif (
        !str_starts_with($configuredRoot, DIRECTORY_SEPARATOR)
        && !preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredRoot)
    ) {
        throw new RuntimeException(
            'TUTALINK_PRIVATE_STORAGE_DIR must be an absolute path outside the public web root.'
        );
    }

    $directory = rtrim($configuredRoot, '/\\')
        . DIRECTORY_SEPARATOR
        . $name;

    if (
        !is_dir($directory)
        && !mkdir($directory, 0700, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            'Private file storage could not be created.'
        );
    }

    if (!is_writable($directory)) {
        throw new RuntimeException(
            'Private file storage is not writable.'
        );
    }

    $resolved = realpath($directory);
    $webRoot = realpath(APP_ROOT);

    if ($resolved === false || $webRoot === false) {
        throw new RuntimeException(
            'Private file storage could not be resolved.'
        );
    }

    $resolvedPath = strtolower(
        rtrim(str_replace('\\', '/', $resolved), '/') . '/'
    );

    $webRootPath = strtolower(
        rtrim(str_replace('\\', '/', $webRoot), '/') . '/'
    );

    if (str_starts_with($resolvedPath, $webRootPath)) {
        throw new RuntimeException(
            'Private file storage must be outside the public web root.'
        );
    }

    return $resolved;
}

function provider_document_storage_directory(): string
{
    return private_storage_directory('provider-documents');
}

function store_provider_document_upload(array $upload): array
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_NO_FILE => 'Choose a document to upload.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Each provider document must be no larger than 10 MB.',
            default =>
                'The provider document could not be uploaded. Please try again.',
        };

        throw new InvalidArgumentException($message);
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);

    if (
        $temporaryPath === ''
        || !is_uploaded_file($temporaryPath)
        || $size <= 0
        || $size > 10 * 1024 * 1024
    ) {
        throw new InvalidArgumentException(
            'Each provider document must be a valid file no larger than 10 MB.'
        );
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException(
            'File type verification is unavailable. Enable PHP fileinfo before accepting documents.'
        );
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);

    $extensions = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
        throw new InvalidArgumentException(
            'Upload a National ID or certificate as a PDF, JPEG, or PNG file.'
        );
    }

    $storedFilename = bin2hex(random_bytes(32))
        . '.'
        . $extensions[$mimeType];

    $path = provider_document_storage_directory()
        . DIRECTORY_SEPARATOR
        . $storedFilename;

    if (!move_uploaded_file($temporaryPath, $path)) {
        throw new RuntimeException(
            'The provider document could not be saved to private storage.'
        );
    }

    $originalFilename = basename(
        str_replace('\\', '/', (string) ($upload['name'] ?? 'document'))
    );

    return [
        'stored_filename' => $storedFilename,
        'original_filename' => mb_substr($originalFilename, 0, 255),
        'mime_type' => $mimeType,
        'file_size' => $size,
    ];
}

function provider_document_file_path(string $storedFilename): string
{
    if (!preg_match('/^[a-f0-9]{64}\.(?:pdf|jpg|png)$/', $storedFilename)) {
        throw new RuntimeException(
            'The requested provider document is not available.'
        );
    }

    $path = provider_document_storage_directory()
        . DIRECTORY_SEPARATOR
        . $storedFilename;

    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException(
            'The requested provider document is not available.'
        );
    }

    return $path;
}

function delete_provider_document_file(string $storedFilename): void
{
    if (!preg_match('/^[a-f0-9]{64}\.(?:pdf|jpg|png)$/', $storedFilename)) {
        throw new RuntimeException(
            'The provider document could not be removed from private storage.'
        );
    }

    $path = provider_document_storage_directory()
        . DIRECTORY_SEPARATOR
        . $storedFilename;

    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException(
            'The provider document could not be removed from private storage.'
        );
    }
}

function discard_provider_document_uploads(array $documents): void
{
    foreach ($documents as $document) {
        try {
            delete_provider_document_file(
                (string) ($document['stored_filename'] ?? '')
            );
        } catch (RuntimeException $exception) {
            error_log(
                'LertFind could not remove a temporary provider document after a failed save.'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Payout encryption
|--------------------------------------------------------------------------
*/

function payout_encryption_key(): string
{
    $configuredKey = trim(
        (string) getenv('TUTALINK_PAYOUT_ENCRYPTION_KEY')
    );

    if ($configuredKey === '' && APP_ENV !== 'local') {
        throw new RuntimeException(
            'Set TUTALINK_PAYOUT_ENCRYPTION_KEY to a secret value before enabling tutor payouts.'
        );
    }

    return hash(
        'sha256',
        $configuredKey !== ''
            ? $configuredKey
            : 'local-only-' . APP_ROOT,
        true
    );
}

function encrypt_payout_details(string $details): string
{
    $nonce = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $details,
        'aes-256-gcm',
        payout_encryption_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException(
            'Payout details could not be encrypted.'
        );
    }

    return 'gcm:' . base64_encode($nonce . $tag . $ciphertext);
}

function decrypt_payout_details(string $encryptedDetails): string
{
    if (str_starts_with($encryptedDetails, 'gcm:')) {
        $payload = base64_decode(
            substr($encryptedDetails, 4),
            true
        );

        if ($payload === false || strlen($payload) <= 28) {
            throw new RuntimeException(
                'Stored payout details are invalid.'
            );
        }

        $details = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            payout_encryption_key(),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16)
        );

        if ($details === false) {
            throw new RuntimeException(
                'Payout details could not be decrypted. Check TUTALINK_PAYOUT_ENCRYPTION_KEY.'
            );
        }

        return $details;
    }

    // Backward compatibility for previously encrypted AES-256-CBC values.
    $payload = base64_decode($encryptedDetails, true);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');

    if (
        $payload === false
        || $ivLength === false
        || strlen($payload) <= $ivLength
    ) {
        throw new RuntimeException(
            'Stored payout details are invalid.'
        );
    }

    $details = openssl_decrypt(
        substr($payload, $ivLength),
        'aes-256-cbc',
        payout_encryption_key(),
        OPENSSL_RAW_DATA,
        substr($payload, 0, $ivLength)
    );

    if ($details === false) {
        throw new RuntimeException(
            'Payout details could not be decrypted. Check TUTALINK_PAYOUT_ENCRYPTION_KEY.'
        );
    }

    return $details;
}

/*
|--------------------------------------------------------------------------
| TiDB Cloud database connection
|--------------------------------------------------------------------------
*/

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException(
            'The PHP pdo_mysql extension is not enabled in this deployment.'
        );
    }

    $host = trim((string) getenv('TIDB_HOST'));
    $name = trim((string) (getenv('TIDB_DB') ?: 'test'));
    $user = trim((string) getenv('TIDB_USER'));
    $password = (string) (getenv('TIDB_PASSWORD') ?: '');

    $portValue = getenv('TIDB_PORT');

    $port = (
        $portValue === false || $portValue === ''
    )
        ? 4000
        : filter_var(
            $portValue,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 65535,
                ],
            ]
        );

    if ($port === false) {
        throw new RuntimeException(
            'TIDB_PORT must be a valid TCP port between 1 and 65535.'
        );
    }

    if (
        $host === ''
        || $name === ''
        || $user === ''
        || $password === ''
    ) {
        throw new RuntimeException(
            'Database configuration is incomplete. Check TIDB_HOST, TIDB_DB, TIDB_USER, and TIDB_PASSWORD in your deployment environment.'
        );
    }

    /*
     * TiDB Cloud requires TLS.
     * Supply the TiDB CA certificate using TIDB_SSL_CA.
     *
     * The certificate path may be absolute or relative to APP_ROOT.
     */
    $caCertificate = trim((string) getenv('TIDB_SSL_CA'));

    if ($caCertificate === '') {
        throw new RuntimeException(
            'TIDB_SSL_CA is missing. Configure the TiDB Cloud CA certificate path to enable verified TLS.'
        );
    }

    if (
        !str_starts_with($caCertificate, DIRECTORY_SEPARATOR)
        && !preg_match('/^[A-Za-z]:[\\\\\/]/', $caCertificate)
    ) {
        $caCertificate = APP_ROOT
            . DIRECTORY_SEPARATOR
            . ltrim($caCertificate, '/\\');
    }

    $caCertificate = realpath($caCertificate);

    if (
        $caCertificate === false
        || !is_file($caCertificate)
        || !is_readable($caCertificate)
    ) {
        throw new RuntimeException(
            'The TiDB CA certificate could not be found or read. Check the TIDB_SSL_CA path.'
        );
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 20,
    ];

    if (!defined('PDO::MYSQL_ATTR_SSL_CA')) {
        throw new RuntimeException(
            'Your PHP PDO MySQL driver does not support SSL CA configuration.'
        );
    }

    $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $caCertificate;

    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
    }

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        $pdo = new PDO(
            $dsn,
            $user,
            $password,
            $options
        );

        return $pdo;

    } catch (PDOException $exception) {
        error_log(
            'LertFind database connection failed: '
            . $exception->getMessage()
        );

        throw new RuntimeException(
            'The LertFind database is unavailable. Check the TiDB host, port, credentials, CA certificate, and PHP PDO MySQL configuration.',
            0,
            $exception
        );
    }
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . e(csrf_token())
        . '">';
}

function csrf_is_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        );
}

function reject_invalid_csrf(): void
{
    if (!csrf_is_valid()) {
        $dashboardRoute = dashboard_notification_route();

        if ($dashboardRoute !== null) {
            flash(
                'error',
                'Your session expired. Refresh the page and try again.'
            );

            header('Location: ' . url($dashboardRoute));
            exit;
        }

        http_response_code(419);

        exit(
            'Your session expired. Go back, refresh the page, and try again.'
        );
    }
}

function dashboard_notification_route(): ?string
{
    $scriptName = str_replace(
        '\\',
        '/',
        $_SERVER['SCRIPT_NAME'] ?? ''
    );

    if (
        preg_match(
            '~/(Pages/(dashboard|pay|pay_checkout)\.php|Admin/[A-Za-z0-9_-]+\.php)$~i',
            $scriptName,
            $matches
        )
    ) {
        if (str_starts_with(strtolower($matches[1]), 'pages/pay')) {
            return 'Pages/dashboard.php';
        }

        return $matches[1];
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return $message;
}

/*
|--------------------------------------------------------------------------
| Authentication and user sessions
|--------------------------------------------------------------------------
*/

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_role(string ...$roles): bool
{
    return in_array(
        current_user()['role'] ?? '',
        $roles,
        true
    );
}

function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role_slug'],
    ];
}

function require_login(): void
{
    $user = current_user();

    if (!$user) {
        $scriptName = str_replace(
            '\\',
            '/',
            $_SERVER['SCRIPT_NAME'] ?? ''
        );

        $loginPage = preg_match('~/Admin/~i', $scriptName)
            ? 'Admin/login.php'
            : 'Pages/login.php';

        $_SESSION['return_to'] = 'Pages/dashboard.php';

        header('Location: ' . url($loginPage));
        exit;
    }

    $statement = db()->prepare(
        'SELECT u.is_active, r.slug AS role_slug
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1'
    );

    $statement->execute([$user['id']]);

    $account = $statement->fetch();

    if (
        !$account
        || !(int) $account['is_active']
        || !hash_equals($user['role'], $account['role_slug'])
    ) {
        unset($_SESSION['user']);

        flash(
            'error',
            'Your account access has changed. Please sign in again.'
        );

        header('Location: ' . url('Pages/login.php'));
        exit;
    }
}

function require_roles(string ...$roles): void
{
    require_login();

    if (!is_role(...$roles)) {
        http_response_code(403);

        exit('You do not have permission to access this page.');
    }
}

/*
|--------------------------------------------------------------------------
| Administrator permissions
|--------------------------------------------------------------------------
*/

function admin_has_permission(
    string $module,
    string $permission
): bool {
    if (is_role('super_admin')) {
        return true;
    }

    if (!is_role('admin1', 'admin2')) {
        return false;
    }

    $allowedModules = [
        'tutoring',
        'home_services',
    ];

    $allowedPermissions = [
        'manage_providers',
        'manage_bookings',
        'manage_payments',
        'handle_complaints',
        'manage_services',
        'view_reports',
    ];

    if (
        !in_array($module, $allowedModules, true)
        || !in_array($permission, $allowedPermissions, true)
    ) {
        return false;
    }

    $statement = db()->prepare(
        "SELECT {$permission}
         FROM admin_permissions
         WHERE user_id = ?
         AND module = ?"
    );

    $statement->execute([
        current_user()['id'],
        $module,
    ]);

    return (bool) $statement->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| Redirect after login
|--------------------------------------------------------------------------
*/

function redirect_after_login(): never
{
    $service = filter_var(
        $_SESSION['pending_service_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $provider = filter_var(
        $_SESSION['pending_provider_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($service) {
        unset(
            $_SESSION['pending_service_id'],
            $_SESSION['pending_provider_id']
        );

        $target = 'Pages/booking.php?service=' . $service;

        if ($provider) {
            $target .= '&provider=' . $provider;
        }

        header('Location: ' . url($target));
        exit;
    }

    $target = $_SESSION['return_to'] ?? 'Pages/dashboard.php';

    unset($_SESSION['return_to']);

    if (
        !preg_match(
            '~^(Pages|Tutor|Homeservices|Admin)/[A-Za-z0-9_./?=&-]*$~',
            $target
        )
    ) {
        $target = 'Pages/dashboard.php';
    }

    header('Location: ' . url($target));
    exit;
}

/*
|--------------------------------------------------------------------------
| Page errors
|--------------------------------------------------------------------------
*/

function page_error(string $message, int $status = 500): never
{
    $dashboardRoute = dashboard_notification_route();

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && $dashboardRoute !== null
    ) {
        flash('error', $message);

        header('Location: ' . url($dashboardRoute));
        exit;
    }

    http_response_code($status);

    $title = $status === 403
        ? 'Access restricted'
        : 'We could not load this page';

    require APP_ROOT . '/includes/header.php';

    echo '<main class="container page-wrap">';
    echo '<section class="notice notice-error">';
    echo '<h1>' . e($title) . '</h1>';
    echo '<p>' . e($message) . '</p>';
    echo '</section>';
    echo '</main>';

    require APP_ROOT . '/includes/footer.php';

    exit;
}
