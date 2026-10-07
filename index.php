<?php

/**
 * i18n Translation API
 * PHP 8.4 + SQLite, without framework dependencies.
 */

declare(strict_types=1);

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) {
            $value = substr($value, 1, -1);
        }
        putenv(trim($name) . '=' . $value);
    }
}

$apiToken = trim((string) (getenv('I18N_API_TOKEN') ?: ''));
$dbFile = __DIR__ . '/database.sqlite';
require_once __DIR__ . '/backup.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeLanguage(string $language): string
{
    return str_replace('_', '-', trim($language));
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS source_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL,
        message TEXT NOT NULL,
        UNIQUE(category, message)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER NOT NULL,
        language TEXT NOT NULL,
        translation TEXT,
        PRIMARY KEY(id, language),
        FOREIGN KEY(id) REFERENCES source_messages(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin', 'user')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    $hasRole = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'role') {
            $hasRole = true;
            break;
        }
    }
    if (!$hasRole) {
        // Existing installations had full access before roles existed.
        $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'admin' CHECK(role IN ('admin', 'user'))");
    }
}

function updaterEncryptionKey(): string
{
    $secret = trim((string) (getenv('I18N_UPDATER_ENCRYPTION_KEY') ?: getenv('I18N_API_TOKEN')));
    if ($secret === '') {
        throw new RuntimeException('I18N_API_TOKEN or I18N_UPDATER_ENCRYPTION_KEY must be configured.');
    }
    return hash('sha256', 'i18n-updater-key|' . $secret, true);
}

function encryptUpdaterValue(string $value): string
{
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', updaterEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) {
        throw new RuntimeException('Could not encrypt updater settings.');
    }
    return base64_encode($iv . $tag . $encrypted);
}

function decryptUpdaterValue(string $value): string
{
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < 28) {
        throw new RuntimeException('Stored updater key is invalid.');
    }
    $iv = substr($decoded, 0, 12);
    $tag = substr($decoded, 12, 16);
    $encrypted = substr($decoded, 28);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', updaterEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($decrypted === false) {
        throw new RuntimeException('Could not decrypt updater settings. Check the encryption key.');
    }
    return $decrypted;
}

function updaterSetting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
    $stmt->execute(['key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

function saveUpdaterSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP');
    $stmt->execute(['key' => $key, 'value' => $value]);
}

function updaterPrivateKey(PDO $pdo): ?string
{
    $encrypted = updaterSetting($pdo, 'updater_private_key');
    if ($encrypted === null || $encrypted === '') {
        return null;
    }
    return decryptUpdaterValue($encrypted);
}

function runGitCommand(string $baseDir, array $arguments, array $environment = []): array
{
    $commandParts = ['git', '-C', $baseDir];
    foreach ($arguments as $argument) {
        $commandParts[] = (string) $argument;
    }
    $command = implode(' ', array_map('escapeshellarg', $commandParts));
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $environment = array_merge($_ENV, [
        'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_CONFIG_COUNT' => '1',
        'GIT_CONFIG_KEY_0' => 'safe.directory',
        'GIT_CONFIG_VALUE_0' => $baseDir,
    ], $environment);
    $process = proc_open($command, $descriptors, $pipes, $baseDir, $environment);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Could not start git.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit_code' => $exitCode, 'stdout' => trim((string) $stdout), 'stderr' => trim((string) $stderr)];
}

function gitOutput(string $baseDir, array $arguments, array $environment = []): string
{
    $result = runGitCommand($baseDir, $arguments, $environment);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException($result['stderr'] ?: $result['stdout'] ?: 'Git command failed.');
    }
    return $result['stdout'];
}

function updaterConfig(PDO $pdo, string $baseDir): array
{
    $repository = updaterSetting($pdo, 'updater_repository');
    if ($repository === null) {
        $remote = runGitCommand($baseDir, ['remote', 'get-url', 'origin']);
        $repository = $remote['exit_code'] === 0 ? $remote['stdout'] : '';
    }
    $branch = updaterSetting($pdo, 'updater_branch');
    if ($branch === null || $branch === '') {
        $currentBranch = runGitCommand($baseDir, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $branch = $currentBranch['exit_code'] === 0 ? $currentBranch['stdout'] : 'main';
    }
    return ['repository' => $repository, 'branch' => $branch, 'key_configured' => updaterSetting($pdo, 'updater_private_key') !== null];
}

function validateUpdaterRepository(string $repository): bool
{
    return strlen($repository) <= 255
        && !str_contains($repository, "\n")
        && preg_match('#^(git@[^:\s]+:[^\s]+|ssh://[^\s]+|https?://[^\s]+)$#', $repository) === 1;
}

function validateUpdaterBranch(string $branch): bool
{
    return preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $branch) === 1 && !str_starts_with($branch, '-');
}

