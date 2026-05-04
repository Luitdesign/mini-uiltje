<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$q = (string)($_GET['q'] ?? '');
$userId = current_data_user_id($db);
$results = repo_search_tags($db, $userId, $q, 10);

echo json_encode(array_map(static fn(array $row): array => [
    'id' => (int)($row['id'] ?? 0),
    'name' => (string)($row['name'] ?? ''),
], $results), JSON_UNESCAPED_UNICODE);
