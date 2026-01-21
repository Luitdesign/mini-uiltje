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

function repo_next_savings_sort_order(PDO $db): int {
    $stmt = $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort_order FROM savings');
    $value = $stmt->fetchColumn();
    return $value !== false ? (int)$value : 1;
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
