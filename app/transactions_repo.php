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

function repo_categories_has_parent_id(PDO $db): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->query("SHOW COLUMNS FROM categories LIKE 'parent_id'");
        $cached = (bool)$stmt->fetch();
    } catch (PDOException $e) {
        $cached = false;
    }
    return $cached;
}

function repo_list_categories(PDO $db): array {
    if (repo_categories_has_parent_id($db)) {
        $stmt = $db->query("SELECT id, name, parent_id FROM categories ORDER BY name ASC");
    } else {
        $stmt = $db->query("SELECT id, name FROM categories ORDER BY name ASC");
    }
    $rows = $stmt->fetchAll();
    $byId = [];
    $children = [];
    foreach ($rows as $row) {
        if (!array_key_exists('parent_id', $row)) {
            $row['parent_id'] = null;
        }
        $byId[(int)$row['id']] = $row;
        if ($row['parent_id'] !== null) {
            $children[(int)$row['parent_id']][] = (int)$row['id'];
        }
    }
    foreach ($rows as &$row) {
        $label = $row['name'];
        $parentId = $row['parent_id'];
        if ($parentId !== null && isset($byId[(int)$parentId])) {
            $label = $byId[(int)$parentId]['name'] . ' → ' . $label;
        }
        $row['label'] = $label;
    }
    unset($row);

    $parents = array_values(array_filter($rows, static fn(array $row): bool => $row['parent_id'] === null));
    usort($parents, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    $ordered = [];
    $added = [];
    foreach ($parents as $parent) {
        $parentId = (int)$parent['id'];
        $ordered[] = $parent;
        $added[$parentId] = true;
        if (isset($children[$parentId])) {
            $childRows = array_map(
                static fn(int $childId): array => $byId[$childId],
                $children[$parentId]
            );
            usort($childRows, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            foreach ($childRows as $child) {
                $ordered[] = $child;
                $added[(int)$child['id']] = true;
            }
        }
    }
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (!isset($added[$id])) {
            $ordered[] = $row;
        }
    }
    return $ordered;
}

function repo_list_assignable_categories(PDO $db): array {
    $rows = repo_list_categories($db);
    $hasChildrenMap = [];
    foreach ($rows as $row) {
        if ($row['parent_id'] !== null) {
            $hasChildrenMap[(int)$row['parent_id']] = true;
        }
    }
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => empty($hasChildrenMap[(int)$row['id']])
    ));
}

function repo_find_category_id(PDO $db, string $name, ?int $parentId): ?int {
    $name = trim($name);
    if ($name === '') return null;
    if (!repo_categories_has_parent_id($db)) {
        $stmt = $db->prepare("SELECT id FROM categories WHERE name = :n LIMIT 1");
        $stmt->execute([':n' => $name]);
    } elseif ($parentId === null) {
        $stmt = $db->prepare("SELECT id FROM categories WHERE name = :n AND parent_id IS NULL LIMIT 1");
        $stmt->execute([':n' => $name]);
    } else {
        $stmt = $db->prepare("SELECT id FROM categories WHERE name = :n AND parent_id = :pid LIMIT 1");
        $stmt->execute([':n' => $name, ':pid' => $parentId]);
    }
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

function repo_create_category(PDO $db, string $name, ?int $parentId): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $parentId = ($parentId !== null && $parentId > 0) ? $parentId : null;
    // Insert ignore via try/catch for unique constraint
    try {
        if (repo_categories_has_parent_id($db)) {
            $stmt = $db->prepare("INSERT INTO categories(name, parent_id) VALUES(:n, :pid)");
            $stmt->execute([':n' => $name, ':pid' => $parentId]);
        } else {
            $stmt = $db->prepare("INSERT INTO categories(name) VALUES(:n)");
            $stmt->execute([':n' => $name]);
        }
        return (int)$db->lastInsertId();
    } catch (PDOException $e) {
        $existingId = repo_find_category_id($db, $name, $parentId);
        return $existingId ?: null;
    }
}

function repo_bulk_create_categories(PDO $db, array $names): array {
    $createdIds = [];
    $skipped = 0;
    if ($names === []) return ['created_ids' => $createdIds, 'skipped' => $skipped];

    $db->beginTransaction();
    try {
        foreach ($names as $name) {
            if (!is_array($name)) {
                $skipped++;
                continue;
            }
            $cleanName = trim((string)($name['name'] ?? ''));
            if ($cleanName === '') {
                $skipped++;
                continue;
            }
            $parentId = $name['parent_id'] ?? null;
            $parentId = $parentId !== null ? (int)$parentId : null;
            if (!repo_categories_has_parent_id($db)) {
                $parentId = null;
            }
            $id = repo_create_category($db, $cleanName, $parentId);
            if ($id) {
                $createdIds[] = $id;
            } else {
                $skipped++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return ['created_ids' => $createdIds, 'skipped' => $skipped];
}

function repo_update_category(PDO $db, int $categoryId, string $name, ?int $parentId): void {
    $name = trim($name);
    if ($categoryId <= 0) {
        throw new RuntimeException('Invalid category.');
    }
    if ($name === '') {
        throw new RuntimeException('Category name cannot be empty.');
    }
    $parentId = ($parentId !== null && $parentId > 0) ? $parentId : null;
    $hasParentId = repo_categories_has_parent_id($db);
    if ($hasParentId && $parentId === $categoryId) {
        throw new RuntimeException('Category cannot be its own parent.');
    }

    try {
        if ($hasParentId) {
            $stmt = $db->prepare("UPDATE categories SET name = :name, parent_id = :pid WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':pid' => $parentId,
                ':id' => $categoryId,
            ]);
        } else {
            $stmt = $db->prepare("UPDATE categories SET name = :name WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':id' => $categoryId,
            ]);
        }
    } catch (PDOException $e) {
        throw new RuntimeException('Category name already exists.');
    }
}

function repo_delete_category(PDO $db, int $categoryId): void {
    if ($categoryId <= 0) {
        throw new RuntimeException('Invalid category.');
    }
    $hasParentId = repo_categories_has_parent_id($db);
    if ($hasParentId) {
        $stmt = $db->prepare("SELECT 1 FROM categories WHERE parent_id = :id LIMIT 1");
        $stmt->execute([':id' => $categoryId]);
        if ($stmt->fetch()) {
            throw new RuntimeException('Category has child categories.');
        }
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE transactions SET category_id = NULL WHERE category_id = :id");
        $stmt->execute([':id' => $categoryId]);
        $stmt = $db->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->execute([':id' => $categoryId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
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
            COALESCE(c.name, 'Niet ingedeeld') AS category,
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
