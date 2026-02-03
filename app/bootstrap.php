<?php
declare(strict_types=1);

// Bootstrap for all pages.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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
require_once __DIR__ . '/rules_repo.php';
require_once __DIR__ . '/savings_repo.php';
require_once __DIR__ . '/csv_ing_import.php';

header('X-App-Version: ' . app_version());

$db = db_connect($config['db']);
