<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

$latest = repo_get_latest_month($db, $userId);
$year = (int)($_GET['year'] ?? ($latest['y'] ?? (int)date('Y')));
$month = (int)($_GET['month'] ?? ($latest['m'] ?? (int)date('n')));
$q = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (string)($_GET['category_id'] ?? '');
$autoCategoryFilter = (string)($_GET['auto_category_id'] ?? '');
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$allTime = (string)($_GET['all_time'] ?? '') === '1';
$saved = isset($_GET['saved']);
$autoUpdated = (int)($_GET['auto_updated'] ?? 0);
$savingsAppliedCount = (int)($_GET['savings_applied'] ?? 0);
$hasDateRange = $startDate !== '' || $endDate !== '';
if ($allTime) {
    $year = 0;
    $month = 0;
    $hasDateRange = false;
    $startDate = '';
    $endDate = '';
}
$isYearView = !$hasDateRange && $month === 0;
$periodLabel = $allTime ? 'All time' : ($hasDateRange ? 'Date range' : ($isYearView ? 'Year' : 'Month'));
$periodValue = $allTime
    ? 'All transactions'
    : ($hasDateRange
        ? sprintf(
            '%s → %s',
            $startDate !== '' ? $startDate : 'Any',
            $endDate !== '' ? $endDate : 'Any'
        )
        : ($isYearView ? sprintf('%04d (all months)', $year) : sprintf('%04d-%02d', $year, $month)));
$canExportMonth = !$allTime && !$hasDateRange && !$isYearView;

