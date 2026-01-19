<?php
declare(strict_types=1);

require_once __DIR__ . '/ing_csv.php';

function db_has_tables(): bool {
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'users'");
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function list_months(): array {
    $stmt = db()->query("SELECT DISTINCT DATE_FORMAT(tx_date,'%Y-%m') AS yyyymm FROM transactions ORDER BY yyyymm DESC");
    return array_map(fn($r) => $r['yyyymm'], $stmt->fetchAll());
}

function get_month_summary_counts(string $yyyymm): array {
    $stmt = db()->prepare("SELECT
        SUM(CASE WHEN is_confirmed = 0 OR (manual_category_id IS NULL AND auto_category_id IS NULL) THEN 1 ELSE 0 END) AS needs_review,
        COUNT(*) AS total
        FROM transactions WHERE DATE_FORMAT(tx_date,'%Y-%m') = ?");
    $stmt->execute([$yyyymm]);
    return $stmt->fetch() ?: ['needs_review'=>0,'total'=>0];
}

function list_categories(): array {
    $stmt = db()->query("SELECT id, type, name, sort_order, is_active FROM categories WHERE is_active = 1 ORDER BY type, sort_order, name");
    return $stmt->fetchAll();
}

function categories_for_select(): array {
    $cats = list_categories();
    $out = [];
    foreach ($cats as $cat) {
        $out[] = [
            'id' => (int)$cat['id'],
            'type' => $cat['type'],
            'label' => $cat['name'],
        ];
    }
    return $out;
}

function import_ing_file(string $tmpPath, string $originalFilename, int $uploadedByUserId): array {
    $fileHash = hash_file('sha256', $tmpPath);

    // block same file
    $stmt = db()->prepare('SELECT id FROM imports WHERE file_hash = ?');
    $stmt->execute([$fileHash]);
    if ($stmt->fetch()) {
        throw new RuntimeException('This file was already imported.');
    }

    $ins = db()->prepare('INSERT INTO imports (uploaded_by_user_id, original_filename, file_hash) VALUES (?, ?, ?)');
    $ins->execute([$uploadedByUserId, $originalFilename, $fileHash]);
    $importId = (int)db()->lastInsertId();

    $rows = parse_ing_csv($tmpPath);

    $inserted = 0;
    $duplicates = 0;

    $stmtTx = db()->prepare(
        'INSERT INTO transactions
        (import_id, tx_date, name_description, account_iban, counterparty_iban, code, direction, amount, amount_signed, mutation_type, messages, balance_after, tag, tx_hash, is_confirmed)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );

    foreach ($rows as $tx) {
        $hash = tx_hash($tx);
        try {
            $stmtTx->execute([
                $importId,
                $tx['tx_date'],
                $tx['name_description'],
                $tx['account_iban'],
                $tx['counterparty_iban'] ?: null,
                $tx['code'] ?: null,
                $tx['direction'],
                $tx['amount'],
                $tx['amount_signed'],
                $tx['mutation_type'] ?: null,
                $tx['messages'] ?: null,
                $tx['balance_after'],
                $tx['tag'] ?: null,
                $hash,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            // duplicate tx_hash
            if ((int)$e->errorInfo[1] === 1062) {
                $duplicates++;
                continue;
            }
            throw $e;
        }
    }

    $upd = db()->prepare('UPDATE imports SET inserted_count=?, duplicate_count=? WHERE id=?');
    $upd->execute([$inserted, $duplicates, $importId]);

    return ['import_id'=>$importId,'inserted'=>$inserted,'duplicates'=>$duplicates,'months'=>list_months()];
}

function fetch_transactions_for_month(string $yyyymm, string $mode = 'all'): array {
    $where = "DATE_FORMAT(tx_date,'%Y-%m') = ?";
    if ($mode === 'review') {
        $where .= " AND (is_confirmed = 0 OR (manual_category_id IS NULL AND auto_category_id IS NULL))";
    } elseif ($mode === 'confirmed') {
        $where .= " AND is_confirmed = 1";
    }

    $sql = "SELECT t.*,
        COALESCE(t.manual_category_id, t.auto_category_id) AS final_category_id,
        c1.name AS auto_category_name,
        c2.name AS manual_category_name,
        cf.name AS final_category_name,
        cf.type AS final_category_type
        FROM transactions t
        LEFT JOIN categories c1 ON c1.id = t.auto_category_id
        LEFT JOIN categories c2 ON c2.id = t.manual_category_id
        LEFT JOIN categories cf ON cf.id = COALESCE(t.manual_category_id, t.auto_category_id)
        WHERE $where
        ORDER BY tx_date DESC, id DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute([$yyyymm]);
    return $stmt->fetchAll();
}

function update_manual_category(int $transactionId, ?int $categoryId, bool $confirm = true): void {
    $sql = 'UPDATE transactions SET manual_category_id = ?, is_confirmed = ? WHERE id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute([$categoryId, $confirm ? 1 : 0, $transactionId]);
}

function confirm_transaction(int $transactionId): void {
    $stmt = db()->prepare('UPDATE transactions SET is_confirmed = 1 WHERE id = ?');
    $stmt->execute([$transactionId]);
}

function confirm_all_in_month(string $yyyymm): int {
    $stmt = db()->prepare("UPDATE transactions SET is_confirmed = 1 WHERE DATE_FORMAT(tx_date,'%Y-%m') = ?");
    $stmt->execute([$yyyymm]);
    return $stmt->rowCount();
}

function month_results(string $yyyymm): array {
    // If splits exist, use them; otherwise use original transaction.
    // For this website baseline we only calculate from transactions (splits table is ready for later).

    $tx = fetch_transactions_for_month($yyyymm, 'all');

    $income = 0.0;
    $expense = 0.0;
    $transfer = 0.0;

    $byCategory = []; // [category => amount]

    foreach ($tx as $t) {
        $signed = (float)$t['amount_signed'];

        $catName = $t['final_category_name'] ?: '(uncategorized)';
        $catType = $t['final_category_type'] ?: null;

        if ($catType === 'transfer') {
            $transfer += $signed;
        } else {
            if ($signed >= 0) $income += $signed;
            else $expense += $signed;
        }

        if (!isset($byCategory[$catName])) $byCategory[$catName] = 0.0;
        $byCategory[$catName] += $signed;
    }

    // Sort categories by absolute value descending
    uksort($byCategory, function($a, $b) use ($byCategory) {
        return abs($byCategory[$b]) <=> abs($byCategory[$a]);
    });

    $net = $income + $expense + $transfer;

    return [
        'income' => round($income, 2),
        'expense' => round($expense, 2),
        'transfer' => round($transfer, 2),
        'net' => round($net, 2),
        'by_category' => $byCategory,
        'count' => count($tx),
    ];
}
