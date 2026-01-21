<?php
declare(strict_types=1);

function repo_list_months(PDO $db, int $userId): array {
    $sql = "
        SELECT
            YEAR(txn_date) AS y,
            MONTH(txn_date) AS m,
            COUNT(*) AS cnt,
            SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) AS uncategorized,
            SUM(CASE WHEN flow_type IN ('income','expense') THEN amount_signed ELSE 0 END) AS net,
            SUM(CASE WHEN flow_type = 'income' THEN amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN flow_type = 'expense' THEN amount_signed ELSE 0 END)) AS spending
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

function repo_transfer_category_name(): string {
    return 'Transfer (eigen rekeningen)';
}

function repo_ensure_transfer_category(PDO $db): int {
    $name = repo_transfer_category_name();
    $existingId = repo_find_category_id($db, $name);
    if ($existingId) {
        return $existingId;
    }
    $createdId = repo_create_category($db, $name, null);
    return $createdId ?: 0;
}

function repo_ensure_transfer_rules(PDO $db, int $userId, int $transferCategoryId): void {
    if ($userId <= 0 || $transferCategoryId <= 0) {
        return;
    }

    $rulesToEnsure = [
        [
            'name' => 'Transfer: Naar Oranje spaarrekening',
            'from_text' => 'Naar Oranje spaarrekening',
        ],
        [
            'name' => 'Transfer: Van Oranje spaarrekening',
            'from_text' => 'Van Oranje spaarrekening',
        ],
    ];

    $stmtFind = $db->prepare('SELECT id FROM rules WHERE user_id = :uid AND name = :name LIMIT 1');

    foreach ($rulesToEnsure as $rule) {
        $stmtFind->execute([
            ':uid' => $userId,
            ':name' => $rule['name'],
        ]);
        $exists = $stmtFind->fetchColumn();
        if ($exists) {
            continue;
        }

        repo_create_rule($db, $userId, [
            'active' => 1,
            'priority' => 0,
            'name' => $rule['name'],
            'from_text' => $rule['from_text'],
            'from_text_match' => 'contains',
            'from_iban' => null,
            'mededelingen_text' => null,
            'mededelingen_match' => null,
            'rekening_equals' => null,
            'amount_min' => null,
            'amount_max' => null,
            'target_category_id' => $transferCategoryId,
        ]);
    }
}

function repo_ensure_transfer_setup(PDO $db, int $userId): int {
    $transferCategoryId = repo_ensure_transfer_category($db);
    repo_ensure_transfer_rules($db, $userId, $transferCategoryId);
    return $transferCategoryId;
}

function repo_compute_flow_type(float $amountSigned, ?int $categoryId, int $transferCategoryId): string {
    if ($categoryId !== null && $transferCategoryId > 0 && $categoryId === $transferCategoryId) {
        return 'transfer';
    }
    return $amountSigned > 0 ? 'income' : 'expense';
}