function updaterSshEnvironment(string $privateKey): array
{
    if (!str_contains($privateKey, 'PRIVATE KEY')) {
        throw new InvalidArgumentException('The private key does not look like a valid SSH private key.');
    }
    $keyPath = tempnam(sys_get_temp_dir(), 'i18n-ssh-');
    if ($keyPath === false || file_put_contents($keyPath, rtrim($privateKey) . "\n") === false) {
        throw new RuntimeException('Could not stage the SSH private key.');
    }
    chmod($keyPath, 0600);
    return [
        'GIT_SSH_COMMAND' => 'ssh -i ' . escapeshellarg($keyPath) . ' -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15',
        'I18N_UPDATER_KEY_PATH' => $keyPath,
    ];
}

function removeUpdaterKey(array $environment): void
{
    if (!empty($environment['I18N_UPDATER_KEY_PATH'])) {
        @unlink($environment['I18N_UPDATER_KEY_PATH']);
    }
}

function syncUpdaterRemote(PDO $pdo, string $baseDir, string $repository): void
{
    $remote = runGitCommand($baseDir, ['remote', 'get-url', 'origin']);
    if ($remote['exit_code'] !== 0) {
        gitOutput($baseDir, ['remote', 'add', 'origin', $repository]);
    } elseif (trim($remote['stdout']) !== $repository) {
        gitOutput($baseDir, ['remote', 'set-url', 'origin', $repository]);
    }
}

function updaterStatus(PDO $pdo, string $baseDir): array
{
    $config = updaterConfig($pdo, $baseDir);
    $commit = runGitCommand($baseDir, ['rev-parse', '--short', 'HEAD']);
    $branch = runGitCommand($baseDir, ['rev-parse', '--abbrev-ref', 'HEAD']);
    $dirty = runGitCommand($baseDir, ['status', '--porcelain', '--untracked-files=all']);
    return [
        'repository' => $config['repository'],
        'branch' => $branch['exit_code'] === 0 ? $branch['stdout'] : $config['branch'],
        'configured_branch' => $config['branch'],
        'commit' => $commit['exit_code'] === 0 ? $commit['stdout'] : null,
        'key_configured' => $config['key_configured'],
        'dirty' => $dirty['exit_code'] === 0 && $dirty['stdout'] !== '',
        'changes' => $dirty['exit_code'] === 0 ? $dirty['stdout'] : '',
        'ahead' => null,
        'behind' => null,
    ];
}

function checkUpdater(PDO $pdo, string $baseDir): array
{
    $config = updaterConfig($pdo, $baseDir);
    if (!$config['key_configured']) {
        throw new RuntimeException('Configure the SSH private key before checking for updates.');
    }
    if (!validateUpdaterRepository($config['repository'])) {
        throw new RuntimeException('Configure a valid SSH or HTTPS Git repository URL.');
    }
    if (!validateUpdaterBranch($config['branch'])) {
        throw new RuntimeException('Configure a valid Git branch.');
    }
    $environment = updaterSshEnvironment(updaterPrivateKey($pdo) ?? '');
    try {
        syncUpdaterRemote($pdo, $baseDir, $config['repository']);
        $fetch = runGitCommand($baseDir, ['fetch', '--prune', 'origin', $config['branch']], $environment);
        if ($fetch['exit_code'] !== 0) {
            throw new RuntimeException($fetch['stderr'] ?: 'Could not fetch the configured Git branch.');
        }
        $counts = gitOutput($baseDir, ['rev-list', '--left-right', '--count', 'HEAD...origin/' . $config['branch']]);
        $parts = preg_split('/\s+/', trim($counts));
        $status = updaterStatus($pdo, $baseDir);
        if ($status['branch'] !== $config['branch']) {
            throw new RuntimeException("The checked out branch '{$status['branch']}' does not match the configured branch '{$config['branch']}'.");
        }
        $status['ahead'] = (int) ($parts[0] ?? 0);
        $status['behind'] = (int) ($parts[1] ?? 0);
        $status['remote_commit'] = gitOutput($baseDir, ['rev-parse', '--short', 'origin/' . $config['branch']]);
        return $status;
    } finally {
        removeUpdaterKey($environment);
    }
}

function updateApplication(PDO $pdo, string $baseDir): array
{
    $status = checkUpdater($pdo, $baseDir);
    if ($status['dirty']) {
        throw new RuntimeException('Update blocked because the working tree has local changes.');
    }
    if ((int) $status['behind'] === 0) {
        $status['updated'] = false;
        return $status;
    }
    $environment = updaterSshEnvironment(updaterPrivateKey($pdo) ?? '');
    try {
        $pull = runGitCommand($baseDir, ['pull', '--ff-only', 'origin', $status['configured_branch']], $environment);
        if ($pull['exit_code'] !== 0) {
            throw new RuntimeException($pull['stderr'] ?: $pull['stdout'] ?: 'Could not update the application.');
        }
        $status['updated'] = true;
        $status['output'] = $pull['stdout'];
        $status['commit'] = gitOutput($baseDir, ['rev-parse', '--short', 'HEAD']);
        return $status;
    } finally {
        removeUpdaterKey($environment);
    }
}

