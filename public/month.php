<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$m = (string)($_GET['m'] ?? '');
if ($m !== '' && preg_match('/^(?<year>\d{4})-(?<month>\d{2})$/', $m, $matches)) {
    $year = (int)$matches['year'];
    $month = (int)$matches['month'];
}

if ($year <= 0 || $month <= 0) {
    $latest = repo_get_latest_month($db, $userId);
    $year = $year > 0 ? $year : (int)($latest['y'] ?? date('Y'));
    $month = $month > 0 ? $month : (int)($latest['m'] ?? date('n'));
}

$export = strtolower((string)($_GET['export'] ?? ''));
if ($export === 'csv') {
    $txns = repo_list_transactions_for_month($db, $userId, $year, $month);
    $filename = sprintf('transactions-%04d-%02d.csv', $year, $month);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $handle = fopen('php://output', 'wb');
    $ingHeader = [
        'Datum',
        'Naam / Omschrijving',
        'Rekening',
        'Tegenrekening',
        'Code',
        'Af Bij',
        'Bedrag (EUR)',
        'Mutatiesoort',
        'Mededelingen',
        'Saldo na mutatie',
        'Tag',
    ];
    $tableColumns = [
        'id',
        'user_id',
        'import_id',
        'import_batch_id',
        'txn_hash',
        'txn_date',
        'description',
        'friendly_name',
        'account_iban',
        'counter_iban',
        'code',
        'direction',
        'amount_signed',
        'currency',
        'mutation_type',
        'notes',
        'balance_after',
        'tag',
        'is_internal_transfer',
        'category_id',
        'category_auto_id',
        'rule_auto_id',
        'auto_reason',
        'created_at',
    ];

    fputcsv($handle, array_merge($ingHeader, $tableColumns), ';', '"');

    foreach ($txns as $txn) {
        $txnDate = (string)($txn['txn_date'] ?? '');
        $dateForCsv = $txnDate !== '' ? date('Ymd', strtotime($txnDate)) : '';
        $direction = (string)($txn['direction'] ?? '');
        $amountSigned = (float)($txn['amount_signed'] ?? 0);
        if ($direction !== 'Af' && $direction !== 'Bij') {
            $direction = $amountSigned < 0 ? 'Af' : 'Bij';
        }
        $amountCsv = number_format(abs($amountSigned), 2, ',', '');
        $balanceAfter = $txn['balance_after'] ?? null;
        $balanceCsv = $balanceAfter === null ? '' : number_format((float)$balanceAfter, 2, ',', '');

        $row = [
            $dateForCsv,
            (string)($txn['description'] ?? ''),
            (string)($txn['account_iban'] ?? ''),
            (string)($txn['counter_iban'] ?? ''),
            (string)($txn['code'] ?? ''),
            $direction,
            $amountCsv,
            (string)($txn['mutation_type'] ?? ''),
            (string)($txn['notes'] ?? ''),
            $balanceCsv,
            (string)($txn['tag'] ?? ''),
        ];

        foreach ($tableColumns as $col) {
            $value = $txn[$col] ?? '';
            $row[] = $value === null ? '' : (string)$value;
        }

        fputcsv($handle, $row, ';', '"');
    }

    fclose($handle);
    exit;
}

redirect('/transactions.php?year=' . $year . '&month=' . $month);
