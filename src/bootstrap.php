<?php
declare(strict_types=1);

// Load config
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo 'Missing config/config.php. Copy config/config.sample.php to config/config.php and fill in your database settings.';
    exit;
}
$config = require $configPath;

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Amsterdam');

// Session
$sessionName = $config['app']['session_name'] ?? 'mini_uiltje_sess';
session_name($sessionName);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Basic helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function app_version(): string {
    $versionFile = __DIR__ . '/../VERSION';
    $v = file_exists($versionFile) ? trim((string)file_get_contents($versionFile)) : 'dev';
    $commit = getenv('APP_COMMIT') ?: '';
    if ($commit !== '') {
        return $v . '+' . $commit;
    }
    return $v;
}

// Add version header on every response
header('X-App-Version: ' . app_version());

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

