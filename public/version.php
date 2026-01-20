<?php
require_once __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$commit = getenv('APP_COMMIT') ?: null;
$builtAt = getenv('APP_BUILT_AT') ?: null;

echo json_encode([
    'version' => app_version(),
    'commit' => $commit,
    'built_at' => $builtAt,
]);
