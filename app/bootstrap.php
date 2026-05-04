<?php
declare(strict_types=1);

// Bootstrap for all pages.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");

$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo 'Missing config/config.php. Copy config/config.sample.php to config/config.php and fill in DB credentials.';
    exit;
}

$config = require $configPath;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/transactions_repo.php';
require_once __DIR__ . '/tagging.php';
require_once __DIR__ . '/rules_repo.php';
require_once __DIR__ . '/savings_repo.php';
require_once __DIR__ . '/csv_ing_import.php';
require_once __DIR__ . '/users_repo.php';
require_once __DIR__ . '/sync_repo.php';

$db = db_connect($config['db']);
db_ensure_runtime_extensions($db);
