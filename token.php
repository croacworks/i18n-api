<?php

/**
 * Token Management Utility for i18n-api
 */

$envFile = __DIR__ . '/.env';

function getToken() {
    global $envFile;
    if (!file_exists($envFile)) return null;
    $content = file_get_contents($envFile);
    if (preg_match('/I18N_API_TOKEN=(.*)/', $content, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function setToken($newToken) {
    global $envFile;
    $content = file_exists($envFile) ? file_get_contents($envFile) : "";
    if (preg_match('/I18N_API_TOKEN=.*/', $content)) {
        $content = preg_replace('/I18N_API_TOKEN=.*/', "I18N_API_TOKEN=$newToken", $content);
    } else {
        $content .= "\nI18N_API_TOKEN=$newToken\n";
    }
    file_put_contents($envFile, trim($content) . "\n");
}

$action = $argv[1] ?? '--show';

if ($action === '--generate' || $action === '-g') {
    $newToken = bin2hex(random_bytes(16));
    setToken($newToken);
    echo "✅ New token generated and saved to .env\n";
    echo "Token: $newToken\n";
} elseif ($action === '--show' || $action === '-s') {
    $token = getToken();
    if ($token) {
        echo "🔑 Current Token: $token\n";
    } else {
        echo "❌ No token found in .env\n";
    }
} else {
    echo "Usage:\n";
    echo "  php token.php --show      (-s)  Show current token\n";
    echo "  php token.php --generate  (-g)  Generate and save new token\n";
}
