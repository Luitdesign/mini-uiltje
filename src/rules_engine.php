<?php
declare(strict_types=1);

function normalize(?string $value): string {
    $value = $value ?? '';
    $value = strtolower($value);
    $normalized = preg_replace('/\s+/', '', $value);
    return $normalized ?? '';
}

function rules_regex_pattern(string $raw): string {
    return '#'.$raw.'#i';
}

function rules_regex_is_valid(string $raw): bool {
    $pattern = rules_regex_pattern($raw);
    return @preg_match($pattern, '') !== false;
}

function get_candidate_rules(PDO $db, string $txDate): array {
    $stmt = $db->prepare(
        'SELECT id, is_active, position, active_from, match_field, match_op, match_value, category_id
         FROM rules
         WHERE is_active = 1 AND active_from <= ?
         ORDER BY position ASC'
    );
    $stmt->execute([$txDate]);
    return $stmt->fetchAll();
}

function match_rule(array $rule, array $txRow): bool {
    $field = (string)($rule['match_field'] ?? '');
    if ($field === '' || !array_key_exists($field, $txRow)) {
        return false;
    }

    $value = (string)($txRow[$field] ?? '');
    $normalizedValue = normalize($value);
    $normalizedMatch = normalize((string)($rule['match_value'] ?? ''));

    switch ($rule['match_op']) {
        case 'contains':
            if ($normalizedMatch === '') {
                return false;
            }
            return str_contains($normalizedValue, $normalizedMatch);
        case 'starts_with':
            if ($normalizedMatch === '') {
                return false;
            }
            return str_starts_with($normalizedValue, $normalizedMatch);
        case 'equals':
            return $normalizedValue === $normalizedMatch;
        case 'regex':
            if (!rules_regex_is_valid((string)$rule['match_value'])) {
                return false;
            }
            return preg_match(rules_regex_pattern((string)$rule['match_value']), $normalizedValue) === 1;
        default:
            return false;
    }
}

function find_first_match(PDO $db, array $txRow): ?array {
    $txDate = (string)($txRow['tx_date'] ?? '');
    if ($txDate === '') {
        return null;
    }

    $rules = get_candidate_rules($db, $txDate);
    foreach ($rules as $rule) {
        if (match_rule($rule, $txRow)) {
            return $rule;
        }
    }

    return null;
}

function apply_rules_to_transaction(PDO $db, array $txRow): array {
    if (!empty($txRow['manual_category_id'])) {
        return [
            'auto_category_id' => $txRow['auto_category_id'] ?? null,
            'auto_rule_id' => $txRow['auto_rule_id'] ?? null,
        ];
    }

    $rule = find_first_match($db, $txRow);
    if (!$rule) {
        return ['auto_category_id' => null, 'auto_rule_id' => null];
    }

    return [
        'auto_category_id' => (int)$rule['category_id'],
        'auto_rule_id' => (int)$rule['id'],
    ];
}
