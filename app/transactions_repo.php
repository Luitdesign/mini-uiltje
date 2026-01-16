<?php
declare(strict_types=1);

function repo_list_months(PDO $db, int $userId): array {
    $sql = "
        SELECT
            YEAR(txn_date) AS y,
            MONTH(txn_date) AS m,
            COUNT(*) AS cnt,
            SUM(amount_signed) AS net,
            SUM(CASE WHEN amount_signed > 0 THEN amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN amount_signed < 0 THEN amount_signed ELSE 0 END)) AS spending
        FROM transactions
        WHERE user_id = :uid
        GROUP BY YEAR(txn_date), MONTH(txn_date)
        ORDER BY y DESC, m DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function repo_get_latest_month(PDO $db, int $userId): ?array {
    $stmt = $db->prepare("SELECT txn_date FROM transactions WHERE user_id = :uid ORDER BY txn_date DESC LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    [$y, $m] = current_year_month_from_txn_date($row['txn_date']);
    return ['y' => $y, 'm' => $m];
}

function repo_list_categories(PDO $db): array {
    $stmt = $db->query("SELECT id, name FROM categories ORDER BY name ASC");
    return $stmt->fetchAll();
}

function repo_create_category(PDO $db, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    // Insert ignore via try/catch for unique constraint
    try {
        $stmt = $db->prepare("INSERT INTO categories(name) VALUES(:n)");
        $stmt->execute([':n' => $name]);
        return (int)$db->lastInsertId();
    } catch (PDOException $e) {
        // If already exists, return existing id
        $stmt = $db->prepare("SELECT id FROM categories WHERE name = :n LIMIT 1");
        $stmt->execute([':n' => $name]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }
}

function repo_bulk_update_categories(PDO $db, array $categories): void {
    if ($categories === []) return;
    $stmt = $db->prepare("UPDATE categories SET name = :name WHERE id = :id");
    $db->beginTransaction();
    try {
        foreach ($categories as $id => $values) {
            $categoryId = (int)$id;
            if ($categoryId <= 0 || !is_array($values)) {
                continue;
            }
            $name = trim((string)($values['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Category name cannot be empty.');
            }
            $stmt->execute([
                ':name' => $name,
                ':id' => $categoryId,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function repo_list_transactions(PDO $db, int $userId, int $year, int $month, string $q = ''): array {
    $params = [':uid' => $userId, ':y' => $year, ':m' => $month];
    $whereQ = '';
    if ($q !== '') {
        $whereQ = " AND (description LIKE :q OR notes LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $sql = "
        SELECT t.*, c.name AS category_name
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :uid
          AND YEAR(t.txn_date) = :y
          AND MONTH(t.txn_date) = :m
          {$whereQ}
        ORDER BY t.txn_date DESC, t.id DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function repo_update_transaction_category(PDO $db, int $userId, int $txnId, ?int $categoryId): void {
    $stmt = $db->prepare("UPDATE transactions SET category_id = :cid WHERE id = :id AND user_id = :uid");
    $stmt->execute([
        ':cid' => $categoryId,
        ':id' => $txnId,
        ':uid' => $userId,
    ]);
}

function repo_month_summary(PDO $db, int $userId, int $year, int $month): array {
    $sql = "
        SELECT
            SUM(CASE WHEN amount_signed > 0 THEN amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN amount_signed < 0 THEN amount_signed ELSE 0 END)) AS spending,
            SUM(amount_signed) AS net
        FROM transactions
        WHERE user_id = :uid
          AND YEAR(txn_date) = :y
          AND MONTH(txn_date) = :m
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId, ':y' => $year, ':m' => $month]);
    $row = $stmt->fetch() ?: [];
    return [
        'income' => (float)($row['income'] ?? 0),
        'spending' => (float)($row['spending'] ?? 0),
        'net' => (float)($row['net'] ?? 0),
    ];
}

function repo_month_breakdown_by_category(PDO $db, int $userId, int $year, int $month): array {
    $sql = "
        SELECT
            COALESCE(c.name, 'Uncategorized') AS category,
            SUM(CASE WHEN t.amount_signed > 0 THEN t.amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN t.amount_signed < 0 THEN t.amount_signed ELSE 0 END)) AS spending,
            SUM(t.amount_signed) AS net
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :uid
          AND YEAR(t.txn_date) = :y
          AND MONTH(t.txn_date) = :m
        GROUP BY category
        ORDER BY spending DESC, income DESC, category ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId, ':y' => $year, ':m' => $month]);
    return $stmt->fetchAll();
}
