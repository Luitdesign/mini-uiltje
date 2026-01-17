<?php
declare(strict_types=1);

function db_connect(array $dbConfig): PDO {
    $host = $dbConfig['host'] ?? 'localhost';
    $name = $dbConfig['name'] ?? '';
    $user = $dbConfig['user'] ?? '';
    $pass = $dbConfig['pass'] ?? '';
    $charset = $dbConfig['charset'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Database connection failed: ' . h($e->getMessage());
        exit;
    }

    return $pdo;
}