$error = '';
$savingsFormAmounts = [];
$savingsTopupDate = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $action = (string)($_POST['action'] ?? '');
    $savedFlag = false;
    if ($action === 'update_categories') {
        $savedFlag = true;
        $categoryIds = $_POST['category_ids'] ?? [];
        if (is_array($categoryIds)) {
            foreach ($categoryIds as $txnIdRaw => $categoryIdRaw) {
                $txnId = (int)$txnIdRaw;
                $categoryIdRaw = (string)$categoryIdRaw;
                $categoryId = ($categoryIdRaw === '' ? null : (int)$categoryIdRaw);
                if ($txnId > 0) {
                    repo_update_transaction_category($db, $userId, $txnId, $categoryId);
                    $ledgerSavingsId = null;
                    if ($categoryId !== null) {
                        $category = repo_get_category($db, $categoryId);
                        $ledgerSavingsId = $category ? (int)($category['savings_id'] ?? 0) : null;
                    }
                    if ($ledgerSavingsId !== null && $ledgerSavingsId > 0) {
                        repo_set_transaction_ledger($db, $userId, $txnId, $ledgerSavingsId);
                    } else {
                        repo_set_transaction_ledger($db, $userId, $txnId, null);
                    }
                }
            }
        }
    }
    if ($action === 'update_friendly_name') {
        $savedFlag = true;
        $txnId = (int)($_POST['friendly_name_id'] ?? 0);
        $friendlyNames = $_POST['friendly_names'] ?? [];
        if ($txnId > 0 && is_array($friendlyNames)) {
            $friendlyName = (string)($friendlyNames[$txnId] ?? '');
            repo_update_transaction_friendly_name($db, $userId, $txnId, $friendlyName);
        }
    }
    if ($action === 'rerun_auto') {
        if ($isYearView || $hasDateRange) {
            $error = 'Auto categorie opnieuw toepassen kan alleen voor een enkele maand.';
        } else {
            $autoUpdated = repo_reapply_auto_categories($db, $userId, $year, $month);
        }
    }
    if ($action === 'update_paid_from_savings') {
        $savedFlag = true;
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        $savingsIdRaw = (string)($_POST['savings_id'] ?? '');
        if ($txnId > 0) {
            if ($savingsIdRaw === '' || $savingsIdRaw === '0') {
                repo_set_transaction_ledger($db, $userId, $txnId, null);
            } else {
                $savingsId = (int)$savingsIdRaw;
                if ($savingsId > 0) {
                    $ok = repo_set_transaction_ledger($db, $userId, $txnId, $savingsId);
                    if (!$ok) {
                        $error = 'Unable to update the ledger for this transaction.';
                    }
                }
            }
        }
    }
    if ($action === 'apply_savings_topups') {
        $savings = repo_list_savings($db);
        $amounts = $_POST['savings_amounts'] ?? [];
        $requestedDate = trim((string)($_POST['savings_date'] ?? ''));
        $applyOnlyId = (int)($_POST['savings_apply_id'] ?? 0);
        $savingsTopupDate = $requestedDate !== '' ? $requestedDate : $savingsTopupDate;

        if ($requestedDate === '') {
            $error = 'Please choose a date for the savings top-ups.';
        } else {
            $dateValue = DateTime::createFromFormat('Y-m-d', $requestedDate);
            if (!$dateValue || $dateValue->format('Y-m-d') !== $requestedDate) {
                $error = 'Please enter a valid date (YYYY-MM-DD).';
            }
        }

        if ($error === '' && !is_array($amounts)) {
            $error = 'Savings amounts could not be read.';
        }

        if ($error === '') {
            $topupCount = 0;
            foreach ($savings as $saving) {
                $savingsId = (int)$saving['id'];
                if ($applyOnlyId > 0 && $savingsId !== $applyOnlyId) {
                    continue;
                }
                $amountRaw = trim((string)($amounts[$savingsId] ?? ''));
                $savingsFormAmounts[$savingsId] = $amountRaw;
                if ($amountRaw === '') {
                    continue;
                }
                if (!is_numeric($amountRaw)) {
                    $error = 'Each savings amount must be numeric.';
                    break;
                }
                $amount = (float)$amountRaw;
                if ($amount <= 0) {
                    continue;
                }
                try {
                    repo_add_savings_topup($db, $userId, $savingsId, $requestedDate, $amount);
                    $topupCount++;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    break;
                }
            }
            if ($error === '' && $topupCount === 0) {
                $error = $applyOnlyId > 0
                    ? 'Enter a savings amount for the selected ledger.'
                    : 'Enter at least one savings amount to top up.';
            }
            if ($error === '') {
                $savingsAppliedCount = $topupCount;
            }
        }
    }
    if ($action === 'split_transaction') {
        $savedFlag = true;
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        $splitCount = (int)($_POST['split_count'] ?? 0);
        $amountsRaw = $_POST['split_amounts'] ?? [];
        $splitAmounts = [];

        if ($txnId <= 0) {
            $error = 'Please select a transaction to split.';
        } elseif ($splitCount < 2 || $splitCount > 3) {
            $error = 'Please choose to split into two or three transactions.';
        } elseif (!is_array($amountsRaw)) {
            $error = 'Split amounts could not be read.';
        } else {
            $splitAmounts = array_fill(0, $splitCount, null);
            $missingIndex = null;
            for ($i = 0; $i < $splitCount; $i++) {
                $amountRaw = trim((string)($amountsRaw[$i] ?? ''));
                if ($amountRaw === '') {
                    if ($missingIndex !== null) {
                        $error = 'Please enter each split amount.';
                        break;
                    }
                    $missingIndex = $i;
                    continue;
                }
                if (!is_numeric($amountRaw)) {
                    $error = 'Each split amount must be numeric.';
                    break;
                }
                $splitAmounts[$i] = (float)$amountRaw;
            }

            if ($error === '' && $missingIndex !== null) {
                $transaction = repo_get_transaction($db, $userId, $txnId);
                if (!$transaction) {
                    $error = 'Transaction not found.';
                } else {
                    $originalAbs = round(abs((float)$transaction['amount_signed']), 2);
                    $sum = 0.0;
                    foreach ($splitAmounts as $amountValue) {
                        if ($amountValue !== null) {
                            $sum += (float)$amountValue;
                        }
                    }
                    $remaining = round($originalAbs - $sum, 2);
                    if ($remaining <= 0) {
                        $error = 'Split amounts must add up to the original transaction total.';
                    } else {
                        $splitAmounts[$missingIndex] = $remaining;
                    }
                }
            }
        }

        if ($error === '') {
            try {
                repo_split_transaction($db, $userId, $txnId, array_values($splitAmounts));
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
    if ($action === 'restore_split') {
        $savedFlag = true;
        $txnId = (int)($_POST['transaction_id'] ?? 0);

        if ($txnId <= 0) {
            $error = 'Please select a transaction to restore.';
        }

        if ($error === '') {
            try {
                repo_restore_split_transaction($db, $userId, $txnId);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }

    // After POST, redirect to GET (PRG pattern) to avoid resubmission.
    if ($error === '') {
        $qsParams = [
            'year' => $year,
            'month' => $month,
            'q' => $q,
        ];
        if ($allTime) {
            $qsParams['all_time'] = 1;
        }
        if ($startDate !== '') {
            $qsParams['start_date'] = $startDate;
        }
        if ($endDate !== '') {
            $qsParams['end_date'] = $endDate;
        }
        if ($categoryFilter !== '') {
            $qsParams['category_id'] = $categoryFilter;
        }
        if ($autoCategoryFilter !== '') {
            $qsParams['auto_category_id'] = $autoCategoryFilter;
        }
        if ($savedFlag) {
            $qsParams['saved'] = 1;
        }
        if ($autoUpdated > 0) {
            $qsParams['auto_updated'] = $autoUpdated;
        }
        if ($savingsAppliedCount > 0) {
            $qsParams['savings_applied'] = $savingsAppliedCount;
        }
        $qs = http_build_query($qsParams);
        redirect('/transactions.php?' . $qs);
    }
}

$categories = repo_list_assignable_categories($db);
$uncategorizedColor = repo_get_setting($db, 'uncategorized_color');
$rangeStart = $startDate !== '' ? $startDate : null;
$rangeEnd = $endDate !== '' ? $endDate : null;
$showInternalSection = true;
$txns = repo_list_transactions(
    $db,
    $userId,
    $year,
    $month,
    $q,
    $categoryFilter,
    $autoCategoryFilter,
    false,
    $rangeStart,
    $rangeEnd
);
$internalTxns = repo_list_transactions(
    $db,
    $userId,
    $year,
    $month,
    $q,
    $categoryFilter,
    $autoCategoryFilter,
    true,
    $rangeStart,
    $rangeEnd
);
$internalTxns = array_values(array_filter(
    $internalTxns,
    static fn(array $txn): bool => !empty($txn['is_internal_transfer'])
));
$savings = repo_list_savings($db);
if (empty($savingsFormAmounts)) {
    foreach ($savings as $saving) {
        $savingsFormAmounts[(int)$saving['id']] = (string)($saving['monthly_amount'] ?? '0');
    }
}
$incomeTxns = [];
$expenseTxns = [];
$topoffTxns = [];

foreach ($txns as $txn) {
    $amt = (float)$txn['amount_signed'];
    if ($amt >= 0) {
        $incomeTxns[] = $txn;
    } elseif (!empty($txn['is_topup'])) {
        $topoffTxns[] = $txn;
    } else {
        $expenseTxns[] = $txn;
    }
}

function render_transactions_table(
    array $txns,
    array $categories,
    ?string $uncategorizedColor,
    string $emptyMessage
): void {
    ?>
    <table class="table txn-table">
      <thead>
        <tr>
          <th data-col="date" style="min-width: 110px; white-space: nowrap;">Date</th>
          <th data-col="description">Description</th>
          <th data-col="amount">Amount</th>
          <th data-col="auto-category">Auto Category</th>
          <th data-col="auto-rule">Auto Rule</th>
          <th data-col="category">Category</th>
          <th data-col="type">Type</th>
          <th data-col="direction">Direction</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($txns)): ?>
          <tr><td colspan="8" class="small"><?= h($emptyMessage) ?></td></tr>
        <?php endif; ?>

        <?php foreach ($txns as $t):
          $amt = (float)$t['amount_signed'];
          $amtCls = ($amt >= 0) ? 'money-pos' : 'money-neg';
          $isInternal = !empty($t['is_internal_transfer']);
          $hasSavingsLedger = !empty($t['savings_paid_id']);
          $isPaidFromSavings = $hasSavingsLedger && empty($t['is_topup']);
          $rowBaseColor = $t['category_color'] ?? $t['auto_category_color'] ?? null;
          $isUncategorized = $t['category_id'] === null && $t['category_auto_id'] === null;
          if ($rowBaseColor === null && $isUncategorized) {
              $rowBaseColor = $uncategorizedColor;
          }
          $rowColor = rgba_from_hex($rowBaseColor, 0.12);
          $rowStyle = $rowColor ? ' style="--row-color: ' . h($rowColor) . ';" data-row-color="1"' : '';
          $rowClasses = [];
          if ($isInternal) {
              $rowClasses[] = 'txn-internal';
          }
          if ($isPaidFromSavings) {
              $rowClasses[] = 'txn-paid-from-savings';
          }
          if ($isUncategorized) {
              $rowClasses[] = 'txn-uncategorized';
          }
          $rowClass = $rowClasses ? ' class="' . implode(' ', $rowClasses) . '"' : '';
        ?>
          <tr<?= $rowClass ?><?= $rowStyle ?>>
            <td data-col="date" style="min-width: 110px; white-space: nowrap;"><?= h($t['txn_date']) ?></td>
            <td data-col="description">
              <?php $hasFriendly = !empty($t['friendly_name']); ?>
              <div class="txn-description js-friendly-row" data-has-friendly="<?= $hasFriendly ? '1' : '0' ?>">
                <div class="txn-friendly-display js-friendly-display" <?= $hasFriendly ? '' : 'hidden' ?>>
                  <button type="button" class="txn-toggle js-friendly-toggle" data-target="original">
                    <strong><?= h((string)$t['friendly_name']) ?></strong>
                  </button>
                  <?php if ($isInternal): ?>
                    <span class="badge badge-transfer">Transfer</span>
                  <?php endif; ?>
                  <?php if ($hasSavingsLedger): ?>
                    <span class="badge badge-savings">Ledger<?= !empty($t['savings_paid_name']) ? ': ' . h((string)$t['savings_paid_name']) : '' ?></span>
                  <?php endif; ?>
                  <?php if (!empty($t['parent_transaction_id'])): ?>
                    <span class="badge">Split</span>
                  <?php endif; ?>
                  <div class="txn-friendly-actions">
                    <button type="button" class="txn-edit-link js-friendly-edit-toggle">Edit</button>
                    <?php if (!empty($t['parent_transaction_id'])): ?>
                      <?php $restoreFormId = 'restore-split-form-' . (int)$t['id']; ?>
                      <button class="txn-edit-link" type="submit" form="<?= h($restoreFormId) ?>">Restore split</button>
                    <?php else: ?>
                      <button type="button" class="txn-edit-link js-split-toggle" data-split-target="split-details-<?= (int)$t['id'] ?>">Split</button>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="txn-original-display js-original-display" <?= $hasFriendly ? 'hidden' : '' ?>>
                  <?php if ($hasFriendly): ?>
                    <button type="button" class="txn-toggle js-friendly-toggle" data-target="friendly">
                      <div class="txn-flags">
                        <strong><?= h($t['description']) ?></strong>
                        <?php if ($isInternal): ?>
                          <span class="badge badge-transfer">Transfer</span>
                        <?php endif; ?>
                        <?php if ($hasSavingsLedger): ?>
                          <span class="badge badge-savings">Ledger<?= !empty($t['savings_paid_name']) ? ': ' . h((string)$t['savings_paid_name']) : '' ?></span>
                        <?php endif; ?>
                        <?php if (!empty($t['parent_transaction_id'])): ?>
                          <span class="badge">Split</span>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($t['notes'])): ?>
                        <div class="small"><?= h(safe_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
                      <?php endif; ?>
                    </button>
                    <div class="txn-friendly-actions">
                      <button type="button" class="txn-edit-link js-friendly-edit-toggle">Edit</button>
                      <?php if (!empty($t['parent_transaction_id'])): ?>
                        <?php $restoreFormId = 'restore-split-form-' . (int)$t['id']; ?>
                        <button class="txn-edit-link" type="submit" form="<?= h($restoreFormId) ?>">Restore split</button>
                      <?php else: ?>
                        <button type="button" class="txn-edit-link js-split-toggle" data-split-target="split-details-<?= (int)$t['id'] ?>">Split</button>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="txn-flags">
                      <strong><?= h($t['description']) ?></strong>
                      <?php if ($isInternal): ?>
                        <span class="badge badge-transfer">Transfer</span>
                      <?php endif; ?>
                      <?php if ($hasSavingsLedger): ?>
                        <span class="badge badge-savings">Ledger<?= !empty($t['savings_paid_name']) ? ': ' . h((string)$t['savings_paid_name']) : '' ?></span>
                      <?php endif; ?>
                      <?php if (!empty($t['parent_transaction_id'])): ?>
                        <span class="badge">Split</span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($t['notes'])): ?>
                      <div class="small"><?= h(safe_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
                    <?php endif; ?>
                    <div class="txn-friendly-actions">
                      <button type="button" class="txn-edit-link js-friendly-edit-toggle">Edit</button>
                      <?php if (!empty($t['parent_transaction_id'])): ?>
                        <?php $restoreFormId = 'restore-split-form-' . (int)$t['id']; ?>
                        <button class="txn-edit-link" type="submit" form="<?= h($restoreFormId) ?>">Restore split</button>
                      <?php else: ?>
                        <button type="button" class="txn-edit-link js-split-toggle" data-split-target="split-details-<?= (int)$t['id'] ?>">Split</button>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="txn-friendly-editor js-friendly-editor" hidden>
                  <label class="small" style="margin-bottom: 6px;">Friendly name</label>
                  <input class="input js-friendly-input" name="friendly_names[<?= (int)$t['id'] ?>]" value="<?= h((string)($t['friendly_name'] ?? '')) ?>" placeholder="Friendly name">
                  <div class="row" style="margin-top: 8px; gap: 8px;">
                    <button class="btn js-friendly-save" type="submit" name="action" value="update_friendly_name" data-friendly-id="<?= (int)$t['id'] ?>">Save name</button>
                    <button class="btn js-friendly-cancel" type="button">Cancel</button>
                  </div>
                </div>
              </div>
            </td>
            <td data-col="amount" class="money <?= $amtCls ?>"><?= number_format($amt, 2, ',', '.') ?></td>
            <td data-col="auto-category">
              <?= h((string)($t['auto_category_name'] ?? '—')) ?>
            </td>
            <td data-col="auto-rule">
              <?= h((string)($t['auto_rule_name'] ?? '—')) ?>
            </td>
            <td data-col="category">
              <select name="category_ids[<?= (int)$t['id'] ?>]" style="min-width: 200px;">
                <option value="" <?= empty($t['category_id']) ? 'selected' : '' ?>>Niet ingedeeld</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ((int)$t['category_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td data-col="type">
              <span class="badge"><?= h((string)($t['mutation_type'] ?? '')) ?></span>
            </td>
            <td data-col="direction" class="small">
              <?= h((string)($t['direction'] ?? '')) ?>
            </td>
          </tr>
          <?php if (empty($t['parent_transaction_id'])): ?>
            <?php $splitFormId = 'split-form-' . (int)$t['id']; ?>
            <tr class="txn-split-row" data-split-row="split-details-<?= (int)$t['id'] ?>" hidden>
              <td colspan="8">
                <div
                  class="txn-split"
                  id="split-details-<?= (int)$t['id'] ?>"
                  data-split-total="<?= number_format(abs($amt), 2, '.', '') ?>"
                >
                  <div class="txn-split-header">
                    <span class="small muted">Split transaction</span>
                    <button type="button" class="txn-edit-link js-split-close" data-split-target="split-details-<?= (int)$t['id'] ?>">Close</button>
                  </div>
                  <div class="txn-split-fields" style="margin-top: 8px; display: grid; gap: 8px;">
                    <select class="input js-split-count" name="split_count" form="<?= h($splitFormId) ?>">
                      <option value="2">2 transactions</option>
                      <option value="3">3 transactions</option>
                    </select>
                    <div class="txn-split-amounts" style="display: grid; gap: 6px;">
                      <input
                        class="input js-split-amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        name="split_amounts[]"
                        form="<?= h($splitFormId) ?>"
                        data-split-index="1"
                        placeholder="Amount 1"
                        required
                      >
                      <input
                        class="input js-split-amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        name="split_amounts[]"
                        form="<?= h($splitFormId) ?>"
                        data-split-index="2"
                        placeholder="Amount 2"
                        required
                      >
                      <input
                        class="input js-split-amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        name="split_amounts[]"
                        form="<?= h($splitFormId) ?>"
                        data-split-index="3"
                        placeholder="Amount 3"
                        hidden
                      >
                    </div>
                    <button class="btn" type="submit" form="<?= h($splitFormId) ?>">Split</button>
                  </div>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

function render_split_forms(array $txns, array $actionQueryParams, array $config): void {
    $action = '/transactions.php?' . http_build_query($actionQueryParams);
    foreach ($txns as $txn) {
        $txnId = (int)($txn['id'] ?? 0);
        if ($txnId <= 0) {
            continue;
        }
        $formId = 'split-form-' . $txnId;
        ?>
        <form id="<?= h($formId) ?>" method="post" action="<?= h($action) ?>" style="display: none;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
          <input type="hidden" name="action" value="split_transaction">
          <input type="hidden" name="transaction_id" value="<?= $txnId ?>">
        </form>
        <?php if (!empty($txn['parent_transaction_id'])): ?>
          <?php $restoreFormId = 'restore-split-form-' . $txnId; ?>
          <form id="<?= h($restoreFormId) ?>" method="post" action="<?= h($action) ?>" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
            <input type="hidden" name="action" value="restore_split">
            <input type="hidden" name="transaction_id" value="<?= $txnId ?>">
          </form>
        <?php endif; ?>
        <?php
    }
}

$actionQueryParams = [
    'year' => $year,
    'month' => $month,
    'q' => $q,
    'category_id' => $categoryFilter,
    'auto_category_id' => $autoCategoryFilter,
];
if ($allTime) {
    $actionQueryParams['all_time'] = 1;
}
if ($startDate !== '') {
    $actionQueryParams['start_date'] = $startDate;
}
if ($endDate !== '') {
    $actionQueryParams['end_date'] = $endDate;
}

$yearInputValue = $year > 0 ? (string)$year : '';
$disableYearMonth = $hasDateRange || $allTime;
$disableDateRange = $allTime;

render_header('Transactions', 'transactions');
?>

<div class="card">
  <h1>Transactions</h1>
  <p class="small">
    <?= h($periodLabel) ?>: <strong><?= h($periodValue) ?></strong>
    <?php if (!$isYearView): ?>
      &nbsp;|&nbsp;
      <a href="/summary.php?year=<?= $year ?>&month=<?= $month ?>">View summary</a>
    <?php endif; ?>
    <?php if ($canExportMonth): ?>
      &nbsp;|&nbsp;
      <a href="/month.php?year=<?= $year ?>&month=<?= $month ?>&export=csv">Export CSV</a>
    <?php endif; ?>
  </p>

  <form method="get" action="/transactions.php" class="row" style="align-items: flex-end;">
    <div style="min-width: 160px;">
      <label>Year</label>
      <input class="input" type="number" name="year" value="<?= h($yearInputValue) ?>" min="2000" max="2100" <?= $disableYearMonth ? 'disabled' : '' ?>>
    </div>
    <div style="min-width: 180px;">
      <label>Month</label>
      <select class="input" name="month" <?= $disableYearMonth ? 'disabled' : '' ?>>
        <option value="0" <?= $isYearView ? 'selected' : '' ?>>All year</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= h(date('F', mktime(0, 0, 0, $m, 1))) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div style="min-width: 180px;">
      <label>Start date</label>
      <input class="input" type="date" name="start_date" value="<?= h($startDate) ?>" <?= $disableDateRange ? 'disabled' : '' ?>>
    </div>
    <div style="min-width: 180px;">
      <label>End date</label>
      <input class="input" type="date" name="end_date" value="<?= h($endDate) ?>" <?= $disableDateRange ? 'disabled' : '' ?>>
    </div>
    <div style="min-width: 140px;">
      <label>&nbsp;</label>
      <label style="display: flex; gap: 8px; align-items: center; color: var(--text); font-size: 14px;">
        <input type="checkbox" name="all_time" value="1" <?= $allTime ? 'checked' : '' ?>>
        All time
      </label>
    </div>
    <div style="flex: 1; min-width: 220px;">
      <label>Search (description/notes)</label>
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="e.g. Albert Heijn">
    </div>
    <div style="min-width: 220px;">
      <label>Auto Category</label>
      <select class="input" name="auto_category_id">
        <option value="" <?= $autoCategoryFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="0" <?= $autoCategoryFilter === '0' ? 'selected' : '' ?>>Not set</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $autoCategoryFilter === (string)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="min-width: 220px;">
      <label>Category</label>
      <select class="input" name="category_id">
        <option value="" <?= $categoryFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="0" <?= $categoryFilter === '0' ? 'selected' : '' ?>>Not set</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $categoryFilter === (string)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button class="btn" type="submit">Apply</button>
    </div>
  </form>

  <form method="post" action="/transactions.php?<?= h(http_build_query($actionQueryParams)) ?>" style="margin-top: 12px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="rerun_auto">
    <button class="btn" type="submit" <?= ($isYearView || $hasDateRange) ? 'disabled' : '' ?>>Auto categorie opnieuw toepassen</button>
  </form>

  <?php if ($saved): ?>
    <div class="small" style="margin-top: 10px; color: var(--accent);">Saved.</div>
  <?php endif; ?>
  <?php if ($autoUpdated > 0): ?>
    <div class="small" style="margin-top: 10px; color: var(--accent);">
      Auto categorie bijgewerkt voor <?= (int)$autoUpdated ?> transacties.
    </div>
  <?php endif; ?>
  <?php if ($savingsAppliedCount > 0): ?>
    <div class="small" style="margin-top: 10px; color: var(--accent);">
      Savings top-ups applied to <?= (int)$savingsAppliedCount ?> ledgers.
    </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="small" style="margin-top: 10px; color: var(--danger);"><?= h($error) ?></div>
  <?php endif; ?>

  </div>
  <details class="card">
    <summary><strong>Savings</strong></summary>
    <div style="margin-top: 12px;">
      <?php if (empty($savings)): ?>
        <div class="small muted">No savings ledgers available.</div>
      <?php else: ?>
        <form method="post" action="/transactions.php?<?= h(http_build_query($actionQueryParams)) ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
          <input type="hidden" name="action" value="apply_savings_topups">
          <table class="table" style="margin-top: 0;">
            <thead>
              <tr>
                <th>Ledger</th>
                <th style="width: 260px;">Default monthly amount</th>
                <th style="width: 160px;">Apply</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($savings as $saving): ?>
                <?php $savingId = (int)$saving['id']; ?>
                <tr>
                  <td><?= h((string)$saving['name']) ?></td>
                  <td>
                    <input
                      class="input"
                      type="number"
                      step="0.01"
                      name="savings_amounts[<?= $savingId ?>]"
                      value="<?= h($savingsFormAmounts[$savingId] ?? '0') ?>"
                    >
                  </td>
                  <td>
                    <button class="btn" type="submit" name="savings_apply_id" value="<?= $savingId ?>">
                      Accept this saving
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="row" style="align-items: flex-end; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
            <div style="min-width: 200px;">
              <label>Date</label>
              <input class="input" type="date" name="savings_date" value="<?= h($savingsTopupDate) ?>" required>
            </div>
            <div>
              <button class="btn" type="submit">Accept savings</button>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </details>

<div class="card">
  <form method="post" action="/transactions.php?<?= h(http_build_query($actionQueryParams)) ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="friendly_name_id" id="js-friendly-name-id" value="">

    <div class="row small" style="align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
      <span><strong>Visible columns:</strong></span>
      <label><input class="js-column-toggle" type="checkbox" data-column="date" checked> Date</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="description" checked> Description</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="amount" checked> Amount</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="auto-category" checked> Auto Category</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="auto-rule"> Auto Rule</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="category" checked> Category</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="type" checked> Type</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="direction" checked> Direction</label>
      <button class="btn" type="button" id="js-row-color-toggle">Row colours: On</button>
    </div>

    <h2>Income</h2>
    <?php render_transactions_table(
        $incomeTxns,
        $categories,
        $uncategorizedColor,
        'No income transactions found for this period.'
    ); ?>

    <h2>Expenses</h2>
    <?php render_transactions_table(
        $expenseTxns,
        $categories,
        $uncategorizedColor,
        'No expense transactions found for this period.'
    ); ?>

    <h2>Top offs</h2>
    <?php render_transactions_table(
        $topoffTxns,
        $categories,
        $uncategorizedColor,
        'No top off transactions found for this period.'
    ); ?>

    <?php if ($showInternalSection): ?>
      <h2>Internal transfers</h2>
      <?php render_transactions_table(
          $internalTxns,
          $categories,
          $uncategorizedColor,
          'No internal transfers found for this period.'
      ); ?>
    <?php endif; ?>

    <button class="btn floating-save" type="submit" name="action" value="update_categories">Save all categories</button>
  </form>
</div>

<?php render_split_forms(array_merge($incomeTxns, $expenseTxns, $topoffTxns, $internalTxns), $actionQueryParams, $config); ?>

<script>
  (function () {
    const storageKey = 'transactions.visibleColumns';
    const rowColorStorageKey = 'transactions.rowColorMode';
    const toggles = Array.from(document.querySelectorAll('.js-column-toggle'));
    const tables = Array.from(document.querySelectorAll('.txn-table'));
    const rowColorToggle = document.getElementById('js-row-color-toggle');

    if (!toggles.length || !tables.length) {
      return;
    }

    const rowColorModes = ['all', 'uncategorized', 'off'];
    const rowColorLabels = {
      all: 'Row colours: On',
      uncategorized: 'Row colours: Only niet ingedeeld',
      off: 'Row colours: Off',
    };
    const applyRowColorMode = (mode) => {
      document.body.classList.toggle('show-row-colors-all', mode === 'all');
      document.body.classList.toggle('show-row-colors-uncategorized', mode === 'uncategorized');
      if (rowColorToggle) {
        rowColorToggle.textContent = rowColorLabels[mode] || rowColorLabels.all;
      }
    };

    let rowColorMode = rowColorModes[0];
    const savedRowColors = window.localStorage.getItem(rowColorStorageKey);
    if (savedRowColors && rowColorModes.includes(savedRowColors)) {
      rowColorMode = savedRowColors;
    }
    applyRowColorMode(rowColorMode);

    const applyVisibility = (column, isVisible) => {
      tables.forEach((table) => {
        table.querySelectorAll(`[data-col="${column}"]`).forEach((cell) => {
          cell.style.display = isVisible ? '' : 'none';
        });
      });
    };

    const saved = window.localStorage.getItem(storageKey);
    if (saved) {
      try {
        const visibleColumns = JSON.parse(saved);
        toggles.forEach((toggle) => {
          const column = toggle.dataset.column;
          if (typeof visibleColumns[column] === 'boolean') {
            toggle.checked = visibleColumns[column];
          }
        });
      } catch (error) {
        window.localStorage.removeItem(storageKey);
      }
    }

    const persist = () => {
      const state = {};
      toggles.forEach((toggle) => {
        state[toggle.dataset.column] = toggle.checked;
      });
      window.localStorage.setItem(storageKey, JSON.stringify(state));
    };

    if (rowColorToggle) {
      rowColorToggle.addEventListener('click', () => {
        const currentIndex = rowColorModes.indexOf(rowColorMode);
        rowColorMode = rowColorModes[(currentIndex + 1) % rowColorModes.length];
        applyRowColorMode(rowColorMode);
        window.localStorage.setItem(rowColorStorageKey, rowColorMode);
      });
    }

    toggles.forEach((toggle) => {
      applyVisibility(toggle.dataset.column, toggle.checked);
      toggle.addEventListener('change', () => {
        applyVisibility(toggle.dataset.column, toggle.checked);
        persist();
      });
    });

    const friendlyRows = Array.from(document.querySelectorAll('.js-friendly-row'));
    const friendlyIdInput = document.getElementById('js-friendly-name-id');

    friendlyRows.forEach((row) => {
      const hasFriendly = row.dataset.hasFriendly === '1';
      const friendlyDisplay = row.querySelector('.js-friendly-display');
      const originalDisplay = row.querySelector('.js-original-display');
      const editToggles = Array.from(row.querySelectorAll('.js-friendly-edit-toggle'));
      const editor = row.querySelector('.js-friendly-editor');
      const cancelButton = row.querySelector('.js-friendly-cancel');
      const saveButton = row.querySelector('.js-friendly-save');
      const input = row.querySelector('.js-friendly-input');

      const showDisplay = (view) => {
        if (friendlyDisplay) {
          friendlyDisplay.hidden = view !== 'friendly';
        }
        if (originalDisplay) {
          originalDisplay.hidden = view !== 'original';
        }
      };

      const toggleDisplay = () => {
        if (!hasFriendly) {
          return;
        }
        const showingFriendly = friendlyDisplay ? !friendlyDisplay.hidden : false;
        showDisplay(showingFriendly ? 'original' : 'friendly');
      };

      showDisplay(hasFriendly ? 'friendly' : 'original');

      row.querySelectorAll('.js-friendly-toggle').forEach((button) => {
        button.addEventListener('click', () => {
          const targetView = button.dataset.target === 'original' ? 'original' : 'friendly';
          showDisplay(targetView);
        });
      });

      row.addEventListener('click', (event) => {
        if (editor && !editor.hidden) {
          return;
        }
        const interactive = event.target.closest(
          '.js-friendly-toggle, .js-friendly-edit-toggle, .js-friendly-editor, button, input, select, textarea, a, label'
        );
        if (interactive) {
          return;
        }
        toggleDisplay();
      });

      editToggles.forEach((editToggle) => {
        editToggle.addEventListener('click', () => {
          if (!editor) {
            return;
          }
          editor.hidden = false;
          if (friendlyDisplay) {
            friendlyDisplay.hidden = true;
          }
          if (originalDisplay) {
            originalDisplay.hidden = true;
          }
          editToggles.forEach((toggle) => {
            toggle.hidden = true;
          });
          if (input) {
            input.focus();
          }
        });
      });

      if (cancelButton && editor) {
        cancelButton.addEventListener('click', () => {
          editor.hidden = true;
          editToggles.forEach((toggle) => {
            toggle.hidden = false;
          });
          showDisplay(hasFriendly ? 'friendly' : 'original');
        });
      }

      if (saveButton && friendlyIdInput) {
        saveButton.addEventListener('click', () => {
          const txnId = saveButton.dataset.friendlyId;
          if (txnId) {
            friendlyIdInput.value = txnId;
          }
        });
      }
    });

    const splitToggles = Array.from(document.querySelectorAll('.js-split-toggle'));
    const splitClosers = Array.from(document.querySelectorAll('.js-split-close'));
    const toggleSplitRow = (targetId, shouldOpen) => {
      if (!targetId) {
        return;
      }
      const details = document.getElementById(targetId);
      if (!details) {
        return;
      }
      const row = details.closest('tr');
      if (!row) {
        return;
      }
      row.hidden = !shouldOpen;
      if (!row.hidden) {
        details.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    };
    splitToggles.forEach((toggle) => {
      toggle.addEventListener('click', () => {
        toggleSplitRow(toggle.dataset.splitTarget, true);
      });
    });
    splitClosers.forEach((close) => {
      close.addEventListener('click', () => {
        toggleSplitRow(close.dataset.splitTarget, false);
      });
    });

    const roundToCents = (value) => Math.round(value * 100) / 100;
    const splitCountSelectors = Array.from(document.querySelectorAll('.js-split-count'));
    splitCountSelectors.forEach((select) => {
      const updateSplitFields = () => {
        const splitContainer = select.closest('.txn-split');
        if (!splitContainer) {
          return;
        }
        const splitCount = Number(select.value || '2');
        splitContainer.querySelectorAll('.js-split-amount').forEach((input) => {
          const index = Number(input.dataset.splitIndex || '0');
          const isActive = index <= splitCount;
          input.hidden = !isActive;
          input.required = isActive;
          if (!isActive) {
            input.value = '';
          }
        });
      };

      const autoFillRemaining = () => {
        const splitContainer = select.closest('.txn-split');
        if (!splitContainer) {
          return;
        }
        const totalRaw = splitContainer.dataset.splitTotal || '';
        const total = Number(totalRaw);
        if (!total || Number.isNaN(total)) {
          return;
        }
        const inputs = Array.from(splitContainer.querySelectorAll('.js-split-amount')).filter((input) => !input.hidden);
        const values = [];
        for (const input of inputs) {
          const raw = input.value.trim();
          if (raw === '') {
            values.push(null);
            continue;
          }
          const parsed = Number(raw);
          if (Number.isNaN(parsed)) {
            return;
          }
          values.push(parsed);
        }
        const missingIndices = values
          .map((value, index) => (value === null ? index : -1))
          .filter((index) => index >= 0);
        if (missingIndices.length !== 1) {
          return;
        }
        const sum = values.reduce((acc, value) => (value === null ? acc : acc + value), 0);
        const remaining = roundToCents(total - sum);
        if (remaining <= 0) {
          return;
        }
        const targetInput = inputs[missingIndices[0]];
        if (targetInput && targetInput.value.trim() === '') {
          targetInput.value = remaining.toFixed(2);
        }
      };

      updateSplitFields();
      select.addEventListener('change', updateSplitFields);
      const splitInputs = Array.from(select.closest('.txn-split')?.querySelectorAll('.js-split-amount') ?? []);
      splitInputs.forEach((input) => {
        input.addEventListener('change', autoFillRemaining);
        input.addEventListener('blur', autoFillRemaining);
      });
    });
  })();
</script>

<?php render_footer(); ?>
