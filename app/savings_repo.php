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