function createUserToken(array $user, string $apiToken): string
{
    $ttl = (int) (getenv('I18N_TOKEN_TTL') ?: 86400);
    $ttl = max(300, min($ttl, 2592000));
    $payload = [
        'sub' => (int) $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'iat' => time(),
        'exp' => time() + $ttl,
    ];
    $payloadB64 = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payloadB64, $apiToken);
    return $payloadB64 . '.' . $signature;
}

function parseUserToken(string $token, string $apiToken, PDO $pdo): ?array
{
    if (substr_count($token, '.') !== 1) {
        return null;
    }
    [$payloadB64, $signature] = explode('.', $token, 2);
    $expectedSignature = hash_hmac('sha256', $payloadB64, $apiToken);
    if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
        return null;
    }
    $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
    $payload = $payloadJson === false ? null : json_decode($payloadJson, true);
    if (!is_array($payload) || empty($payload['sub']) || empty($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id');
    $stmt->execute(['id' => $payload['sub']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !in_array($user['role'], ['admin', 'user'], true)) {
        return null;
    }

    return [
        'sub' => (int) $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'exp' => (int) $payload['exp'],
    ];
}

function requireAdmin(array $currentUser): void
{
    if (($currentUser['role'] ?? '') !== 'admin') {
        response(['error' => 'Forbidden. Admin role required.'], 403);
    }
}

function readJsonBody(): array
{
    $raw = trim((string) file_get_contents('php://input'));
    if ($raw === '') {
        response(['error' => 'JSON body is required.'], 400);
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        response(['error' => 'Invalid JSON payload.'], 400);
    }
    return $data;
}

function boolValue(mixed $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function backupDatabase(string $dbFile): string
{
    $backupDir = dirname($dbFile) . '/database.backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Could not create the database backup directory.');
    }
    $backupName = 'database-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
    $backupPath = $backupDir . '/' . $backupName;
    if (!copy($dbFile, $backupPath)) {
        throw new RuntimeException('Could not create a database backup.');
    }
    return $backupName;
}

/**
 * Rebuilds the translation data in memory using normalized source keys and languages.
 * Applying the result replaces only source_messages/messages inside one transaction.
 */
function sanitizeDatabase(PDO $pdo, string $dbFile, bool $apply): array
{
    $report = [
        'sources_scanned' => 0,
        'source_normalizations' => 0,
        'duplicate_sources' => 0,
        'empty_sources' => 0,
        'translations_scanned' => 0,
        'language_normalizations' => 0,
        'duplicate_translations' => 0,
        'conflicting_translations' => 0,
        'empty_translations' => 0,
        'orphan_translations' => 0,
        'removed_sources' => 0,
        'removed_translations' => 0,
    ];

    $sourceRows = $pdo->query('SELECT id, category, message FROM source_messages ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $messageRows = $pdo->query('SELECT id, language, translation FROM messages ORDER BY id ASC, language ASC')->fetchAll(PDO::FETCH_ASSOC);
    $report['sources_scanned'] = count($sourceRows);
    $report['translations_scanned'] = count($messageRows);

    $sourceMap = [];
    $cleanSources = [];
    foreach ($sourceRows as $row) {
        $category = trim((string) $row['category']);
        $message = trim((string) $row['message']);
        if ($category === '' || $message === '') {
            $report['empty_sources']++;
            continue;
        }
        if ($category !== $row['category'] || $message !== $row['message']) {
            $report['source_normalizations']++;
        }
        $key = $category . "\0" . $message;
        if (isset($sourceMap[$key])) {
            $sourceMap[(string) $row['id']] = $sourceMap[$key];
            $report['duplicate_sources']++;
            continue;
        }
        $sourceMap[$key] = (int) $row['id'];
        $cleanSources[(int) $row['id']] = ['category' => $category, 'message' => $message];
        $sourceMap[(string) $row['id']] = (int) $row['id'];
    }

    $mergedTranslations = [];
    foreach ($messageRows as $row) {
        $oldId = (int) $row['id'];
        if (!isset($sourceMap[(string) $oldId])) {
            $report['orphan_translations']++;
            continue;
        }
        $targetId = $sourceMap[(string) $oldId];
        $language = normalizeLanguage((string) $row['language']);
        $translation = $row['translation'] === null ? '' : trim((string) $row['translation']);
        if ($language !== (string) $row['language']) {
            $report['language_normalizations']++;
        }
        if ($language === '' || $translation === '') {
            $report['empty_translations']++;
            continue;
        }
        $translationKey = $targetId . "\0" . $language;
        if (isset($mergedTranslations[$translationKey])) {
            $report['duplicate_translations']++;
            if ($mergedTranslations[$translationKey]['translation'] !== $translation) {
                $report['conflicting_translations']++;
            }
            continue;
        }
        $mergedTranslations[$translationKey] = [
            'id' => $targetId,
            'language' => $language,
            'translation' => $translation,
        ];
    }

    $report['removed_sources'] = $report['duplicate_sources'] + $report['empty_sources'];
    $report['removed_translations'] = $report['orphan_translations']
        + $report['empty_translations'] + $report['duplicate_translations'];

    if (!$apply) {
        $report['applied'] = false;
        return $report;
    }

    $backupName = backupDatabase($dbFile);
    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM messages');
        $pdo->exec('DELETE FROM source_messages');

        $insertSource = $pdo->prepare('INSERT INTO source_messages (id, category, message) VALUES (:id, :category, :message)');
        foreach ($cleanSources as $id => $source) {
            $insertSource->execute(['id' => $id, 'category' => $source['category'], 'message' => $source['message']]);
        }
        $insertMessage = $pdo->prepare('INSERT INTO messages (id, language, translation) VALUES (:id, :language, :translation)');
        foreach ($mergedTranslations as $translation) {
            $insertMessage->execute($translation);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $report['applied'] = true;
    $report['backup'] = $backupName;
    return $report;
}

function parseCsvImport(string $content): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    $header = fgetcsv($stream, 0, ',', '"', '\\');
    if (!$header) {
        fclose($stream);
        throw new InvalidArgumentException('The CSV file is empty.');
    }
    $header = array_map(static fn($value): string => trim((string) $value), $header);
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $categoryIndex = array_search('category', $header, true);
    $messageIndex = array_search('message', $header, true);
    $languageColumns = [];
    foreach ($header as $index => $column) {
        if (str_starts_with($column, 'translation_')) {
            $language = normalizeLanguage(substr($column, 12));
            if ($language !== '') {
                $languageColumns[$index] = $language;
            }
        }
    }
    if ($categoryIndex === false || $messageIndex === false || $languageColumns === []) {
        fclose($stream);
        throw new InvalidArgumentException('CSV must contain category, message and at least one translation_* column.');
    }

    $rows = [];
    $invalid = [];
    $line = 1;
    while (($data = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($data === [null] || $data === []) {
            continue;
        }
        $category = trim((string) ($data[$categoryIndex] ?? ''));
        $message = trim((string) ($data[$messageIndex] ?? ''));
        if ($category === '' && $message === '') {
            continue;
        }
        if ($category === '' || $message === '') {
            $invalid[] = ['line' => $line, 'error' => 'category and message are required.'];
            continue;
        }
        $translations = [];
        foreach ($languageColumns as $index => $language) {
            $value = trim((string) ($data[$index] ?? ''));
            if ($value !== '') {
                $translations[$language] = $value;
            }
        }
        $rows[] = ['category' => $category, 'message' => $message, 'translations' => $translations];
    }
    fclose($stream);
    return ['rows' => $rows, 'invalid' => $invalid];
}

function importPreview(PDO $pdo, array $rows): array
{
    $selectSource = $pdo->prepare('SELECT id FROM source_messages WHERE category = :category AND message = :message');
    $checkTranslation = $pdo->prepare('SELECT 1 FROM messages WHERE id = :id AND language = :language');
    $newKeys = 0;
    $existingKeys = 0;
    $translations = 0;
    $conflicts = [];
    foreach ($rows as $rowIndex => $row) {
        $selectSource->execute(['category' => $row['category'], 'message' => $row['message']]);
        $id = $selectSource->fetchColumn();
        if ($id === false) {
            $newKeys++;
        } else {
            $existingKeys++;
            foreach ($row['translations'] as $language => $_translation) {
                $checkTranslation->execute(['id' => $id, 'language' => $language]);
                if ($checkTranslation->fetchColumn() && count($conflicts) < 50) {
                    $conflicts[] = ['row' => $rowIndex + 2, 'category' => $row['category'], 'message' => $row['message'], 'language' => $language];
                }
            }
        }
        $translations += count($row['translations']);
    }
    return ['rows' => count($rows), 'new_keys' => $newKeys, 'existing_keys' => $existingKeys, 'translations' => $translations, 'conflicts' => $conflicts];
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureSchema($pdo);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// The PHP development server must only serve known public assets directly.
if (php_sapi_name() === 'cli-server') {
    $isAsset = str_starts_with($path, '/assets/') || $path === '/robots.txt';
    if ($isAsset && file_exists(__DIR__ . $path) && is_file(__DIR__ . $path)) {
        return false;
    }
}

if ($method === 'GET' && in_array($path, ['/', '/index.php', '/login.php'], true)) {
    require __DIR__ . '/login.php';
    exit;
}

if ($method === 'POST' && in_array($path, ['/api/login', '/api/auth/login'], true)) {
    if ($apiToken === '') {
        response(['error' => 'API token is not configured.'], 500);
    }
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data) || empty($data['username']) || empty($data['password'])) {
        $data = $_POST;
    }
    if (!is_array($data) || trim((string) ($data['username'] ?? '')) === '' || (string) ($data['password'] ?? '') === '') {
        response(['error' => 'Username and password required'], 400);
    }
    $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :username');
    $stmt->execute(['username' => trim((string) $data['username'])]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify((string) $data['password'], $user['password_hash'])) {
        response(['success' => true, 'token' => createUserToken($user, $apiToken), 'role' => $user['role'], 'username' => $user['username']]);
    }
    response(['error' => 'Invalid credentials'], 401);
}

if ($apiToken === '') {
    response(['error' => 'API token is not configured.'], 500);
}
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
$authHeader = $headers['authorization'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', (string) $authHeader, $matches)) {
    response(['error' => 'Unauthorized'], 401);
}
$providedToken = trim($matches[1]);
$currentUser = hash_equals($apiToken, $providedToken) ? ['role' => 'admin', 'username' => 'token'] : parseUserToken($providedToken, $apiToken, $pdo);
if (!$currentUser) {
    response(['error' => 'Unauthorized'], 401);
}

if ($method === 'GET' && $path === '/api/updater/status') {
    requireAdmin($currentUser);
    try {
        response(['success' => true, 'status' => updaterStatus($pdo, __DIR__)]);
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 500);
    }
}

if ($method === 'POST' && $path === '/api/updater/config') {
    requireAdmin($currentUser);
    $data = readJsonBody();
    $repository = trim((string) ($data['repository'] ?? ''));
    $branch = trim((string) ($data['branch'] ?? 'main'));
    $privateKey = trim((string) ($data['private_key'] ?? ''));
    if (!validateUpdaterRepository($repository) || !validateUpdaterBranch($branch)) {
        response(['error' => 'Repository must be an SSH/HTTPS Git URL and branch must be valid.'], 400);
    }
    if ($privateKey === '' && updaterSetting($pdo, 'updater_private_key') === null) {
        response(['error' => 'A private SSH key is required for the first configuration.'], 400);
    }
    if (strlen($privateKey) > 20000) {
        response(['error' => 'The private key is too large.'], 400);
    }
    if ($privateKey !== '' && !str_contains($privateKey, 'PRIVATE KEY')) {
        response(['error' => 'The private key does not look like a valid SSH private key.'], 400);
    }
    try {
        saveUpdaterSetting($pdo, 'updater_repository', $repository);
        saveUpdaterSetting($pdo, 'updater_branch', $branch);
        if ($privateKey !== '') {
            saveUpdaterSetting($pdo, 'updater_private_key', encryptUpdaterValue($privateKey));
        }
        response(['success' => true, 'config' => updaterConfig($pdo, __DIR__)], 200);
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 400);
    }
}

