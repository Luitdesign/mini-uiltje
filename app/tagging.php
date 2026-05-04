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
