<?php
declare(strict_types=1);

function repo_list_savings(PDO $db): array {
    $sql = "
        SELECT id, name, active, sort_order, start_amount, monthly_amount
        FROM savings
        ORDER BY active DESC, sort_order ASC, name ASC, id ASC
    ";
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

function repo_list_savings_with_balance(PDO $db): array {
    $sql = "
        SELECT s.id, s.name, s.active, s.sort_order, s.start_amount, s.monthly_amount,
               (s.start_amount + COALESCE(se.total_amount, 0)) AS balance
        FROM savings s
        LEFT JOIN (
            SELECT savings_id, SUM(amount) AS total_amount
            FROM savings_entries
            GROUP BY savings_id
        ) se ON se.savings_id = s.id
        ORDER BY s.active DESC, s.sort_order ASC, s.name ASC, s.id ASC
    ";
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

function repo_next_savings_sort_order(PDO $db): int {
    $stmt = $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort_order FROM savings');
    $value = $stmt->fetchColumn();
    return $value !== false ? (int)$value : 1;
}

function repo_list_savings_entries(PDO $db, int $savingsId, int $limit = 5): array {
    $stmt = $db->prepare(
        'SELECT se.id, se.`date`, se.amount, se.entry_type, se.note,
                t.description AS transaction_description
         FROM savings_entries se
         LEFT JOIN transactions t ON t.id = se.source_transaction_id
         WHERE se.savings_id = :sid
         ORDER BY se.`date` DESC, se.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':sid', $savingsId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function repo_create_saving(
    PDO $db,
    string $name,
    float $startAmount,
    float $monthlyAmount,
    int $active,
    int $sortOrder
): int {
    $sql = "
        INSERT INTO savings (name, active, sort_order, start_amount, monthly_amount)
        VALUES (:name, :active, :sort_order, :start_amount, :monthly_amount)
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'name' => $name,
        'active' => $active,
        'sort_order' => $sortOrder,
        'start_amount' => $startAmount,
        'monthly_amount' => $monthlyAmount,
    ]);
    return (int)$db->lastInsertId();
}

function repo_ensure_savings_topup_category(PDO $db): int {
    $existingId = repo_find_category_id($db, 'Savings top-ups');
    if ($existingId !== null) {
        return $existingId;
    }
    $createdId = repo_create_category($db, 'Savings top-ups', null);
    if ($createdId === null) {
        $fallbackId = repo_find_category_id($db, 'Savings top-ups');
        if ($fallbackId !== null) {
            return $fallbackId;
        }
        throw new RuntimeException('Could not create Savings top-ups category.');
    }
    return $createdId;
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

    $categoryId = repo_ensure_savings_topup_category($db);
    $absAmount = abs($amount);
    $description = 'Top-up: ' . (string)$saving['name'];
    $hashSeed = sprintf('internal-topup|%d|%s|%0.2f|%s', $savingsId, $date, $absAmount, uniqid('', true));
    $txnHash = sha1($hashSeed);

    $db->beginTransaction();
    try {
        $stmtTxn = $db->prepare(
            'INSERT INTO transactions(
                user_id, import_id, import_batch_id, txn_hash,
                txn_date, description, friendly_name,
                account_iban, counter_iban, code,
                direction, amount_signed, currency,
                mutation_type, notes, balance_after, tag,
                is_internal_transfer, include_in_overview, ignored, created_source,
                category_id, category_auto_id, rule_auto_id, auto_reason
            ) VALUES(
                :uid, NULL, NULL, :txn_hash,
                :txn_date, :description, NULL,
                NULL, NULL, NULL,
                :direction, :amount_signed, :currency,
                NULL, NULL, NULL, NULL,
                0, 1, 0, :created_source,
                :category_id, NULL, NULL, NULL
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
            ':category_id' => $categoryId,
        ]);
        $transactionId = (int)$db->lastInsertId();

        $stmtEntry = $db->prepare(
            'INSERT INTO savings_entries(
                savings_id, `date`, amount, entry_type, source_transaction_id, note
            ) VALUES(
                :savings_id, :entry_date, :amount, :entry_type, :source_transaction_id, :note
            )'
        );
        $stmtEntry->execute([
            ':savings_id' => $savingsId,
            ':entry_date' => $date,
            ':amount' => $absAmount,
            ':entry_type' => 'topup',
            ':source_transaction_id' => $transactionId,
            ':note' => null,
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
    $stmt = $db->prepare(
        'SELECT id, amount_signed, ignored, txn_date
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
    $amount = (float)($txn['amount_signed'] ?? 0);
    if ($amount >= 0 || !empty($txn['ignored'])) {
        return false;
    }

    $db->beginTransaction();
    try {
        $stmtUpdate = $db->prepare(
            'UPDATE transactions
             SET include_in_overview = 0
             WHERE id = :id AND user_id = :uid'
        );
        $stmtUpdate->execute([
            ':id' => $transactionId,
            ':uid' => $userId,
        ]);

        $stmtEntry = $db->prepare(
            'INSERT INTO savings_entries(
                savings_id, `date`, amount, entry_type, source_transaction_id, note
            ) VALUES(
                :savings_id, :entry_date, :amount, :entry_type, :source_transaction_id, :note
            )
            ON DUPLICATE KEY UPDATE
                savings_id = VALUES(savings_id),
                `date` = VALUES(`date`),
                amount = VALUES(amount),
                entry_type = VALUES(entry_type),
                note = VALUES(note)'
        );
        $stmtEntry->execute([
            ':savings_id' => $savingsId,
            ':entry_date' => $txn['txn_date'],
            ':amount' => -abs($amount),
            ':entry_type' => 'spend',
            ':source_transaction_id' => $transactionId,
            ':note' => null,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return true;
}

function repo_unmark_transaction_paid_from_savings(PDO $db, int $userId, int $transactionId): void {
    $db->beginTransaction();
    try {
        $stmtUpdate = $db->prepare(
            'UPDATE transactions
             SET include_in_overview = 1
             WHERE id = :id AND user_id = :uid'
        );
        $stmtUpdate->execute([
            ':id' => $transactionId,
            ':uid' => $userId,
        ]);

        $stmtDelete = $db->prepare(
            'DELETE FROM savings_entries WHERE source_transaction_id = :id AND entry_type = :entry_type'
        );
        $stmtDelete->execute([
            ':id' => $transactionId,
            ':entry_type' => 'spend',
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function repo_find_saving(PDO $db, int $id): ?array {
    $sql = "
        SELECT id, name, active, sort_order, start_amount, monthly_amount
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
    int $sortOrder
): void {
    $sql = "
        UPDATE savings
        SET name = :name,
            active = :active,
            sort_order = :sort_order,
            start_amount = :start_amount,
            monthly_amount = :monthly_amount
        WHERE id = :id
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'id' => $id,
        'name' => $name,
        'active' => $active,
        'sort_order' => $sortOrder,
        'start_amount' => $startAmount,
        'monthly_amount' => $monthlyAmount,
    ]);
}
