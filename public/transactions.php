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

    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Description</th>
          <th>Amount</th>
          <th>Category</th>
          <th>Type</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($txns)): ?>
          <tr><td colspan="6" class="small">No transactions found for this month.</td></tr>
        <?php endif; ?>

        <?php foreach ($txns as $t):
          $amt = (float)$t['amount_signed'];
          $amtCls = ($amt >= 0) ? 'money-pos' : 'money-neg';
        ?>
          <tr>
            <td><?= h($t['txn_date']) ?></td>
            <td>
              <div><strong><?= h($t['description']) ?></strong></div>
              <?php if (!empty($t['notes'])): ?>
                <div class="small"><?= h(mb_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td class="money <?= $amtCls ?>"><?= number_format($amt, 2, ',', '.') ?></td>
            <td>
              <select name="category_ids[<?= (int)$t['id'] ?>]" style="min-width: 200px;">
                <option value="" <?= empty($t['category_id']) ? 'selected' : '' ?>>Niet ingedeeld</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ((int)$t['category_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <span class="badge"><?= h((string)($t['mutation_type'] ?? '')) ?></span>
            </td>
            <td class="small">
              <?= h((string)($t['direction'] ?? '')) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <button class="btn floating-save" type="submit">Save all categories</button>
  </form>
</div>

<?php render_footer(); ?>
