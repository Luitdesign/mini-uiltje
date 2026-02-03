<?php
declare(strict_types=1);

function repo_list_months(PDO $db, int $userId): array {
    $sql = "
        SELECT
            YEAR(txn_date) AS y,
            MONTH(txn_date) AS m,
            COUNT(*) AS cnt,
            SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) AS uncategorized,
            SUM(amount_signed) AS net,
            SUM(CASE WHEN amount_signed > 0 THEN amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN amount_signed < 0 THEN amount_signed ELSE 0 END)) AS spending
        FROM transactions
        WHERE user_id = :uid
          AND is_internal_transfer = 0
          AND (savings_id IS NULL OR amount_signed >= 0)
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
    $stmt = $db->query("
        SELECT c.id, c.name, c.color, c.savings_id, s.name AS savings_name
        FROM categories c
        LEFT JOIN savings s ON s.id = c.savings_id
        ORDER BY c.name ASC
    ");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['label'] = $row['name'];
    }
    unset($row);
    return $rows;
}

function repo_list_assignable_categories(PDO $db): array {
    return repo_list_categories($db);
}

function repo_get_category(PDO $db, int $categoryId): ?array {
    if ($categoryId <= 0) {
        return null;
    }
    $stmt = $db->prepare("SELECT id, name, color, savings_id FROM categories WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function repo_get_setting(PDO $db, string $key): ?string {
    $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :key");
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return null;
    }
    $value = (string)$value;
    return $value === '' ? null : $value;
}

