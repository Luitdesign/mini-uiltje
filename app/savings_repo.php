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
