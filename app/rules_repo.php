<?php
declare(strict_types=1);

function repo_list_rules(PDO $db, int $userId): array {
    $sql = "
        SELECT r.*, c.name AS category_name
        FROM rules r
        LEFT JOIN categories c ON c.id = r.target_category_id
        WHERE r.user_id = :uid
        ORDER BY r.priority ASC, r.id ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function repo_find_rule(PDO $db, int $userId, int $ruleId): ?array {
    $sql = "
        SELECT r.*, c.name AS category_name
        FROM rules r
        LEFT JOIN categories c ON c.id = r.target_category_id
        WHERE r.user_id = :uid AND r.id = :id
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':uid' => $userId, ':id' => $ruleId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function repo_get_max_priority(PDO $db, int $userId): int {
    $stmt = $db->prepare('SELECT MAX(priority) FROM rules WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $value = $stmt->fetchColumn();
    return ($value === null) ? 0 : (int)$value;
}

function repo_create_rule(PDO $db, int $userId, array $data): int {
    $priorityRaw = $data['priority'] ?? null;
    $priority = ($priorityRaw === null || $priorityRaw === '')
        ? (repo_get_max_priority($db, $userId) + 1)
        : (int)$priorityRaw;

    $allowedMatches = ['contains', 'starts', 'equals'];
    $fromTextMatch = in_array($data['from_text_match'] ?? null, $allowedMatches, true)
        ? (string)$data['from_text_match']
        : null;
    $mededelingenMatch = in_array($data['mededelingen_match'] ?? null, $allowedMatches, true)
        ? (string)$data['mededelingen_match']
        : null;

    $stmt = $db->prepare(
        "INSERT INTO rules (
            user_id,
            active,
            priority,
            name,
            from_text,
            from_text_match,
            from_iban,
            mededelingen_text,
            mededelingen_match,
            rekening_equals,
            amount_min,
            amount_max,
            target_category_id
        ) VALUES (
            :uid,
            :active,
            :priority,
            :name,
            :from_text,
            :from_text_match,
            :from_iban,
            :mededelingen_text,
            :mededelingen_match,
            :rekening_equals,
            :amount_min,
            :amount_max,
            :target_category_id
        )"
    );

    $stmt->execute([
        ':uid' => $userId,
        ':active' => !empty($data['active']) ? 1 : 0,
        ':priority' => $priority,
        ':name' => (string)$data['name'],
        ':from_text' => $data['from_text'] ?: null,
        ':from_text_match' => $fromTextMatch,
        ':from_iban' => $data['from_iban'] ?: null,
        ':mededelingen_text' => $data['mededelingen_text'] ?: null,
        ':mededelingen_match' => $mededelingenMatch,
        ':rekening_equals' => $data['rekening_equals'] ?: null,
        ':amount_min' => $data['amount_min'] ?: null,
        ':amount_max' => $data['amount_max'] ?: null,
        ':target_category_id' => $data['target_category_id'] ?: null,
    ]);

    return (int)$db->lastInsertId();
}

function repo_update_rule(PDO $db, int $userId, int $ruleId, array $data): void {
    $allowedMatches = ['contains', 'starts', 'equals'];
    $fromTextMatch = in_array($data['from_text_match'] ?? null, $allowedMatches, true)
        ? (string)$data['from_text_match']
        : null;
    $mededelingenMatch = in_array($data['mededelingen_match'] ?? null, $allowedMatches, true)
        ? (string)$data['mededelingen_match']
        : null;

    $stmt = $db->prepare(
        "UPDATE rules
         SET active = :active,
             priority = :priority,
             name = :name,
             from_text = :from_text,
             from_text_match = :from_text_match,
             from_iban = :from_iban,
             mededelingen_text = :mededelingen_text,
             mededelingen_match = :mededelingen_match,
             rekening_equals = :rekening_equals,
             amount_min = :amount_min,
             amount_max = :amount_max,
             target_category_id = :target_category_id
         WHERE id = :id AND user_id = :uid"
    );

    $stmt->execute([
        ':active' => !empty($data['active']) ? 1 : 0,
        ':priority' => (int)($data['priority'] ?? 0),
        ':name' => (string)$data['name'],
        ':from_text' => $data['from_text'] ?: null,
        ':from_text_match' => $fromTextMatch,
        ':from_iban' => $data['from_iban'] ?: null,
        ':mededelingen_text' => $data['mededelingen_text'] ?: null,
        ':mededelingen_match' => $mededelingenMatch,
        ':rekening_equals' => $data['rekening_equals'] ?: null,
        ':amount_min' => $data['amount_min'] ?: null,
        ':amount_max' => $data['amount_max'] ?: null,
        ':target_category_id' => $data['target_category_id'] ?: null,
        ':id' => $ruleId,
        ':uid' => $userId,
    ]);
}