function repo_list_categories(PDO $db): array {
    repo_ensure_transfer_category($db);
    $stmt = $db->query("SELECT id, name, color FROM categories ORDER BY name ASC");
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
    $stmt = $db->prepare("SELECT id, name, color FROM categories WHERE id = :id LIMIT 1");
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

function repo_create_category(PDO $db, string $name, ?string $color): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $color = normalize_hex_color($color);
    // Insert ignore via try/catch for unique constraint
    try {
        $stmt = $db->prepare("INSERT INTO categories(name, color) VALUES(:n, :color)");
        $stmt->execute([':n' => $name, ':color' => $color]);
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

function repo_update_category(PDO $db, int $categoryId, string $name, ?string $color): void {
    $name = trim($name);
    if ($categoryId <= 0) {
        throw new RuntimeException('Invalid category.');
    }
    if ($name === '') {
        throw new RuntimeException('Category name cannot be empty.');
    }
    $color = normalize_hex_color($color);

    try {
        $stmt = $db->prepare("UPDATE categories SET name = :name, color = :color WHERE id = :id");
        $stmt->execute([
            ':name' => $name,
            ':color' => $color,
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
    string $autoCategoryFilter = ''
): array {
    $params = [':uid' => $userId, ':y' => $year, ':m' => $month];
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

    $sql = "
        SELECT t.*, c.name AS category_name, c.color AS category_color,
               ac.name AS auto_category_name, ac.color AS auto_category_color
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        LEFT JOIN categories ac ON ac.id = t.category_auto_id
        WHERE t.user_id = :uid
          AND YEAR(t.txn_date) = :y
          AND MONTH(t.txn_date) = :m
          {$whereQ}
          {$whereCategory}
          {$whereAutoCategory}
        ORDER BY t.txn_date DESC, t.id DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function repo_update_transaction_category(PDO $db, int $userId, int $txnId, ?int $categoryId): void {
    $transferCategoryId = repo_ensure_transfer_category($db);

    $stmtTxn = $db->prepare("SELECT amount_signed, category_auto_id FROM transactions WHERE id = :id AND user_id = :uid");
    $stmtTxn->execute([
        ':id' => $txnId,
        ':uid' => $userId,
    ]);
    $txnRow = $stmtTxn->fetch(PDO::FETCH_ASSOC) ?: [];
    $amountSigned = (float)($txnRow['amount_signed'] ?? 0);
    $autoCategoryId = $txnRow['category_auto_id'] !== null ? (int)$txnRow['category_auto_id'] : null;
    $flowType = repo_compute_flow_type($amountSigned, $categoryId ?? $autoCategoryId, $transferCategoryId);

    $stmt = $db->prepare("UPDATE transactions SET category_id = :cid, flow_type = :flow_type WHERE id = :id AND user_id = :uid");
    $stmt->execute([
        ':cid' => $categoryId,
        ':flow_type' => $flowType,
        ':id' => $txnId,
        ':uid' => $userId,
    ]);
}

function repo_update_transaction_friendly_name(PDO $db, int $userId, int $txnId, ?string $friendlyName): void {
    if ($txnId <= 0) {
        return;
    }

    $friendlyName = $friendlyName !== null ? trim($friendlyName) : null;
    if ($friendlyName === '') {
        $friendlyName = null;
    }
    if ($friendlyName !== null) {
        $friendlyName = mb_substr($friendlyName, 0, 255);
    }

    $stmt = $db->prepare("UPDATE transactions SET friendly_name = :name WHERE id = :id AND user_id = :uid");
    $stmt->execute([
        ':name' => $friendlyName,
        ':id' => $txnId,
        ':uid' => $userId,
    ]);
}

function repo_apply_rules_to_import_batch(PDO $db, int $userId, int $importBatchId): int {
    $transferCategoryId = repo_ensure_transfer_setup($db, $userId);

    $stmtRules = $db->prepare(
        'SELECT *
         FROM rules
         WHERE user_id = :uid AND active = 1
         ORDER BY priority ASC, id ASC'
    );
    $stmtRules->execute([':uid' => $userId]);
    $rules = $stmtRules->fetchAll();

    $stmtTxns = $db->prepare(
        'SELECT id, description, notes, amount_signed, counter_iban, account_iban, category_id, category_auto_id
         FROM transactions
         WHERE user_id = :uid
           AND import_batch_id = :bid'
    );
    $stmtTxns->execute([':uid' => $userId, ':bid' => $importBatchId]);
    $transactions = $stmtTxns->fetchAll();

    if ($transactions === []) {
        return 0;
    }

    $stmtUpdate = $db->prepare(
        'UPDATE transactions
         SET category_auto_id = :auto_id,
             rule_auto_id = :rule_id,
             auto_reason = :auto_reason,
             category_id = :category_id,
             flow_type = :flow_type
         WHERE id = :id AND user_id = :uid'
    );

    $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($transactions as $txn) {
            $auto = ing_apply_rules($txn, $rules);
            $newAutoId = $auto['category_auto_id'];
            $currentCategoryId = $txn['category_id'] !== null ? (int)$txn['category_id'] : null;
            $currentAutoId = $txn['category_auto_id'] !== null ? (int)$txn['category_auto_id'] : null;
            $shouldUpdateCategory = $currentCategoryId === null || $currentCategoryId === $currentAutoId;
            $categoryId = $shouldUpdateCategory ? $newAutoId : $currentCategoryId;
            $flowType = repo_compute_flow_type(
                (float)$txn['amount_signed'],
                $categoryId ?? $newAutoId,
                $transferCategoryId
            );

            $stmtUpdate->execute([
                ':auto_id' => $newAutoId,
                ':rule_id' => $auto['rule_auto_id'],
                ':auto_reason' => $auto['auto_reason'],
                ':category_id' => $categoryId,
                ':flow_type' => $flowType,
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

function repo_reapply_auto_categories(PDO $db, int $userId, int $year, int $month): int {
    $transferCategoryId = repo_ensure_transfer_setup($db, $userId);

    $stmtRules = $db->prepare(
        'SELECT *
         FROM rules
         WHERE user_id = :uid AND active = 1
         ORDER BY priority ASC, id ASC'
    );
    $stmtRules->execute([':uid' => $userId]);
    $rules = $stmtRules->fetchAll();

    $stmtTxns = $db->prepare(
        'SELECT id, description, notes, amount_signed, counter_iban, account_iban, category_id, category_auto_id
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
             category_id = :category_id,
             flow_type = :flow_type
         WHERE id = :id AND user_id = :uid'
    );

    $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($transactions as $txn) {
            $auto = ing_apply_rules($txn, $rules);
            $newAutoId = $auto['category_auto_id'];
            $currentCategoryId = $txn['category_id'] !== null ? (int)$txn['category_id'] : null;
            $currentAutoId = $txn['category_auto_id'] !== null ? (int)$txn['category_auto_id'] : null;
            $shouldUpdateCategory = $currentCategoryId === null || $currentCategoryId === $currentAutoId;
            $categoryId = $shouldUpdateCategory ? $newAutoId : $currentCategoryId;
            $flowType = repo_compute_flow_type(
                (float)$txn['amount_signed'],
                $categoryId ?? $newAutoId,
                $transferCategoryId
            );

            $stmtUpdate->execute([
                ':auto_id' => $newAutoId,
                ':rule_id' => $auto['rule_auto_id'],
                ':auto_reason' => $auto['auto_reason'],
                ':category_id' => $categoryId,
                ':flow_type' => $flowType,
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

function repo_month_summary(PDO $db, int $userId, int $year, int $month): array {
    $sql = "
        SELECT
            SUM(CASE WHEN flow_type = 'income' THEN amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN flow_type = 'expense' THEN amount_signed ELSE 0 END)) AS spending,
            SUM(CASE WHEN flow_type IN ('income','expense') THEN amount_signed ELSE 0 END) AS net
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
            SUM(CASE WHEN t.flow_type = 'income' THEN t.amount_signed ELSE 0 END) AS income,
            ABS(SUM(CASE WHEN t.flow_type = 'expense' THEN t.amount_signed ELSE 0 END)) AS spending,
            SUM(CASE WHEN t.flow_type IN ('income','expense') THEN t.amount_signed ELSE 0 END) AS net
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