if ($method === 'POST' && $path === '/api/updater/check') {
    requireAdmin($currentUser);
    try {
        response(['success' => true, 'status' => checkUpdater($pdo, __DIR__)]);
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 400);
    }
}

if ($method === 'POST' && $path === '/api/updater/update') {
    requireAdmin($currentUser);
    try {
        response(['success' => true, 'status' => updateApplication($pdo, __DIR__)]);
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 409);
    }
}

if ($method === 'GET' && $path === '/api/backups') {
    requireAdmin($currentUser);
    try {
        response(listBackupArchives(__DIR__));
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 500);
    }
}

if ($method === 'GET' && $path === '/api/backup') {
    requireAdmin($currentUser);
    try {
        $archivePath = createBackupArchive(__DIR__);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($archivePath) . '"');
        header('Content-Length: ' . (string) filesize($archivePath));
        readfile($archivePath);
        exit;
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 500);
    }
}

if ($method === 'POST' && $path === '/api/restore') {
    requireAdmin($currentUser);
    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        response(['error' => 'Please upload a valid backup archive.'], 400);
    }
    if ($_FILES['backup']['size'] > 100 * 1024 * 1024) {
        response(['error' => 'Backup archive exceeds the 100 MB limit.'], 413);
    }
    $temporaryArchive = sys_get_temp_dir() . '/i18n-api-upload-' . bin2hex(random_bytes(8)) . '.tar.gz';
    if (!copy($_FILES['backup']['tmp_name'], $temporaryArchive)) {
        response(['error' => 'Could not stage the uploaded backup.'], 500);
    }
    $restoreResult = null;
    $restoreError = null;
    try {
        $restoreResult = restoreBackupArchive(__DIR__, $temporaryArchive);
    } catch (Throwable $e) {
        $restoreError = $e->getMessage();
    } finally {
        @unlink($temporaryArchive);
    }
    if ($restoreError !== null) {
        response(['error' => $restoreError], 400);
    }
    if (is_array($restoreResult) && !empty($restoreResult['emergency_backup'])) {
        $restoreResult['emergency_backup'] = basename((string) $restoreResult['emergency_backup']);
    }
    response(['success' => true, 'restore' => $restoreResult]);
}

