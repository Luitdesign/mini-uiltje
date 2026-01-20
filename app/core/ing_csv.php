<?php
declare(strict_types=1);

/**
 * Parse ING transaction export CSV.
 * Expected header columns:
 * Datum; Naam / Omschrijving; Rekening; Tegenrekening; Code; Af Bij; Bedrag (EUR);
 * Mutatiesoort; Mededelingen; Saldo na mutatie; Tag
 */
function parse_ing_csv(string $filepath): array {
    $fh = fopen($filepath, 'rb');
    if (!$fh) {
        throw new RuntimeException('Cannot open CSV file');
    }

    $rows = [];
    $header = null;

    while (($data = fgetcsv($fh, 0, ';', '"')) !== false) {
        if ($header === null) {
            $header = array_map('trim', $data);
            continue;
        }
        if (count($data) < 7) {
            continue;
        }
        $row = array_combine($header, $data);
        if (!$row) continue;

        $rawDate = trim((string)($row['"Datum"'] ?? $row['Datum'] ?? ''));
        $rawDate = trim($rawDate, "\" ");
        if ($rawDate === '') continue;
        // ING uses YYYYMMDD
        $date = DateTime::createFromFormat('Ymd', $rawDate);
        if (!$date) continue;

        $amountStr = (string)($row['Bedrag (EUR)'] ?? '0');
        $amountStr = trim($amountStr, "\" ");
        $amountStr = str_replace('.', '', $amountStr); // just in case thousands separators
        $amountStr = str_replace(',', '.', $amountStr);
        $amount = (float)$amountStr;

        $direction = trim((string)($row['Af Bij'] ?? ''));
        $direction = trim($direction, "\" ");
        $amountSigned = ($direction === 'Af') ? -1 * $amount : $amount;

        $rows[] = [
            'tx_date' => $date->format('Y-m-d'),
            'name_description' => trim((string)($row['Naam / Omschrijving'] ?? '')),
            'account_iban' => trim((string)($row['Rekening'] ?? '')),
            'counterparty_iban' => trim((string)($row['Tegenrekening'] ?? '')),
            'code' => trim((string)($row['Code'] ?? '')),
            'direction' => $direction,
            'amount' => round($amount, 2),
            'amount_signed' => round($amountSigned, 2),
            'mutation_type' => trim((string)($row['Mutatiesoort'] ?? '')),
            'messages' => (string)($row['Mededelingen'] ?? ''),
            'balance_after' => parse_eur_decimal((string)($row['Saldo na mutatie'] ?? '')),
            'tag' => trim((string)($row['Tag'] ?? '')),
        ];
    }

    fclose($fh);
    return $rows;
}

function parse_eur_decimal(string $value): ?float {
    $value = trim($value, "\" ");
    if ($value === '') return null;
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) return null;
    return round((float)$value, 2);
}

function tx_hash(array $tx): string {
    $parts = [
        $tx['tx_date'] ?? '',
        (string)($tx['amount_signed'] ?? ''),
        $tx['name_description'] ?? '',
        $tx['account_iban'] ?? '',
        $tx['counterparty_iban'] ?? '',
        $tx['code'] ?? '',
        $tx['mutation_type'] ?? '',
        $tx['messages'] ?? '',
    ];
    return hash('sha256', implode('|', $parts));
}