function repo_set_setting(PDO $db, string $key, ?string $value): void {
    $value = $value !== null ? trim($value) : null;
    if ($value === null || $value === '') {
        $stmt = $db->prepare("DELETE FROM app_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        return;
    }

    $stmt = $db->prepare(
        "INSERT INTO app_settings(setting_key, setting_value)
         VALUES(:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function repo_find_category_id(PDO $db, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $stmt = $db->prepare("SELECT id FROM categories WHERE name = :n LIMIT 1");
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

function repo_create_category(PDO $db, string $name, ?string $color, ?int $savingsId = null): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $color = normalize_hex_color($color);
    $savingsId = $savingsId !== null && $savingsId > 0 ? $savingsId : null;
    // Insert ignore via try/catch for unique constraint
    try {
        $stmt = $db->prepare("INSERT INTO categories(name, color, savings_id) VALUES(:n, :color, :savings_id)");
        $stmt->execute([':n' => $name, ':color' => $color, ':savings_id' => $savingsId]);
        return (int)$db->lastInsertId();
    } catch (PDOException $e) {
        $existingId = repo_find_category_id($db, $name);
        return $existingId ?: null;
    }
}

function repo_bulk_create_categories(PDO $db, array $names): array {
    $createdIds = [];
    $skipped = 0;
    if ($names === []) return ['created_ids' => $createdIds, 'skipped' => $skipped];

    $db->beginTransaction();
    try {
        foreach ($names as $entry) {
            $cleanName = is_array($entry)
                ? trim((string)($entry['name'] ?? ''))
                : trim((string)$entry);
            if ($cleanName === '') {
                $skipped++;
                continue;
            }
            $color = is_array($entry) ? ($entry['color'] ?? null) : null;
            $id = repo_create_category($db, $cleanName, $color);
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

function repo_update_category(PDO $db, int $categoryId, string $name, ?string $color, ?int $savingsId = null): void {
    $name = trim($name);
    if ($categoryId <= 0) {
        throw new RuntimeException('Invalid category.');
    }
    if ($name === '') {
        throw new RuntimeException('Category name cannot be empty.');
    }
    $color = normalize_hex_color($color);
    $savingsId = $savingsId !== null && $savingsId > 0 ? $savingsId : null;

    try {
        $stmt = $db->prepare("UPDATE categories SET name = :name, color = :color, savings_id = :savings_id WHERE id = :id");
        $stmt->execute([
            ':name' => $name,
            ':color' => $color,
            ':savings_id' => $savingsId,
            ':id' => $categoryId,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('Category name already exists.');
    }
}

function repo_delete_category(PDO $db, int $categoryId): void {
    if ($categoryId <= 0) {
        throw new RuntimeException('Invalid category.');
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

function repo_list_transactions(
    PDO $db,
    int $userId,
    int $year,
    int $month,
    string $q = '',
    string $categoryFilter = '',
    string $autoCategoryFilter = '',
    bool $showInternalTransfers = false,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $params = [':uid' => $userId];
    $whereQ = '';
    if ($q !== '') {
        $whereQ = " AND (description LIKE :q OR notes LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $whereCategory = '';
    if ($categoryFilter !== '') {
        if ($categoryFilter === '0') {
            $whereCategory = " AND t.category_id IS NULL";
        } else {
            $whereCategory = " AND t.category_id = :category_id";
            $params[':category_id'] = (int)$categoryFilter;
        }
    }
    $whereAutoCategory = '';
    if ($autoCategoryFilter !== '') {
        if ($autoCategoryFilter === '0') {
            $whereAutoCategory = " AND t.category_auto_id IS NULL";
        } else {
            $whereAutoCategory = " AND t.category_auto_id = :auto_category_id";
            $params[':auto_category_id'] = (int)$autoCategoryFilter;
        }
    }
    $whereInternalTransfer = $showInternalTransfers ? '' : ' AND t.is_internal_transfer = 0';
    $whereDate = '';
    if ($startDate !== null) {
        $whereDate .= ' AND t.txn_date >= :start_date';
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $whereDate .= ' AND t.txn_date <= :end_date';
        $params[':end_date'] = $endDate;
    }
    if ($startDate === null && $endDate === null && $year > 0) {
        $whereDate .= ' AND YEAR(t.txn_date) = :y';
        $params[':y'] = $year;
        if ($month > 0) {
            $whereDate .= ' AND MONTH(t.txn_date) = :m';
            $params[':m'] = $month;
        }
    }

    $sql = "
        SELECT t.*, c.name AS category_name, c.color AS category_color, c.savings_id AS category_savings_id,
               ac.name AS auto_category_name, ac.color AS auto_category_color,
               r.name AS auto_rule_name,
               t.savings_id AS savings_paid_id,
               s.name AS savings_paid_name
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        LEFT JOIN categories ac ON ac.id = t.category_auto_id
        LEFT JOIN rules r ON r.id = t.rule_auto_id AND r.user_id = t.user_id
        LEFT JOIN savings s ON s.id = t.savings_id
        WHERE t.user_id = :uid
          {$whereDate}
          {$whereQ}
          {$whereCategory}
          {$whereAutoCategory}
          {$whereInternalTransfer}
        ORDER BY t.txn_date DESC, t.id DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function repo_list_transactions_for_month(PDO $db, int $userId, int $year, int $month): array {
    $stmt = $db->prepare(
        'SELECT *
         FROM transactions
         WHERE user_id = :uid
           AND YEAR(txn_date) = :y
           AND MONTH(txn_date) = :m
         ORDER BY txn_date DESC, id DESC'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':y' => $year,
        ':m' => $month,
    ]);
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

function repo_update_transaction_friendly_name(PDO $db, int $userId, int $txnId, ?string $friendlyName): void {
    $friendlyName = $friendlyName !== null ? trim($friendlyName) : null;
    if ($friendlyName === '') {
        $friendlyName = null;
    }
    $stmt = $db->prepare(
        "UPDATE transactions
         SET friendly_name = :friendly_name
         WHERE id = :id AND user_id = :uid"
    );
    $stmt->execute([
        ':friendly_name' => $friendlyName,
        ':id' => $txnId,
        ':uid' => $userId,
    ]);
}

function repo_reapply_auto_categories(PDO $db, int $userId, int $year, int $month): int {
    $stmtRules = $db->prepare(
        'SELECT *
         FROM rules
         WHERE user_id = :uid AND active = 1
         ORDER BY priority ASC, id ASC'
    );
    $stmtRules->execute([':uid' => $userId]);
    $rules = $stmtRules->fetchAll();

    $stmtTxns = $db->prepare(
        'SELECT id, description, notes, amount_signed, counter_iban, account_iban, category_id, category_auto_id, is_internal_transfer
         FROM transactions
         WHERE user_id = :uid
           AND YEAR(txn_date) = :y
           AND MONTH(txn_date) = :m'
    );
    $stmtTxns->execute([':uid' => $userId, ':y' => $year, ':m' => $month]);
    $transactions = $stmtTxns->fetchAll();

    if ($transactions === []) {
        return 0;
    }

    $stmtUpdate = $db->prepare(
        'UPDATE transactions
         SET category_auto_id = :auto_id,
             rule_auto_id = :rule_id,
             auto_reason = :auto_reason,
             category_id = :category_id
         WHERE id = :id AND user_id = :uid'
    );

    $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($transactions as $txn) {
            if (!empty($txn['is_internal_transfer'])) {
                continue;
            }
            $auto = ing_apply_rules($txn, $rules);
            $newAutoId = $auto['category_auto_id'];
            $currentCategoryId = $txn['category_id'] !== null ? (int)$txn['category_id'] : null;
            $currentAutoId = $txn['category_auto_id'] !== null ? (int)$txn['category_auto_id'] : null;
            $shouldUpdateCategory = $currentCategoryId === null || $currentCategoryId === $currentAutoId;
            $categoryId = $shouldUpdateCategory ? $newAutoId : $currentCategoryId;

            $stmtUpdate->execute([
                ':auto_id' => $newAutoId,
                ':rule_id' => $auto['rule_auto_id'],
                ':auto_reason' => $auto['auto_reason'],
                ':category_id' => $categoryId,
                ':id' => (int)$txn['id'],
                ':uid' => $userId,
            ]);
            $updated++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $updated;
}

function repo_period_summary(
    PDO $db,
    int $userId,
    int $year,
    int $month,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $params = [':uid' => $userId];
    $whereDate = '';
    if ($startDate !== null) {
        $whereDate .= ' AND txn_date >= :start_date';
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $whereDate .= ' AND txn_date <= :end_date';
        $params[':end_date'] = $endDate;
    }
    if ($startDate === null && $endDate === null && $year > 0) {
        $whereDate .= ' AND YEAR(txn_date) = :y';
        $params[':y'] = $year;
        if ($month > 0) {
            $whereDate .= ' AND MONTH(txn_date) = :m';
            $params[':m'] = $month;
        }
    }

    $sql = "
        SELECT
            SUM(CASE WHEN amount_signed > 0 THEN amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN amount_signed < 0 THEN amount_signed ELSE 0 END)) AS spending,
            SUM(amount_signed) AS net
        FROM transactions
        WHERE user_id = :uid
          {$whereDate}
          AND is_internal_transfer = 0
          AND (savings_id IS NULL OR amount_signed >= 0)
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
        'income' => (float)($row['income'] ?? 0),
        'spending' => (float)($row['spending'] ?? 0),
        'net' => (float)($row['net'] ?? 0),
    ];
}

function repo_period_breakdown_by_category(
    PDO $db,
    int $userId,
    int $year,
    int $month,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $params = [':uid' => $userId];
    $whereDate = '';
    if ($startDate !== null) {
        $whereDate .= ' AND t.txn_date >= :start_date';
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $whereDate .= ' AND t.txn_date <= :end_date';
        $params[':end_date'] = $endDate;
    }
    if ($startDate === null && $endDate === null && $year > 0) {
        $whereDate .= ' AND YEAR(t.txn_date) = :y';
        $params[':y'] = $year;
        if ($month > 0) {
            $whereDate .= ' AND MONTH(t.txn_date) = :m';
            $params[':m'] = $month;
        }
    }

    $sql = "
        SELECT
            COALESCE(c.name, 'Niet ingedeeld') AS category,
            SUM(CASE WHEN t.amount_signed > 0 THEN t.amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN t.amount_signed < 0 THEN t.amount_signed ELSE 0 END)) AS spending,
            SUM(t.amount_signed) AS net
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :uid
          {$whereDate}
          AND t.is_internal_transfer = 0
          AND (t.savings_id IS NULL OR t.amount_signed >= 0)
        GROUP BY category
        ORDER BY spending DESC, income DESC, category ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function repo_period_paid_from_savings_total(
    PDO $db,
    int $userId,
    int $year,
    int $month,
    ?string $startDate = null,
    ?string $endDate = null
): float {
    $params = [':uid' => $userId];
    $whereDate = '';
    if ($startDate !== null) {
        $whereDate .= ' AND t.txn_date >= :start_date';
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $whereDate .= ' AND t.txn_date <= :end_date';
        $params[':end_date'] = $endDate;
    }
    if ($startDate === null && $endDate === null && $year > 0) {
        $whereDate .= ' AND YEAR(t.txn_date) = :y';
        $params[':y'] = $year;
        if ($month > 0) {
            $whereDate .= ' AND MONTH(t.txn_date) = :m';
            $params[':m'] = $month;
        }
    }

    $sql = "
        SELECT ABS(SUM(t.amount_signed)) AS total
        FROM transactions t
        WHERE t.user_id = :uid
          {$whereDate}
          AND t.amount_signed < 0
          AND t.savings_id IS NOT NULL
          AND t.is_topup = 0
          AND t.is_internal_transfer = 0
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value !== false ? (float)$value : 0.0;
}

function repo_period_paid_from_savings_breakdown(
    PDO $db,
    int $userId,
    int $year,
    int $month,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $params = [':uid' => $userId];
    $whereDate = '';
    if ($startDate !== null) {
        $whereDate .= ' AND t.txn_date >= :start_date';
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $whereDate .= ' AND t.txn_date <= :end_date';
        $params[':end_date'] = $endDate;
    }
    if ($startDate === null && $endDate === null && $year > 0) {
        $whereDate .= ' AND YEAR(t.txn_date) = :y';
        $params[':y'] = $year;
        if ($month > 0) {
            $whereDate .= ' AND MONTH(t.txn_date) = :m';
            $params[':m'] = $month;
        }
    }

    $sql = "
        SELECT
            COALESCE(c.name, 'Niet ingedeeld') AS category,
            ABS(SUM(t.amount_signed)) AS spending
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :uid
          {$whereDate}
          AND t.amount_signed < 0
          AND t.savings_id IS NOT NULL
          AND t.is_topup = 0
          AND t.is_internal_transfer = 0
        GROUP BY category
        ORDER BY spending DESC, category ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function repo_period_paid_from_savings_transactions(
    PDO $db,
    int $userId,
    int $year,
    int $month,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $params = [':uid' => $userId];
    $whereDate = '';
    if ($startDate !== null) {
        $whereDate .= ' AND t.txn_date >= :start_date';
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== null) {
        $whereDate .= ' AND t.txn_date <= :end_date';
        $params[':end_date'] = $endDate;
    }
    if ($startDate === null && $endDate === null && $year > 0) {
        $whereDate .= ' AND YEAR(t.txn_date) = :y';
        $params[':y'] = $year;
        if ($month > 0) {
            $whereDate .= ' AND MONTH(t.txn_date) = :m';
            $params[':m'] = $month;
        }
    }

    $sql = "
        SELECT
            t.id,
            t.txn_date,
            t.description,
            t.amount_signed,
            COALESCE(c.name, 'Niet ingedeeld') AS category_name,
            s.name AS savings_name
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        LEFT JOIN savings s ON s.id = t.savings_id
        WHERE t.user_id = :uid
          {$whereDate}
          AND t.amount_signed < 0
          AND t.savings_id IS NOT NULL
          AND t.is_topup = 0
          AND t.is_internal_transfer = 0
        ORDER BY t.txn_date DESC, t.id DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
