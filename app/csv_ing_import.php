<?php
declare(strict_types=1);

/**
 * Import ING NL CSV export.
 * - semicolon separated
 * - quoted
 * - header row with Dutch names
 */

function ing_parse_decimal(string $s): ?float {
    $s = trim($s);
    if ($s === '') return null;
    // ING uses comma as decimal separator.
    $s = str_replace(['.', ' '], ['', ''], $s);
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return (float)$s;
}

function ing_parse_date(string $ymd): ?string {
    $ymd = trim($ymd, " \t\n\r\0\x0B\"");
    if ($ymd === '') return null;
    $dt = DateTime::createFromFormat('Ymd', $ymd);
    if (!$dt) return null;
    return $dt->format('Y-m-d');
}

function ing_txn_hash(array $row): string {
    $parts = [
        $row['txn_date'] ?? '',
        $row['description'] ?? '',
        $row['account_iban'] ?? '',
        $row['counter_iban'] ?? '',
        $row['code'] ?? '',
        $row['direction'] ?? '',
        (string)($row['amount_signed'] ?? ''),
        $row['mutation_type'] ?? '',
        $row['notes'] ?? '',
        (string)($row['balance_after'] ?? ''),
        $row['tag'] ?? '',
    ];
    return sha1(implode('|', $parts));
}

function ing_normalize_iban(?string $iban): string {
    if ($iban === null) return '';
    return strtoupper(trim($iban));
}

function ing_normalize_text(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return $value;
}

function ing_rule_text_match(string $value, string $pattern, ?string $mode): bool {
    $pattern = trim($pattern);
    if ($pattern === '') return true;

    $value = ing_normalize_text($value);
    $pattern = ing_normalize_text($pattern);
    $mode = $mode ?: 'contains';

    return match ($mode) {
        'starts' => str_starts_with($value, $pattern),
        'equals' => $value === $pattern,
        default => str_contains($value, $pattern),
    };
}

function ing_rule_matches_transaction(array $rule, array $txn): bool {
    if (empty($rule['active'])) return false;

    $desc = (string)($txn['description'] ?? '');
    $notes = (string)($txn['notes'] ?? '');
    $amount = abs((float)($txn['amount_signed'] ?? 0));

    $fromText = (string)($rule['from_text'] ?? '');
    if ($fromText !== '' && !ing_rule_text_match($desc, $fromText, $rule['from_text_match'] ?? null)) {
        return false;
    }

    $medText = (string)($rule['mededelingen_text'] ?? '');
    if ($medText !== '' && !ing_rule_text_match($notes, $medText, $rule['mededelingen_match'] ?? null)) {
        return false;
    }

    $fromIban = ing_normalize_iban($rule['from_iban'] ?? null);
    if ($fromIban !== '' && $fromIban !== ing_normalize_iban($txn['counter_iban'] ?? null)) {
        return false;
    }

    $rekening = ing_normalize_iban($rule['rekening_equals'] ?? null);
    if ($rekening !== '' && $rekening !== ing_normalize_iban($txn['account_iban'] ?? null)) {
        return false;
    }

    if ($rule['amount_min'] !== null && $rule['amount_min'] !== '') {
        if ($amount < (float)$rule['amount_min']) return false;
    }

    if ($rule['amount_max'] !== null && $rule['amount_max'] !== '') {
        if ($amount > (float)$rule['amount_max']) return false;
    }

    return true;
}

function ing_apply_rules(array $txn, array $rules): array {
    foreach ($rules as $rule) {
        $targetCategoryId = (int)($rule['target_category_id'] ?? 0);
        if ($targetCategoryId <= 0) {
            continue;
        }
        if (ing_rule_matches_transaction($rule, $txn)) {
            return [
                'category_auto_id' => $targetCategoryId,
                'rule_auto_id' => (int)$rule['id'],
                'auto_reason' => 'Rule: ' . (string)$rule['name'],
            ];
        }
    }

    return [
        'category_auto_id' => null,
        'rule_auto_id' => null,
        'auto_reason' => null,
    ];
}