if ($method === 'GET' && $path === '/api/stats') {
    response([
        'keys' => (int) $pdo->query('SELECT COUNT(*) FROM source_messages')->fetchColumn(),
        'translations' => (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE translation IS NOT NULL AND translation <> ''")->fetchColumn(),
        'languages' => (int) $pdo->query("SELECT COUNT(DISTINCT language) FROM messages WHERE language <> ''")->fetchColumn(),
        'categories' => (int) $pdo->query('SELECT COUNT(DISTINCT category) FROM source_messages')->fetchColumn(),
    ]);
}

if ($method === 'POST' && $path === '/api/translate') {
    requireAdmin($currentUser);
    $input = (string) file_get_contents('php://input');
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($input), 'content' => $input, 'ignore_errors' => true, 'timeout' => 30]]);
    $result = @file_get_contents('http://libretranslate:5000/translate', false, $context);
    $status = 200;
    if (isset($http_response_header[0]) && preg_match('#HTTP/[0-9.]+\s+([0-9]+)#', $http_response_header[0], $match)) {
        $status = (int) $match[1];
    }
    if ($result === false) {
        response(['error' => 'Could not connect to Translation Engine.'], 502);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $result;
    exit;
}

if ($method === 'GET' && $path === '/api/pull') {
    $stmt = $pdo->query('SELECT sm.id, sm.category, sm.message, m.language, m.translation FROM source_messages sm LEFT JOIN messages m ON sm.id = m.id ORDER BY sm.id ASC, m.language ASC');
    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $row['id'];
        $results[$id] ??= ['category' => $row['category'], 'message' => $row['message'], 'translations' => []];
        if ($row['language'] !== null && $row['translation'] !== null && $row['translation'] !== '') {
            $results[$id]['translations'][$row['language']] = $row['translation'];
        }
    }
    response(array_values($results));
}

