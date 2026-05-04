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

function db_table_exists(PDO $db, string $name): bool {
    $stmt = $db->prepare('SHOW TABLES LIKE :t');
    $stmt->execute([':t' => $name]);
    return (bool)$stmt->fetch();
}

function db_column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return ((int)$stmt->fetchColumn() > 0);
}

function db_index_exists(PDO $db, string $table, string $index): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
    );
    $stmt->execute([':table' => $table, ':index' => $index]);
    return ((int)$stmt->fetchColumn() > 0);
}

function db_constraint_exists(PDO $db, string $table, string $constraint): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = :table AND constraint_name = :constraint'
    );
    $stmt->execute([':table' => $table, ':constraint' => $constraint]);
    return ((int)$stmt->fetchColumn() > 0);
}

function db_ensure_runtime_extensions(PDO $db): void {
    if (db_table_exists($db, 'categories')) {
        if (!db_column_exists($db, 'categories', 'explainer')) {
            $db->exec('ALTER TABLE categories ADD COLUMN explainer VARCHAR(255) NULL AFTER name');
        }
        if (!db_column_exists($db, 'categories', 'parent_id')) {
            $db->exec('ALTER TABLE categories ADD COLUMN parent_id INT UNSIGNED NULL AFTER color');
        }
        if (!db_column_exists($db, 'categories', 'is_parent')) {
            $db->exec('ALTER TABLE categories ADD COLUMN is_parent TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_id');
        }
        if (!db_index_exists($db, 'categories', 'idx_categories_parent_id')) {
            $db->exec('ALTER TABLE categories ADD KEY idx_categories_parent_id (parent_id)');
        }
        if (!db_constraint_exists($db, 'categories', 'fk_categories_parent')) {
            $db->exec('ALTER TABLE categories ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL');
        }
    }

    if (db_table_exists($db, 'transactions')) {
        if (!db_column_exists($db, 'transactions', 'category_auto_id')) {
            $db->exec('ALTER TABLE transactions ADD COLUMN category_auto_id INT UNSIGNED NULL AFTER category_id');
        }
        if (!db_column_exists($db, 'transactions', 'approved')) {
            $db->exec('ALTER TABLE transactions ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 0 AFTER is_topup');
        }
    }
}
