<?php
declare(strict_types=1);

require_once __DIR__ . '/rules_engine.php';

function rule_match_fields(): array {
    return [
        'name_description' => 'Name / Description',
        'messages' => 'Messages',
        'counterparty_iban' => 'Counterparty IBAN',
        'account_iban' => 'Account IBAN',
        'code' => 'Code',
        'mutation_type' => 'Mutation type',
        'tag' => 'Tag',
        'direction' => 'Direction',
        'amount' => 'Amount',
        'amount_signed' => 'Signed amount',
    ];
}

function list_rules(): array {
    $stmt = db()->query(
        'SELECT r.*, c.name AS category_name
         FROM rules r
         LEFT JOIN categories c ON c.id = r.category_id
         ORDER BY r.position ASC'
    );
    return $stmt->fetchAll();
}

function count_rules(): int {
    $stmt = db()->query('SELECT COUNT(*) AS cnt FROM rules');
    $row = $stmt->fetch();
    return (int)($row['cnt'] ?? 0);
}

function get_rule(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM rules WHERE id = ?');
    $stmt->execute([$id]);
    $rule = $stmt->fetch();
    return $rule ?: null;
}

function create_rule(array $data): int {
    $db = db();
    $db->beginTransaction();
    try {
        $position = (int)$data['position'];
        $stmt = $db->prepare('UPDATE rules SET position = position + 1 WHERE position >= ?');
        $stmt->execute([$position]);

        $insert = $db->prepare(
            'INSERT INTO rules (is_active, position, active_from, match_field, match_op, match_value, category_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $insert->execute([
            (int)$data['is_active'],
            $position,
            $data['active_from'],
            $data['match_field'],
            $data['match_op'],
            $data['match_value'],
            (int)$data['category_id'],
        ]);

        $id = (int)$db->lastInsertId();
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function update_rule(int $id, array $data): void {
    $db = db();
    $db->beginTransaction();
    try {
        $current = get_rule($id);
        if (!$current) {
            throw new RuntimeException('Rule not found.');
        }

        $newPos = (int)$data['position'];
        $oldPos = (int)$current['position'];
        if ($newPos !== $oldPos) {
            if ($newPos < $oldPos) {
                $stmt = $db->prepare('UPDATE rules SET position = position + 1 WHERE position >= ? AND position < ?');
                $stmt->execute([$newPos, $oldPos]);
            } else {
                $stmt = $db->prepare('UPDATE rules SET position = position - 1 WHERE position <= ? AND position > ?');
                $stmt->execute([$newPos, $oldPos]);
            }
        }

        $update = $db->prepare(
            'UPDATE rules
             SET is_active = ?, position = ?, active_from = ?, match_field = ?, match_op = ?, match_value = ?, category_id = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $update->execute([
            (int)$data['is_active'],
            $newPos,
            $data['active_from'],
            $data['match_field'],
            $data['match_op'],
            $data['match_value'],
            (int)$data['category_id'],
            $id,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function move_rule(int $id, string $direction): void {
    $db = db();
    $current = get_rule($id);
    if (!$current) {
        return;
    }

    $delta = $direction === 'up' ? -1 : 1;
    $newPos = (int)$current['position'] + $delta;
    if ($newPos < 1) {
        return;
    }

    $stmt = $db->prepare('SELECT id, position FROM rules WHERE position = ?');
    $stmt->execute([$newPos]);
    $swap = $stmt->fetch();
    if (!$swap) {
        return;
    }

    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE rules SET position = ? WHERE id = ?');
        $update->execute([(int)$current['position'], (int)$swap['id']]);
        $update->execute([$newPos, $id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function set_rule_active(int $id, bool $active): void {
    $stmt = db()->prepare('UPDATE rules SET is_active = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
}

function recategorize_month_with_rules(string $yyyymm): int {
    $db = db();
    $stmt = $db->prepare(
        "SELECT * FROM transactions WHERE DATE_FORMAT(tx_date,'%Y-%m') = ? AND manual_category_id IS NULL"
    );
    $stmt->execute([$yyyymm]);
    $txs = $stmt->fetchAll();

    $updated = 0;
    $updateStmt = $db->prepare(
        'UPDATE transactions SET auto_category_id = ?, auto_rule_id = ?, is_confirmed = ? WHERE id = ?'
    );

    foreach ($txs as $tx) {
        $result = apply_rules_to_transaction($db, $tx);
        $newAuto = $result['auto_category_id'];
        $newRule = $result['auto_rule_id'];
        $oldAuto = $tx['auto_category_id'] ?? null;
        $oldRule = $tx['auto_rule_id'] ?? null;

        if ($newAuto != $oldAuto || $newRule != $oldRule) {
            $resetConfirm = $newAuto != $oldAuto ? 0 : (int)$tx['is_confirmed'];
            $updateStmt->execute([$newAuto, $newRule, $resetConfirm, (int)$tx['id']]);
            $updated++;
        }
    }

    return $updated;
}

function apply_rules_from_active_date(int $ruleId): int {
    $rule = get_rule($ruleId);
    if (!$rule) {
        return 0;
    }

    $db = db();
    $stmt = $db->prepare(
        'SELECT * FROM transactions WHERE tx_date >= ? AND manual_category_id IS NULL'
    );
    $stmt->execute([$rule['active_from']]);
    $txs = $stmt->fetchAll();

    $updated = 0;
    $updateStmt = $db->prepare(
        'UPDATE transactions SET auto_category_id = ?, auto_rule_id = ?, is_confirmed = ? WHERE id = ?'
    );

    foreach ($txs as $tx) {
        $result = apply_rules_to_transaction($db, $tx);
        $newAuto = $result['auto_category_id'];
        $newRule = $result['auto_rule_id'];
        $oldAuto = $tx['auto_category_id'] ?? null;
        $oldRule = $tx['auto_rule_id'] ?? null;

        if ($newAuto != $oldAuto || $newRule != $oldRule) {
            $resetConfirm = $newAuto != $oldAuto ? 0 : (int)$tx['is_confirmed'];
            $updateStmt->execute([$newAuto, $newRule, $resetConfirm, (int)$tx['id']]);
            $updated++;
        }
    }

    return $updated;
}

function preview_apply_rules_from_active_date(int $ruleId): int {
    $rule = get_rule($ruleId);
    if (!$rule) {
        return 0;
    }

    $db = db();
    $stmt = $db->prepare(
        'SELECT * FROM transactions WHERE tx_date >= ? AND manual_category_id IS NULL'
    );
    $stmt->execute([$rule['active_from']]);
    $txs = $stmt->fetchAll();

    $count = 0;
    foreach ($txs as $tx) {
        $result = apply_rules_to_transaction($db, $tx);
        $newAuto = $result['auto_category_id'];
        $oldAuto = $tx['auto_category_id'] ?? null;
        if ($newAuto != $oldAuto) {
            $count++;
        }
    }

    return $count;
}