if ($method === 'GET' && $path === '/api/export') {
    $stmt = $pdo->query('SELECT sm.category, sm.message, m.language, m.translation FROM source_messages sm LEFT JOIN messages m ON sm.id = m.id ORDER BY sm.id ASC, m.language ASC');
    $data = [];
    $languages = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['category'] . "\0" . $row['message'];
        $data[$key] ??= ['category' => $row['category'], 'message' => $row['message']];
        if ($row['language'] !== null) {
            $language = normalizeLanguage((string) $row['language']);
            $data[$key]['translation_' . $language] = $row['translation'] ?? '';
            $languages[$language] = true;
        }
    }
    $languages = array_keys($languages);
    sort($languages);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="i18n_messages_app.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_merge(['category', 'message'], array_map(static fn($language): string => 'translation_' . $language, $languages)), ',', '"', '');
    foreach ($data as $row) {
        $csvRow = [$row['category'], $row['message']];
        foreach ($languages as $language) {
            $csvRow[] = $row['translation_' . $language] ?? '';
        }
        fputcsv($output, $csvRow, ',', '"', '');
    }
    fclose($output);
    exit;
}

if ($method === 'POST' && $path === '/api/import') {
    requireAdmin($currentUser);
    $content = '';
    if (isset($_FILES['file'])) {
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            response(['error' => 'Could not upload the CSV file.'], 400);
        }
        if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            response(['error' => 'CSV file exceeds the 10 MB limit.'], 413);
        }
        $content = (string) file_get_contents($_FILES['file']['tmp_name']);
    } else {
        $content = (string) file_get_contents('php://input');
        if (strlen($content) > 10 * 1024 * 1024) {
            response(['error' => 'CSV file exceeds the 10 MB limit.'], 413);
        }
    }
    try {
        $parsed = parseCsvImport($content);
        $preview = importPreview($pdo, $parsed['rows']);
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 400);
    }
    $preview['invalid'] = $parsed['invalid'];
    $isPreview = isset($_POST['preview']) ? boolValue($_POST['preview']) : false;
    if ($isPreview) {
        response(['success' => true, 'preview' => $preview]);
    }
    if ($parsed['invalid'] !== []) {
        response(['error' => 'CSV contains invalid rows. Fix the file and preview it again.', 'preview' => $preview], 400);
    }
    $overwrite = isset($_POST['overwrite']) ? boolValue($_POST['overwrite']) : false;
    try {
        $pdo->beginTransaction();
        $selectSource = $pdo->prepare('SELECT id FROM source_messages WHERE category = :category AND message = :message');
        $insertSource = $pdo->prepare('INSERT INTO source_messages (category, message) VALUES (:category, :message)');
        $checkTranslation = $pdo->prepare('SELECT 1 FROM messages WHERE id = :id AND language = :language');
        $upsertTranslation = $pdo->prepare('INSERT INTO messages (id, language, translation) VALUES (:id, :language, :translation) ON CONFLICT(id, language) DO UPDATE SET translation = excluded.translation');
        $created = 0;
        $updated = 0;
        $translationCount = 0;
        foreach ($parsed['rows'] as $index => $row) {
            $selectSource->execute(['category' => $row['category'], 'message' => $row['message']]);
            $id = $selectSource->fetchColumn();
            if ($id === false) {
                $insertSource->execute(['category' => $row['category'], 'message' => $row['message']]);
                $id = (int) $pdo->lastInsertId();
                $created++;
            } else {
                $updated++;
            }
            foreach ($row['translations'] as $language => $translation) {
                if (!$overwrite) {
                    $checkTranslation->execute(['id' => $id, 'language' => $language]);
                    if ($checkTranslation->fetchColumn()) {
                        throw new DomainException("Translation conflict at CSV row " . ($index + 2) . " for language '$language'.");
                    }
                }
                $upsertTranslation->execute(['id' => $id, 'language' => $language, 'translation' => $translation]);
                $translationCount++;
            }
        }
        $pdo->commit();
        response(['success' => true, 'rows' => count($parsed['rows']), 'created' => $created, 'updated' => $updated, 'translations' => $translationCount, 'invalid' => $parsed['invalid']]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        response(['error' => $e->getMessage(), 'preview' => $preview], $e instanceof DomainException ? 409 : 500);
    }
}

