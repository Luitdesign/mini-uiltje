<?php
declare(strict_types=1);

function repo_list_pots(PDO $db, int $userId): array {
    $stmt = $db->prepare(
        "SELECT id, name, archived, start_amount
         FROM pots
         WHERE user_id = :uid
         ORDER BY archived ASC, name ASC, id ASC"
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function repo_list_pots_with_balances(PDO $db, int $userId): array {
    $sql = "
        SELECT
            p.id,
            p.name,
            p.archived,
            p.start_amount,
            COALESCE(alloc.total_allocated, 0) AS allocated_total,
            COALESCE(spent.total_spent, 0) AS spent_total,
            COALESCE(p.start_amount, 0)
                + COALESCE(alloc.total_allocated, 0)
                - COALESCE(spent.total_spent, 0) AS balance
        FROM pots p
        LEFT JOIN (
            SELECT pot_id, SUM(amount) AS total_allocated
            FROM pot_allocations
            WHERE user_id = :uid
            GROUP BY pot_id
        ) alloc ON alloc.pot_id = p.id
        LEFT JOIN (
            SELECT pcm.pot_id,
                   ABS(SUM(CASE WHEN t.amount_signed < 0 THEN t.amount_signed ELSE 0 END)) AS total_spent
            FROM transactions t
            JOIN pot_category_map pcm ON pcm.category_id = t.category_id
            WHERE t.user_id = :uid
            GROUP BY pcm.pot_id
        ) spent ON spent.pot_id = p.id
        WHERE p.user_id = :uid
        ORDER BY p.archived ASC, p.name ASC, p.id ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function repo_get_pot(PDO $db, int $userId, int $potId): ?array {
    if ($potId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT id, name, archived, start_amount
         FROM pots
         WHERE id = :id AND user_id = :uid
         LIMIT 1"
    );
    $stmt->execute([':id' => $potId, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function repo_create_pot(PDO $db, int $userId, string $name, float $startAmount, bool $archived = false): int {
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Pot name cannot be empty.');
    }
    $stmt = $db->prepare(
        "INSERT INTO pots(user_id, name, start_amount, archived)
         VALUES(:uid, :name, :start_amount, :archived)"
    );
    $stmt->execute([
        ':uid' => $userId,
        ':name' => $name,
        ':start_amount' => $startAmount,
        ':archived' => $archived ? 1 : 0,
    ]);
    return (int)$db->lastInsertId();
}

function repo_update_pot(PDO $db, int $userId, int $potId, string $name, float $startAmount, bool $archived): void {
    $name = trim($name);
    if ($potId <= 0) {
        throw new RuntimeException('Invalid pot.');
    }
    if ($name === '') {
        throw new RuntimeException('Pot name cannot be empty.');
    }
    $stmt = $db->prepare(
        "UPDATE pots
         SET name = :name,
             start_amount = :start_amount,
             archived = :archived
         WHERE id = :id AND user_id = :uid"
    );
    $stmt->execute([
        ':name' => $name,
        ':start_amount' => $startAmount,
        ':archived' => $archived ? 1 : 0,
        ':id' => $potId,
        ':uid' => $userId,
    ]);
}

function repo_get_category_pot_map(PDO $db, int $userId): array {
    $sql = "
        SELECT pcm.category_id, pcm.pot_id
        FROM pot_category_map pcm
        JOIN pots p ON p.id = pcm.pot_id
        WHERE p.user_id = :uid
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['category_id']] = (int)$row['pot_id'];
    }
    return $map;
}

function repo_bulk_set_category_pots(PDO $db, int $userId, array $mapping): void {
    $pots = repo_list_pots($db, $userId);
    $validPotIds = [];
    foreach ($pots as $pot) {
        $validPotIds[(int)$pot['id']] = true;
    }

    $insertStmt = $db->prepare(
        "INSERT INTO pot_category_map (category_id, pot_id)
         VALUES (:category_id, :pot_id)
         ON DUPLICATE KEY UPDATE pot_id = VALUES(pot_id)"
    );
    $deleteStmt = $db->prepare(
        "DELETE FROM pot_category_map WHERE category_id = :category_id"
    );

    $db->beginTransaction();
    try {
        foreach ($mapping as $categoryId => $potId) {
            $categoryId = (int)$categoryId;
            if ($categoryId <= 0) {
                continue;
            }
            $potId = (int)$potId;
            if ($potId > 0 && isset($validPotIds[$potId])) {
                $insertStmt->execute([
                    ':category_id' => $categoryId,
                    ':pot_id' => $potId,
                ]);
            } else {
                $deleteStmt->execute([':category_id' => $categoryId]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function repo_list_pot_rules(PDO $db, int $userId, int $potId): array {
    $stmt = $db->prepare(
        "SELECT *
         FROM pot_allocation_rules
         WHERE user_id = :uid AND pot_id = :pot_id
         ORDER BY start_year DESC, start_month DESC, id DESC"
    );
    $stmt->execute([
        ':uid' => $userId,
        ':pot_id' => $potId,
    ]);
    return $stmt->fetchAll();
}

function repo_create_pot_rule(
    PDO $db,
    int $userId,
    int $potId,
    float $amount,
    int $startYear,
    int $startMonth,
    ?int $endYear,
    ?int $endMonth,
    bool $active
): int {
    $stmt = $db->prepare(
        "INSERT INTO pot_allocation_rules (
            user_id,
            pot_id,
            amount_monthly,
            start_year,
            start_month,
            end_year,
            end_month,
            active
        ) VALUES (
            :uid,
            :pot_id,
            :amount,
            :start_year,
            :start_month,
            :end_year,
            :end_month,
            :active
        )"
    );
    $stmt->execute([
        ':uid' => $userId,
        ':pot_id' => $potId,
        ':amount' => $amount,
        ':start_year' => $startYear,
        ':start_month' => $startMonth,
        ':end_year' => $endYear,
        ':end_month' => $endMonth,
        ':active' => $active ? 1 : 0,
    ]);
    return (int)$db->lastInsertId();
}

function repo_update_pot_rule(
    PDO $db,
    int $userId,
    int $ruleId,
    float $amount,
    int $startYear,
    int $startMonth,
    ?int $endYear,
    ?int $endMonth,
    bool $active
): void {
    $stmt = $db->prepare(
        "UPDATE pot_allocation_rules
         SET amount_monthly = :amount,
             start_year = :start_year,
             start_month = :start_month,
             end_year = :end_year,
             end_month = :end_month,
             active = :active
         WHERE id = :id AND user_id = :uid"
    );
    $stmt->execute([
        ':amount' => $amount,
        ':start_year' => $startYear,
        ':start_month' => $startMonth,
        ':end_year' => $endYear,
        ':end_month' => $endMonth,
        ':active' => $active ? 1 : 0,
        ':id' => $ruleId,
        ':uid' => $userId,
    ]);
}

function repo_delete_pot_rule(PDO $db, int $userId, int $ruleId): void {
    $stmt = $db->prepare("DELETE FROM pot_allocation_rules WHERE id = :id AND user_id = :uid");
    $stmt->execute([
        ':id' => $ruleId,
        ':uid' => $userId,
    ]);
}
