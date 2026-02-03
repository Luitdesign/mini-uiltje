<?php
declare(strict_types=1);

function repo_list_savings(PDO $db): array {
    $sql = "
        SELECT id, name, active, sort_order, start_amount, monthly_amount, topup_category_id
        FROM savings
        ORDER BY active DESC, sort_order ASC, name ASC, id ASC
    ";
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

function repo_list_savings_with_balance(PDO $db): array {
    $sql = "
        SELECT s.id, s.name, s.active, s.sort_order, s.start_amount, s.monthly_amount, s.topup_category_id,
               (s.start_amount + COALESCE(st.total_amount, 0)) AS balance
        FROM savings s
        LEFT JOIN (
            SELECT savings_id,
                   SUM(
                       CASE
                           WHEN is_topup = 1 THEN ABS(amount_signed)
                           ELSE amount_signed
                       END
                   ) AS total_amount
            FROM transactions
            WHERE savings_id IS NOT NULL
              AND ignored = 0
            GROUP BY savings_id
        ) st ON st.savings_id = s.id
        ORDER BY s.active DESC, s.sort_order ASC, s.name ASC, s.id ASC
    ";
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

function repo_find_saving_with_balance(PDO $db, int $id): ?array {
    $sql = "
        SELECT s.id, s.name, s.active, s.sort_order, s.start_amount, s.monthly_amount, s.topup_category_id,
               (s.start_amount + COALESCE(st.total_amount, 0)) AS balance
        FROM savings s
        LEFT JOIN (
            SELECT savings_id,
                   SUM(
                       CASE
                           WHEN is_topup = 1 THEN ABS(amount_signed)
                           ELSE amount_signed
                       END
                   ) AS total_amount
            FROM transactions
            WHERE savings_id IS NOT NULL
              AND ignored = 0
            GROUP BY savings_id
        ) st ON st.savings_id = s.id
        WHERE s.id = :id
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(['id' => $id]);
    $saving = $stmt->fetch();
    return $saving === false ? null : $saving;
}

function repo_next_savings_sort_order(PDO $db): int {
    $stmt = $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort_order FROM savings');
    $value = $stmt->fetchColumn();
    return $value !== false ? (int)$value : 1;
}

function repo_list_savings_entries(PDO $db, int $savingsId, ?int $limit = null): array {
    $sql = 'SELECT t.id,
                   t.txn_date AS `date`,
                   CASE
                       WHEN t.is_topup = 1 THEN ABS(t.amount_signed)
                       ELSE t.amount_signed
                   END AS amount,
                   CASE
                       WHEN t.is_topup = 1 THEN "topup"
                       WHEN t.amount_signed >= 0 THEN "income"
                       ELSE "spend"
                   END AS entry_type,
                   t.notes AS note,
                   t.description AS transaction_description
            FROM transactions t
            WHERE t.savings_id = :sid
            ORDER BY t.txn_date DESC, t.id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT :limit';
    }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':sid', $savingsId, PDO::PARAM_INT);
    if ($limit !== null) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function repo_create_saving(
    PDO $db,
    string $name,
    float $startAmount,
    float $monthlyAmount,
    int $active,
    int $sortOrder,
    ?int $topupCategoryId = null
): int {
    $sql = "
        INSERT INTO savings (name, active, sort_order, start_amount, monthly_amount, topup_category_id)
        VALUES (:name, :active, :sort_order, :start_amount, :monthly_amount, :topup_category_id)
    ";
    $stmt = $db->prepare($sql);
    $topupCategoryId = $topupCategoryId !== null && $topupCategoryId > 0 ? $topupCategoryId : null;
    $stmt->execute([
        'name' => $name,
        'active' => $active,
        'sort_order' => $sortOrder,
        'start_amount' => $startAmount,
        'monthly_amount' => $monthlyAmount,
        'topup_category_id' => $topupCategoryId,
    ]);
    return (int)$db->lastInsertId();
}

function repo_add_savings_topup(
    PDO $db,
    int $userId,
    int $savingsId,
    string $date,
    float $amount
): void {
    if ($amount <= 0) {
        throw new RuntimeException('Top-up amount must be greater than zero.');
    }
    $saving = repo_find_saving($db, $savingsId);
    if (!$saving) {
        throw new RuntimeException('Saving not found.');
    }

    $absAmount = abs($amount);
    $description = 'Top-up: ' . (string)$saving['name'];
    $hashSeed = sprintf('internal-topup|%d|%s|%0.2f|%s', $savingsId, $date, $absAmount, uniqid('', true));
    $txnHash = sha1($hashSeed);
    $topupCategoryId = isset($saving['topup_category_id']) ? (int)$saving['topup_category_id'] : null;
    if ($topupCategoryId !== null && $topupCategoryId <= 0) {
        $topupCategoryId = null;
    }

    $db->beginTransaction();
    try {
        $stmtTxn = $db->prepare(
            'INSERT INTO transactions(
                user_id, import_id, import_batch_id, txn_hash,
                txn_date, description, friendly_name,
                account_iban, counter_iban, code,
                direction, amount_signed, currency,
                mutation_type, notes, balance_after, tag,
                is_internal_transfer, ignored, created_source,
                category_id, category_auto_id, rule_auto_id, auto_reason,
                savings_id, is_topup
            ) VALUES(
                :uid, NULL, NULL, :txn_hash,
                :txn_date, :description, NULL,
                NULL, NULL, NULL,
                :direction, :amount_signed, :currency,
                NULL, NULL, NULL, NULL,
                0, 0, :created_source,
                :category_id, NULL, NULL, NULL,
                :savings_id, :is_topup
            )'
        );
        $stmtTxn->execute([
            ':uid' => $userId,
            ':txn_hash' => $txnHash,
            ':txn_date' => $date,
            ':description' => $description,
            ':direction' => 'Af',
            ':amount_signed' => -$absAmount,
            ':currency' => 'EUR',
            ':created_source' => 'internal',
            ':category_id' => $topupCategoryId,
            ':savings_id' => $savingsId,
            ':is_topup' => 1,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function repo_mark_transaction_paid_from_savings(
    PDO $db,
    int $userId,
    int $transactionId,
    int $savingsId
): bool {
    return repo_set_transaction_ledger($db, $userId, $transactionId, $savingsId);
}

function repo_unmark_transaction_paid_from_savings(PDO $db, int $userId, int $transactionId): void {
    repo_set_transaction_ledger($db, $userId, $transactionId, null);
}

function repo_set_transaction_ledger(
    PDO $db,
    int $userId,
    int $transactionId,
    ?int $savingsId
): bool {
    $stmt = $db->prepare(
        'SELECT id, amount_signed, ignored, txn_date, is_topup
         FROM transactions
         WHERE id = :id AND user_id = :uid
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $transactionId,
        ':uid' => $userId,
    ]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$txn) {
        return false;
    }

    if ($savingsId === null || $savingsId <= 0) {
        $db->beginTransaction();
        try {
            $stmtUpdate = $db->prepare(
                'UPDATE transactions
                 SET savings_id = NULL,
                     is_topup = 0
                 WHERE id = :id AND user_id = :uid'
            );
            $stmtUpdate->execute([
                ':id' => $transactionId,
                ':uid' => $userId,
            ]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return true;
    }

    $saving = repo_find_saving($db, $savingsId);
    if (!$saving) {
        throw new RuntimeException('Saving not found.');
    }

    $amount = (float)($txn['amount_signed'] ?? 0);
    if (!empty($txn['ignored'])) {
        return false;
    }

    $db->beginTransaction();
    try {
        $stmtUpdate = $db->prepare(
            'UPDATE transactions
             SET savings_id = :savings_id,
                 is_topup = :is_topup
             WHERE id = :id AND user_id = :uid'
        );
        $stmtUpdate->execute([
            ':id' => $transactionId,
            ':uid' => $userId,
            ':savings_id' => $savingsId,
            ':is_topup' => (int)($txn['is_topup'] ?? 0),
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return true;
}

function repo_apply_category_ledger(
    PDO $db,
    int $userId,
    int $categoryId,
    ?int $savingsId
): int {
    if ($categoryId <= 0) {
        return 0;
    }
    $stmt = $db->prepare(
        'SELECT id
         FROM transactions
         WHERE user_id = :uid
           AND category_id = :cid'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':cid' => $categoryId,
    ]);
    $txnIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $updated = 0;
    foreach ($txnIds as $txnId) {
        if (repo_set_transaction_ledger($db, $userId, (int)$txnId, $savingsId)) {
            $updated++;
        }
    }
    return $updated;
}

function repo_find_saving(PDO $db, int $id): ?array {
    $sql = "
        SELECT id, name, active, sort_order, start_amount, monthly_amount, topup_category_id
        FROM savings
        WHERE id = :id
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(['id' => $id]);
    $saving = $stmt->fetch();
    return $saving === false ? null : $saving;
}

function repo_update_saving(
    PDO $db,
    int $id,
    string $name,
    float $startAmount,
    float $monthlyAmount,
    int $active,
    int $sortOrder,
    ?int $topupCategoryId = null
): void {
    $sql = "
        UPDATE savings
        SET name = :name,
            active = :active,
            sort_order = :sort_order,
            start_amount = :start_amount,
            monthly_amount = :monthly_amount,
            topup_category_id = :topup_category_id
        WHERE id = :id
    ";
    $stmt = $db->prepare($sql);
    $topupCategoryId = $topupCategoryId !== null && $topupCategoryId > 0 ? $topupCategoryId : null;
    $stmt->execute([
        'id' => $id,
        'name' => $name,
        'active' => $active,
        'sort_order' => $sortOrder,
        'start_amount' => $startAmount,
        'monthly_amount' => $monthlyAmount,
        'topup_category_id' => $topupCategoryId,
    ]);
}
