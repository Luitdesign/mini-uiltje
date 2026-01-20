<?php
require_once __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$checkDb = isset($_GET['db']) && (string)$_GET['db'] === '1';
if ($checkDb) {
    try {
        $db->query('SELECT 1')->fetch();
        echo json_encode(['status' => 'ok', 'db' => 'ok']);
        exit;
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode(['status' => 'degraded', 'db' => 'fail']);
        exit;
    }
}

echo json_encode(['status' => 'ok']);
