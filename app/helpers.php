<?php
declare(strict_types=1);

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function current_year_month_from_txn_date(?string $dateStr): array {
    if (!$dateStr) {
        return [ (int)date('Y'), (int)date('n') ];
    }
    $dt = new DateTime($dateStr);
    return [ (int)$dt->format('Y'), (int)$dt->format('n') ];
}