if ($method === 'POST' && $path === '/api/push') {
    requireAdmin($currentUser);
    $input = (string) file_get_contents('php://input');
    if (strlen($input) > 5 * 1024 * 1024) {
        response(['error' => 'Payload exceeds the 5 MB limit.'], 413);
    }
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $data = $_POST;
    }
    $isBatch = is_array($data) && array_is_list($data);
    if (is_array($data) && array_key_exists('items', $data)) {
        if (!is_array($data['items']) || !array_is_list($data['items'])) {
            response(['error' => 'The items property must be an array.'], 400);
        }
        $isBatch = true;
        $data = $data['items'];
    }
    $items = $isBatch ? $data : [$data];
    if (!is_array($items) || $items === []) {
        response(['error' => 'At least one translation item is required.'], 400);
    }
    if (count($items) > 1000) {
        response(['error' => 'A batch may contain at most 1000 translation items.'], 413);
    }
    $validationErrors = [];
    foreach ($items as $index => &$item) {
        if (!is_array($item) || array_is_list($item)) {
            $validationErrors[] = ['index' => $index, 'error' => 'Each item must be an object.'];
            continue;
        }
        if (!isset($item['category'], $item['message']) || !is_scalar($item['category']) || !is_scalar($item['message'])) {
            $validationErrors[] = ['index' => $index, 'error' => 'category and message must be scalar values.'];
            continue;
        }
        $category = trim((string) $item['category']);
        $message = trim((string) $item['message']);
        if ($category === '' || $message === '') {
            $validationErrors[] = ['index' => $index, 'error' => 'category and message are required.'];
            continue;
        }
        if (strlen($category) > 255 || strlen($message) > 65535) {
            $validationErrors[] = ['index' => $index, 'error' => 'category or message exceeds the supported length.'];
            continue;
        }
        if (isset($item['translations']) && (!is_array($item['translations']) || ($item['translations'] !== [] && array_is_list($item['translations'])))) {
            $validationErrors[] = ['index' => $index, 'error' => 'translations must be an object keyed by language.'];
            continue;
        }
        $translations = [];
        foreach (($item['translations'] ?? []) as $language => $translation) {
            $language = normalizeLanguage((string) $language);
            if ($language === '' || strlen($language) > 35) {
                $validationErrors[] = ['index' => $index, 'error' => 'A translation language is invalid.'];
                continue 2;
            }
            if (!is_scalar($translation) && $translation !== null) {
                $validationErrors[] = ['index' => $index, 'error' => 'Translation values must be scalar or null.'];
                continue 2;
            }
            $translations[$language] = $translation === null ? null : (string) $translation;
        }
        $item = ['category' => $category, 'message' => $message, 'translations' => $translations, 'overwrite' => !empty($item['overwrite'])];
    }
    unset($item);
    if ($validationErrors !== []) {
        response(['error' => 'Invalid translation batch.', 'errors' => $validationErrors], 400);
    }
    try {
        $pdo->beginTransaction();
        $selectSource = $pdo->prepare('SELECT id FROM source_messages WHERE category = :category AND message = :message');
        $insertSource = $pdo->prepare('INSERT INTO source_messages (category, message) VALUES (:category, :message)');
        $checkTranslation = $pdo->prepare('SELECT 1 FROM messages WHERE id = :id AND language = :language');
        $upsertTranslation = $pdo->prepare('INSERT INTO messages (id, language, translation) VALUES (:id, :language, :translation) ON CONFLICT(id, language) DO UPDATE SET translation = excluded.translation');
        $created = 0;
        $updated = 0;
        $translationCount = 0;
        $ids = [];
        foreach ($items as $index => $item) {
            $selectSource->execute(['category' => $item['category'], 'message' => $item['message']]);
            $id = $selectSource->fetchColumn();
            if ($id === false) {
                $insertSource->execute(['category' => $item['category'], 'message' => $item['message']]);
                $id = (int) $pdo->lastInsertId();
                $created++;
            } else {
                $updated++;
            }
            if (!$item['overwrite']) {
                foreach ($item['translations'] as $language => $_translation) {
                    $checkTranslation->execute(['id' => $id, 'language' => $language]);
                    if ($checkTranslation->fetchColumn()) {
                        throw new DomainException("Translation for language '$language' already exists at item $index.");
                    }
                }
            }
            foreach ($item['translations'] as $language => $translation) {
                $upsertTranslation->execute(['id' => $id, 'language' => $language, 'translation' => $translation]);
                $translationCount++;
            }
            $ids[] = (int) $id;
        }
        $pdo->commit();
        if (!$isBatch) {
            response(['success' => true, 'id' => $ids[0]]);
        }
        response(['success' => true, 'processed' => count($items), 'created' => $created, 'updated' => $updated, 'translations' => $translationCount, 'ids' => $ids]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        response(['error' => $e->getMessage()], $e instanceof DomainException ? 409 : 500);
    }
}

