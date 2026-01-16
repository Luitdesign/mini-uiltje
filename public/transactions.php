<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

$latest = repo_get_latest_month($db, $userId);
$year = (int)($_GET['year'] ?? ($latest['y'] ?? (int)date('Y')));
$month = (int)($_GET['month'] ?? ($latest['m'] ?? (int)date('n')));
$q = trim((string)($_GET['q'] ?? ''));
$columnOptions = [
    'date' => 'Date',
    'description' => 'Description',
    'amount' => 'Amount',
    'category' => 'Category',
    'type' => 'Type',
    'direction' => 'Direction',
];
$selectedColumns = array_keys($columnOptions);
if (isset($_GET['cols'])) {
    $requested = $_GET['cols'];
    if (!is_array($requested)) {
        $requested = array_filter(array_map('trim', explode(',', (string)$requested)));
    }
    $requested = array_values(array_intersect((array)$requested, array_keys($columnOptions)));
    if (!empty($requested)) {
        $selectedColumns = $requested;
    }
}
$selectedColumnSet = array_flip($selectedColumns);
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
    $qs = http_build_query([
        'year'=>$year,
        'month'=>$month,
        'q'=>$q,
        'cols'=>$selectedColumns,
        'saved'=>1,
    ]);
    redirect('/transactions.php?' . $qs);
}

$categories = repo_list_categories($db);
$txns = repo_list_transactions($db, $userId, $year, $month, $q);

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
    <div style="min-width: 260px;">
      <label>Visible columns</label>
      <div class="small" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 10px;">
        <?php foreach ($columnOptions as $key => $label): ?>
          <label style="display: inline-flex; gap: 6px; align-items: center;">
            <input type="checkbox" name="cols[]" value="<?= h($key) ?>" <?= isset($selectedColumnSet[$key]) ? 'checked' : '' ?>>
            <span><?= h($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
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
  <form method="post" action="/transactions.php?<?= h(http_build_query(['year'=>$year,'month'=>$month,'q'=>$q,'cols'=>$selectedColumns])) ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="update_categories">

    <table class="table">
      <thead>
        <tr>
          <?php if (isset($selectedColumnSet['date'])): ?>
            <th>Date</th>
          <?php endif; ?>
          <?php if (isset($selectedColumnSet['description'])): ?>
            <th>Description</th>
          <?php endif; ?>
          <?php if (isset($selectedColumnSet['amount'])): ?>
            <th>Amount</th>
          <?php endif; ?>
          <?php if (isset($selectedColumnSet['category'])): ?>
            <th>Category</th>
          <?php endif; ?>
          <?php if (isset($selectedColumnSet['type'])): ?>
            <th>Type</th>
          <?php endif; ?>
          <?php if (isset($selectedColumnSet['direction'])): ?>
            <th>Direction</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($txns)): ?>
          <tr><td colspan="<?= count($selectedColumns) ?>" class="small">No transactions found for this month.</td></tr>
        <?php endif; ?>

        <?php foreach ($txns as $t):
          $amt = (float)$t['amount_signed'];
          $amtCls = ($amt >= 0) ? 'money-pos' : 'money-neg';
        ?>
          <tr>
            <?php if (isset($selectedColumnSet['date'])): ?>
              <td><?= h($t['txn_date']) ?></td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['description'])): ?>
              <td>
                <div><strong><?= h($t['description']) ?></strong></div>
                <?php if (!empty($t['notes'])): ?>
                  <div class="small"><?= h(mb_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['amount'])): ?>
              <td class="money <?= $amtCls ?>"><?= number_format($amt, 2, ',', '.') ?></td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['category'])): ?>
              <td>
                <select name="category_ids[<?= (int)$t['id'] ?>]" style="min-width: 200px;">
                  <option value="" <?= empty($t['category_id']) ? 'selected' : '' ?>>Uncategorized</option>
                  <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$t['category_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['type'])): ?>
              <td>
                <span class="badge"><?= h((string)($t['mutation_type'] ?? '')) ?></span>
              </td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['direction'])): ?>
              <td class="small">
                <?= h((string)($t['direction'] ?? '')) ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <button class="btn floating-save" type="submit">Save all categories</button>
  </form>
</div>

<?php render_footer(); ?>
