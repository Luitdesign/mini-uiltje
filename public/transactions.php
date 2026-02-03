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
                $error = 'Enter at least one savings amount to top up.';
            }
            if ($error === '') {
                $savingsAppliedCount = $topupCount;
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

foreach ($txns as $txn) {
    $amt = (float)$txn['amount_signed'];
    if ($amt >= 0) {
        $incomeTxns[] = $txn;
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
          if ($rowBaseColor === null && $t['category_id'] === null && $t['category_auto_id'] === null) {
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
                  <button type="button" class="txn-edit-link js-friendly-edit-toggle">Edit</button>
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
                      </div>
                      <?php if (!empty($t['notes'])): ?>
                        <div class="small"><?= h(safe_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
                      <?php endif; ?>
                    </button>
                    <button type="button" class="txn-edit-link js-friendly-edit-toggle">Edit</button>
                  <?php else: ?>
                    <div class="txn-flags">
                      <strong><?= h($t['description']) ?></strong>
                      <?php if ($isInternal): ?>
                        <span class="badge badge-transfer">Transfer</span>
                      <?php endif; ?>
                      <?php if ($hasSavingsLedger): ?>
                        <span class="badge badge-savings">Ledger<?= !empty($t['savings_paid_name']) ? ': ' . h((string)$t['savings_paid_name']) : '' ?></span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($t['notes'])): ?>
                      <div class="small"><?= h(safe_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
                    <?php endif; ?>
                    <button type="button" class="txn-edit-link js-friendly-edit-toggle">Edit</button>
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
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
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
 <div class="card">

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
                <th style="width: 200px;">Default monthly amount</th>
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
</div>

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

<script>
  (function () {
    const storageKey = 'transactions.visibleColumns';
    const rowColorStorageKey = 'transactions.showRowColors';
    const toggles = Array.from(document.querySelectorAll('.js-column-toggle'));
    const tables = Array.from(document.querySelectorAll('.txn-table'));
    const rowColorToggle = document.getElementById('js-row-color-toggle');

    if (!toggles.length || !tables.length) {
      return;
    }

    const applyRowColors = (enabled) => {
      document.body.classList.toggle('show-row-colors', enabled);
      if (rowColorToggle) {
        rowColorToggle.textContent = enabled ? 'Row colours: On' : 'Row colours: Off';
      }
    };

    let rowColorsEnabled = true;
    const savedRowColors = window.localStorage.getItem(rowColorStorageKey);
    if (savedRowColors !== null) {
      rowColorsEnabled = savedRowColors === '1';
    }
    applyRowColors(rowColorsEnabled);

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
        rowColorsEnabled = !rowColorsEnabled;
        applyRowColors(rowColorsEnabled);
        window.localStorage.setItem(rowColorStorageKey, rowColorsEnabled ? '1' : '0');
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
  })();
</script>

<?php render_footer(); ?>
