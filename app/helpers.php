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

function normalize_hex_color(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $value)) {
        throw new RuntimeException('Color must be a 6-digit hex value like #6ee7b7.');
    }
    if ($value[0] !== '#') {
        $value = '#' . $value;
    }
    return strtolower($value);
}

function safe_strimwidth(string $value, int $start, int $width, string $trimMarker = '…'): string {
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, $start, $width, $trimMarker);
    }

    $slice = substr($value, $start, $width);
    if (strlen($value) > $width) {
        return $slice . $trimMarker;
    }

    return $slice;
}

function rgba_from_hex(?string $value, float $alpha = 0.12): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $value)) {
        return null;
    }
    $hex = ltrim($value, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $alpha = max(0.0, min(1.0, $alpha));
    return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $alpha);
}

function is_internal_transfer_description(string $description): bool {
    $value = trim(mb_strtolower($description));
    if ($value === '') {
        return false;
    }
    $needles = [
        'van oranje spaarrekening',
        'naar oranje spaarrekening',
        'oranje spaarrekening',
    ];
    foreach ($needles as $needle) {
        if (str_contains($value, $needle)) {
            return true;
        }
    }
    return false;
}

function app_version(): string {
    $versionFile = __DIR__ . '/../VERSION';
    $v = file_exists($versionFile) ? trim((string)file_get_contents($versionFile)) : 'dev';
    $commit = getenv('APP_COMMIT') ?: '';
    if ($commit !== '') {
        return $v . '+' . $commit;
    }
    return $v;
}
