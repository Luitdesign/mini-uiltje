<?php
declare(strict_types=1);

function normalize_tag_name(string $tag): string {
    $tag = trim($tag);
    $tag = preg_replace('/\s+/u', ' ', $tag) ?? '';
    return mb_strtolower($tag, 'UTF-8');
}

function clean_tag_name(string $tag): string {
    $tag = trim($tag);
    return preg_replace('/\s+/u', ' ', $tag) ?? '';
}

function parse_tags_csv(string $raw, int $maxLen = 50): array {
    $parts = explode(',', $raw);
    $seen = [];
    $out = [];
    foreach ($parts as $part) {
        $clean = clean_tag_name((string)$part);
        if ($clean === '') { continue; }
        if (mb_strlen($clean, 'UTF-8') > $maxLen) {
            $clean = mb_substr($clean, 0, $maxLen, 'UTF-8');
            $clean = rtrim($clean);
        }
        $norm = normalize_tag_name($clean);
        if ($norm === '' || isset($seen[$norm])) { continue; }
        $seen[$norm] = true;
        $out[] = $clean;
    }
    return $out;
}

function format_tags_csv(array $tags): ?string {
    if ($tags === []) return null;
    return implode(', ', $tags);
}

function repo_search_tags(PDO $db, int $userId, string $query, int $limit = 10): array {
    $queryClean = clean_tag_name($query);
    $queryNorm = normalize_tag_name($queryClean);
    if ($queryNorm === '') {
        return [];
    }

    $stmt = $db->prepare(
        'SELECT tag FROM transactions WHERE user_id = :user_id AND tag IS NOT NULL AND tag <> "" AND LOWER(tag) LIKE :q ORDER BY id DESC LIMIT 300'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':q' => '%' . $queryNorm . '%',
    ]);

    $scores = [];
    foreach ($stmt->fetchAll() as $row) {
        $tags = parse_tags_csv((string)($row['tag'] ?? ''));
        foreach ($tags as $tag) {
            $norm = normalize_tag_name($tag);
            if ($norm === '' || strpos($norm, $queryNorm) === false) { continue; }
            if (!isset($scores[$norm])) {
                $scores[$norm] = ['id' => 0, 'name' => $tag, 'usage' => 0, 'rank' => 3];
            }
            $scores[$norm]['usage']++;
            if ($norm === $queryNorm) {
                $scores[$norm]['rank'] = min($scores[$norm]['rank'], 1);
            } elseif (str_starts_with($norm, $queryNorm)) {
                $scores[$norm]['rank'] = min($scores[$norm]['rank'], 2);
            }
        }
    }

    usort($scores, static function(array $a, array $b): int {
        return ($a['rank'] <=> $b['rank']) ?: ($b['usage'] <=> $a['usage']) ?: strcasecmp($a['name'], $b['name']);
    });

    return array_slice(array_values($scores), 0, $limit);
}

function repo_list_tags_with_totals(PDO $db, int $userId): array {
    $stmt = $db->prepare(
        'SELECT id, txn_date, tag, amount_signed
         FROM transactions
         WHERE user_id = :user_id
           AND tag IS NOT NULL
           AND tag <> ""'
    );
    $stmt->execute([':user_id' => $userId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $amount = (float)($row['amount_signed'] ?? 0);
        $txnDate = (string)($row['txn_date'] ?? '');
        $txnId = (int)($row['id'] ?? 0);
        foreach (parse_tags_csv((string)($row['tag'] ?? '')) as $tag) {
            $norm = normalize_tag_name($tag);
            if ($norm === '') {
                continue;
            }
            if (!isset($rows[$norm])) {
                $rows[$norm] = [
                    'tag' => $tag,
                    'income' => 0.0,
                    'spending' => 0.0,
                    'net' => 0.0,
                    'last_txn_date' => '',
                    'last_txn_id' => 0,
                ];
            }
            if ($amount > 0) {
                $rows[$norm]['income'] += $amount;
            } elseif ($amount < 0) {
                $rows[$norm]['spending'] += abs($amount);
            }
            $rows[$norm]['net'] += $amount;

            if (
                $txnDate > (string)$rows[$norm]['last_txn_date']
                || ($txnDate === (string)$rows[$norm]['last_txn_date'] && $txnId > (int)$rows[$norm]['last_txn_id'])
            ) {
                $rows[$norm]['last_txn_date'] = $txnDate;
                $rows[$norm]['last_txn_id'] = $txnId;
            }
        }
    }

    $list = array_values($rows);
    usort($list, static function (array $a, array $b): int {
        return ((string)$b['last_txn_date'] <=> (string)$a['last_txn_date'])
            ?: ((int)$b['last_txn_id'] <=> (int)$a['last_txn_id'])
            ?: strcmp(normalize_tag_name((string)$a['tag']), normalize_tag_name((string)$b['tag']));
    });
    return $list;
}

function repo_rename_tag(PDO $db, int $userId, string $oldTag, string $newTag): int {
    $oldTagNorm = normalize_tag_name($oldTag);
    $newTagClean = clean_tag_name($newTag);
    if ($oldTagNorm === '' || $newTagClean === '') {
        return 0;
    }

    $stmt = $db->prepare(
        'SELECT id, tag
         FROM transactions
         WHERE user_id = :user_id
           AND is_split_active = 1
           AND tag IS NOT NULL
           AND tag <> ""'
    );
    $stmt->execute([':user_id' => $userId]);

    $update = $db->prepare('UPDATE transactions SET tag = :tag WHERE id = :id AND user_id = :user_id');
    $updated = 0;
    foreach ($stmt->fetchAll() as $row) {
        $rawTags = parse_tags_csv((string)($row['tag'] ?? ''));
        if ($rawTags === []) {
            continue;
        }

        $changed = false;
        $mapped = [];
        foreach ($rawTags as $rawTag) {
            if (normalize_tag_name($rawTag) === $oldTagNorm) {
                $mapped[] = $newTagClean;
                $changed = true;
            } else {
                $mapped[] = $rawTag;
            }
        }
        if (!$changed) {
            continue;
        }

        $normalized = format_tags_csv(parse_tags_csv(implode(', ', $mapped)));
        $update->execute([
            ':tag' => $normalized,
            ':id' => (int)$row['id'],
            ':user_id' => $userId,
        ]);
        $updated++;
    }

    return $updated;
}
