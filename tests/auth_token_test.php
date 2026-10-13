<?php

declare(strict_types=1);

$baseUrl = rtrim(getenv('I18N_TEST_BASE_URL') ?: 'http://127.0.0.1', '/');
$env = parse_ini_file(__DIR__ . '/../.env');
$apiToken = is_array($env) ? trim((string) ($env['I18N_API_TOKEN'] ?? '')) : '';
$configuredTtl = trim((string) ((is_array($env) ? ($env['I18N_TOKEN_TTL'] ?? null) : null) ?? getenv('I18N_TOKEN_TTL') ?: ''));
$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
$user = $pdo->query("SELECT id, username, role FROM users ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($apiToken === '' || !$user) {
    throw new RuntimeException('The authentication test requires I18N_API_TOKEN and at least one user.');
}

function signedUserToken(array $user, string $apiToken, int $expiresAt): string
{
    $payload = [
        'sub' => (int) $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'iat' => $expiresAt - 86400,
        'exp' => $expiresAt,
    ];
    $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    return $encoded . '.' . hash_hmac('sha256', $encoded, $apiToken);
}

function authenticationStatus(string $baseUrl, string $token): int
{
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}",
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    file_get_contents($baseUrl . '/api/stats', false, $context);
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            return (int) $matches[1];
        }
    }
    return 0;
}

$legacyExpiredToken = signedUserToken($user, $apiToken, time() - 3600);
$expiredStatus = authenticationStatus($baseUrl, $legacyExpiredToken);
$expectedExpiredStatus = $configuredTtl === '' ? 200 : 401;
if ($expiredStatus !== $expectedExpiredStatus) {
    throw new RuntimeException("An expired token returned HTTP {$expiredStatus}; expected {$expectedExpiredStatus}.");
}

$validToken = signedUserToken($user, $apiToken, time() + 3600);
if (authenticationStatus($baseUrl, $validToken) !== 200) {
    throw new RuntimeException('A correctly signed valid token was unexpectedly rejected.');
}

$tamperedToken = substr($legacyExpiredToken, 0, -1) . (str_ends_with($legacyExpiredToken, '0') ? '1' : '0');
if (authenticationStatus($baseUrl, $tamperedToken) !== 401) {
    throw new RuntimeException('A token with an invalid signature was accepted.');
}

echo "Authentication token tests passed.\n";