function ing_import_csv(PDO $db, int $userId, string $tmpFile, string $originalFilename): array {
    $handle = fopen($tmpFile, 'rb');
    if (!$handle) {
        throw new RuntimeException('Could not read uploaded file.');
    }

    // Read header
    $header = fgetcsv($handle, 0, ';', '"');
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('CSV seems empty or unreadable.');
    }

    // Normalize header names
    $header = array_map(fn($h) => trim($h, " \t\n\r\0\x0B\""), $header);

    $map = [
        'Datum' => 'Datum',
        'Naam / Omschrijving' => 'Naam / Omschrijving',
        'Rekening' => 'Rekening',
        'Tegenrekening' => 'Tegenrekening',
        'Code' => 'Code',
        'Af Bij' => 'Af Bij',
        'Bedrag (EUR)' => 'Bedrag (EUR)',
        'Mutatiesoort' => 'Mutatiesoort',
        'Mededelingen' => 'Mededelingen',
        'Saldo na mutatie' => 'Saldo na mutatie',
        'Tag' => 'Tag',
    ];

    $idx = [];
    foreach ($header as $i => $col) {
        if (isset($map[$col])) {
            $idx[$map[$col]] = $i;
        }
    }

    // Minimal columns we need
    foreach (['Datum', 'Naam / Omschrijving', 'Af Bij', 'Bedrag (EUR)'] as $need) {
        if (!isset($idx[$need])) {
            fclose($handle);
            throw new RuntimeException('CSV missing required column: ' . $need);
        }
    }

    // Create import record
    $stmtImp = $db->prepare('INSERT INTO imports(user_id, filename) VALUES(:uid, :fn)');
    $stmtImp->execute([':uid' => $userId, ':fn' => $originalFilename]);
    $importId = (int)$db->lastInsertId();

    $inserted = 0;
    $skipped = 0;

    $stmtIns = $db->prepare(
        'INSERT INTO transactions(
            user_id, import_id, import_batch_id, txn_hash,
            txn_date, description,
            account_iban, counter_iban, code,
            direction, amount_signed, currency,
            flow_type,
            mutation_type, notes, balance_after, tag
            , category_id, category_auto_id, rule_auto_id, auto_reason
        ) VALUES(
            :uid, :import_id, :import_batch_id, :txn_hash,
            :txn_date, :description,
            :account_iban, :counter_iban, :code,
            :direction, :amount_signed, :currency,
            :flow_type,
            :mutation_type, :notes, :balance_after, :tag
            , :category_id, :category_auto_id, :rule_auto_id, :auto_reason
        )'
    );

    while (($row = fgetcsv($handle, 0, ';', '"')) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }

        $txnDate = ing_parse_date((string)($row[$idx['Datum']] ?? ''));
        $desc = trim((string)($row[$idx['Naam / Omschrijving']] ?? ''));
        $dir = trim((string)($row[$idx['Af Bij']] ?? ''));
        $amt = ing_parse_decimal((string)($row[$idx['Bedrag (EUR)']] ?? ''));

        if (!$txnDate || $desc === '' || ($dir !== 'Af' && $dir !== 'Bij') || $amt === null) {
            $skipped++;
            continue;
        }

        $signed = ($dir === 'Af') ? -abs($amt) : abs($amt);

        $flowType = $signed > 0 ? 'income' : 'expense';
        $rec = [
            'user_id' => $userId,
            'import_id' => $importId,
            'txn_date' => $txnDate,
            'description' => $desc,
            'account_iban' => isset($idx['Rekening']) ? trim((string)($row[$idx['Rekening']] ?? '')) : null,
            'counter_iban' => isset($idx['Tegenrekening']) ? trim((string)($row[$idx['Tegenrekening']] ?? '')) : null,
            'code' => isset($idx['Code']) ? trim((string)($row[$idx['Code']] ?? '')) : null,
            'direction' => $dir,
            'amount_signed' => $signed,
            'currency' => 'EUR',
            'flow_type' => $flowType,
            'mutation_type' => isset($idx['Mutatiesoort']) ? trim((string)($row[$idx['Mutatiesoort']] ?? '')) : null,
            'notes' => isset($idx['Mededelingen']) ? trim((string)($row[$idx['Mededelingen']] ?? '')) : null,
            'balance_after' => isset($idx['Saldo na mutatie']) ? ing_parse_decimal((string)($row[$idx['Saldo na mutatie']] ?? '')) : null,
            'tag' => isset($idx['Tag']) ? trim((string)($row[$idx['Tag']] ?? '')) : null,
        ];

        $rec['category_auto_id'] = null;
        $rec['category_id'] = null;
        $rec['rule_auto_id'] = null;
        $rec['auto_reason'] = null;

        $rec['txn_hash'] = ing_txn_hash([
            'txn_date' => $rec['txn_date'],
            'description' => $rec['description'],
            'account_iban' => $rec['account_iban'],
            'counter_iban' => $rec['counter_iban'],
            'code' => $rec['code'],
            'direction' => $rec['direction'],
            'amount_signed' => $rec['amount_signed'],
            'mutation_type' => $rec['mutation_type'],
            'notes' => $rec['notes'],
            'balance_after' => $rec['balance_after'],
            'tag' => $rec['tag'],
        ]);

        try {
            $stmtIns->execute([
                ':uid' => $userId,
                ':import_id' => $importId,
                ':import_batch_id' => $importId,
                ':txn_hash' => $rec['txn_hash'],
                ':txn_date' => $rec['txn_date'],
                ':description' => $rec['description'],
                ':account_iban' => $rec['account_iban'] ?: null,
                ':counter_iban' => $rec['counter_iban'] ?: null,
                ':code' => $rec['code'] ?: null,
                ':direction' => $rec['direction'],
                ':amount_signed' => $rec['amount_signed'],
                ':currency' => $rec['currency'],
                ':flow_type' => $rec['flow_type'],
                ':mutation_type' => $rec['mutation_type'] ?: null,
                ':notes' => $rec['notes'] ?: null,
                ':balance_after' => $rec['balance_after'],
                ':tag' => $rec['tag'] ?: null,
                ':category_id' => $rec['category_id'],
                ':category_auto_id' => $rec['category_auto_id'],
                ':rule_auto_id' => $rec['rule_auto_id'],
                ':auto_reason' => $rec['auto_reason'],
            ]);
            $inserted++;
        } catch (PDOException $e) {
            // Most likely duplicate hash
            $skipped++;
        }
    }

    fclose($handle);

    repo_apply_rules_to_import_batch($db, $userId, $importId);

    return [
        'import_id' => $importId,
        'inserted' => $inserted,
        'skipped' => $skipped,
    ];
}
