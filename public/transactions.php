<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

$latest = repo_get_latest_month($db, $userId);
$year = (int)($_GET['year'] ?? ($latest['y'] ?? (int)date('Y')));
$month = (int)($_GET['month'] ?? ($latest['m'] ?? (int)date('n')));
$q = trim((string)($_GET['q'] ?? ''));
$saved = isset($_GET['saved']);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update_categories') {
        $categoryIds = $_POST['category_ids'] ?? [];
        if (is_array($categoryIds)) {
            foreach ($categoryIds as $txnIdRaw => $categoryIdRaw) {
                $txnId = (int)$txnIdRaw;
                $categoryIdRaw = (string)$categoryIdRaw;
                $categoryId = ($categoryIdRaw === '' ? null : (int)$categoryIdRaw);
                if ($txnId > 0) {
                    repo_update_transaction_category($db, $userId, $txnId, $categoryId);
                }
            }
        }
    }

    // After POST, redirect to GET (PRG pattern) to avoid resubmission.
    $qs = http_build_query(['year'=>$year, 'month'=>$month, 'q'=>$q, 'saved'=>1]);
    redirect('/transactions.php?' . $qs);
}

$categories = repo_list_categories($db);
$txns = repo_list_transactions($db, $userId, $year, $month, $q);
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

render_header('Transactions', 'transactions');
?>

<div class="card">
  <h1>Transactions</h1>
  <p class="small">
    Month: <strong><?= h(sprintf('%04d-%02d', $year, $month)) ?></strong>
    &nbsp;|&nbsp;
    <a href="/summary.php?year=<?= $year ?>&month=<?= $month ?>">View summary</a>
  </p>

  <form method="get" action="/transactions.php" class="row" style="align-items: flex-end;">
    <div style="min-width: 160px;">
      <label>Year</label>
      <input class="input" type="number" name="year" value="<?= $year ?>" min="2000" max="2100">
    </div>
    <div style="min-width: 140px;">
      <label>Month</label>
      <input class="input" type="number" name="month" value="<?= $month ?>" min="1" max="12">
    </div>
    <div style="flex: 1; min-width: 220px;">
      <label>Search (description/notes)</label>
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="e.g. Albert Heijn">
    </div>
    <div>
      <button class="btn" type="submit">Apply</button>
    </div>
  </form>

  <?php if ($saved): ?>
    <div class="small" style="margin-top: 10px; color: var(--accent);">Saved.</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="small" style="margin-top: 10px; color: var(--danger);"><?= h($error) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <form method="post" action="/transactions.php?<?= h(http_build_query(['year'=>$year,'month'=>$month,'q'=>$q])) ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="update_categories">

    <div class="row small" style="align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
      <span><strong>Visible columns:</strong></span>
      <label><input class="js-column-toggle" type="checkbox" data-column="date" checked> Date</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="description" checked> Description</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="amount" checked> Amount</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="category" checked> Category</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="type" checked> Type</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="direction" checked> Direction</label>
    </div>

    <h2>Income</h2>
    <table class="table txn-table">
      <thead>
        <tr>
          <th data-col="date">Date</th>
          <th data-col="description">Description</th>
          <th data-col="amount">Amount</th>
          <th data-col="category">Category</th>
          <th data-col="type">Type</th>
          <th data-col="direction">Direction</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($incomeTxns)): ?>
          <tr><td colspan="6" class="small">No income transactions found for this month.</td></tr>
        <?php endif; ?>

        <?php foreach ($incomeTxns as $t):
          $amt = (float)$t['amount_signed'];
          $amtCls = ($amt >= 0) ? 'money-pos' : 'money-neg';
        ?>
          <tr>
            <td data-col="date"><?= h($t['txn_date']) ?></td>
            <td data-col="description">
              <div><strong><?= h($t['description']) ?></strong></div>
              <?php if (!empty($t['notes'])): ?>
                <div class="small"><?= h(mb_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td data-col="amount" class="money <?= $amtCls ?>"><?= number_format($amt, 2, ',', '.') ?></td>
            <td data-col="category">
              <select name="category_ids[<?= (int)$t['id'] ?>]" style="min-width: 200px;">
                <option value="" <?= empty($t['category_id']) ? 'selected' : '' ?>>Uncategorized</option>
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

    <h2>Expenses</h2>
    <table class="table txn-table">
      <thead>
        <tr>
          <th data-col="date">Date</th>
          <th data-col="description">Description</th>
          <th data-col="amount">Amount</th>
          <th data-col="category">Category</th>
          <th data-col="type">Type</th>
          <th data-col="direction">Direction</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($expenseTxns)): ?>
          <tr><td colspan="6" class="small">No expense transactions found for this month.</td></tr>
        <?php endif; ?>

        <?php foreach ($expenseTxns as $t):
          $amt = (float)$t['amount_signed'];
          $amtCls = ($amt >= 0) ? 'money-pos' : 'money-neg';
        ?>
          <tr>
            <td data-col="date"><?= h($t['txn_date']) ?></td>
            <td data-col="description">
              <div><strong><?= h($t['description']) ?></strong></div>
              <?php if (!empty($t['notes'])): ?>
                <div class="small"><?= h(mb_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td data-col="amount" class="money <?= $amtCls ?>"><?= number_format($amt, 2, ',', '.') ?></td>
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

    <button class="btn floating-save" type="submit">Save all categories</button>
  </form>
</div>

<script>
  (function () {
    const storageKey = 'transactions.visibleColumns';
    const toggles = Array.from(document.querySelectorAll('.js-column-toggle'));
    const tables = Array.from(document.querySelectorAll('.txn-table'));

    if (!toggles.length || !tables.length) {
      return;
    }

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

    toggles.forEach((toggle) => {
      applyVisibility(toggle.dataset.column, toggle.checked);
      toggle.addEventListener('change', () => {
        applyVisibility(toggle.dataset.column, toggle.checked);
        persist();
      });
    });
  })();
</script>

<?php render_footer(); ?>