if ($method === 'GET' && $path === '/api/sanitize/preview') {
    requireAdmin($currentUser);
    try {
        response(['success' => true, 'report' => sanitizeDatabase($pdo, $dbFile, false)]);
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 500);
    }
}

if ($method === 'POST' && $path === '/api/sanitize') {
    requireAdmin($currentUser);
    $data = readJsonBody();
    try {
        response(['success' => true, 'report' => sanitizeDatabase($pdo, $dbFile, boolValue($data['apply'] ?? false))]);
    } catch (Throwable $e) {
        response(['error' => $e->getMessage()], 500);
    }
}

if ($path === '/api/users' && $method === 'GET') {
    requireAdmin($currentUser);
    response($pdo->query('SELECT id, username, role, created_at FROM users ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC));
}

if ($path === '/api/users' && $method === 'POST') {
    requireAdmin($currentUser);
    $data = readJsonBody();
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $role = strtolower(trim((string) ($data['role'] ?? 'user')));
    if ($username === '' || strlen($username) > 100 || $password === '' || strlen($password) < 8 || !in_array($role, ['admin', 'user'], true)) {
        response(['error' => 'Username, password (at least 8 characters) and a valid role are required.'], 400);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)');
        $stmt->execute(['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role]);
        response(['success' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    } catch (PDOException $e) {
        response(['error' => 'Username already exists.'], 409);
    }
}

if (preg_match('#^/api/users/(\d+)$#', $path, $userMatch)) {
    requireAdmin($currentUser);
    $userId = (int) $userMatch[1];
    if ($method === 'PUT' || $method === 'PATCH') {
        $data = readJsonBody();
        $username = trim((string) ($data['username'] ?? ''));
        $role = strtolower(trim((string) ($data['role'] ?? 'user')));
        $password = (string) ($data['password'] ?? '');
        if ($username === '' || strlen($username) > 100 || !in_array($role, ['admin', 'user'], true) || ($password !== '' && strlen($password) < 8)) {
            response(['error' => 'Username, role and an optional password of at least 8 characters are required.'], 400);
        }
        $existing = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
        $existing->execute(['id' => $userId]);
        $existingUser = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$existingUser) {
            response(['error' => 'User not found.'], 404);
        }
        if (isset($currentUser['sub']) && (int) $currentUser['sub'] === $userId && $role !== 'admin') {
            response(['error' => 'You cannot remove administrator access from the account currently in use.'], 409);
        }
        if ($existingUser['role'] === 'admin' && $role !== 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            response(['error' => 'At least one administrator must remain.'], 409);
        }
        try {
            if ($password !== '') {
                $stmt = $pdo->prepare('UPDATE users SET username = :username, password_hash = :password_hash, role = :role WHERE id = :id');
                $stmt->execute(['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'id' => $userId]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET username = :username, role = :role WHERE id = :id');
                $stmt->execute(['username' => $username, 'role' => $role, 'id' => $userId]);
            }
            response(['success' => true]);
        } catch (PDOException $e) {
            response(['error' => 'Username already exists.'], 409);
        }
    }
    if ($method === 'DELETE') {
        if (isset($currentUser['sub']) && (int) $currentUser['sub'] === $userId) {
            response(['error' => 'You cannot delete the account currently in use.'], 409);
        }
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $role = $stmt->fetchColumn();
        if ($role === false) {
            response(['error' => 'User not found.'], 404);
        }
        if ($role === 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            response(['error' => 'At least one administrator must remain.'], 409);
        }
        $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $delete->execute(['id' => $userId]);
        response(['success' => true]);
    }
}

if ($method === 'POST' && $path === '/api/delete') {
    requireAdmin($currentUser);
    $data = readJsonBody();
    $category = trim((string) ($data['category'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));
    $language = normalizeLanguage((string) ($data['language'] ?? ''));
    if ($category === '' || $message === '') {
        response(['error' => 'category and message are required.'], 400);
    }
    $stmt = $pdo->prepare('SELECT id FROM source_messages WHERE category = :category AND message = :message');
    $stmt->execute(['category' => $category, 'message' => $message]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        if ($language !== '') {
            $delete = $pdo->prepare('DELETE FROM messages WHERE id = :id AND language = :language');
            $delete->execute(['id' => $id, 'language' => $language]);
            $check = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE id = :id');
            $check->execute(['id' => $id]);
            if ((int) $check->fetchColumn() === 0) {
                $deleteSource = $pdo->prepare('DELETE FROM source_messages WHERE id = :id');
                $deleteSource->execute(['id' => $id]);
            }
        } else {
            $delete = $pdo->prepare('DELETE FROM source_messages WHERE id = :id');
            $delete->execute(['id' => $id]);
        }
    }
    response(['success' => true]);
}

response(['error' => 'Not Found'], 404);
