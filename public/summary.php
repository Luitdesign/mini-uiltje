<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
$latest = repo_get_latest_month($db, $userId);
$year = (int)($_GET['year'] ?? ($latest['y'] ?? (int)date('Y')));
$month = (int)($_GET['month'] ?? ($latest['m'] ?? (int)date('n')));

$sum = repo_month_summary($db, $userId, $year, $month);
$breakdown = repo_month_breakdown_by_category($db, $userId, $year, $month);

render_header('Summary', 'summary');
?>

<div class="card">
  <h1>Summary</h1>
  <p class="small">
    Month: <strong><?= h(sprintf('%04d-%02d', $year, $month)) ?></strong>
    &nbsp;|&nbsp;
    <a href="/transactions.php?year=<?= $year ?>&month=<?= $month ?>">View transactions</a>
  </p>

  <form method="get" action="/summary.php" class="row" style="align-items:flex-end;">
    <div style="min-width:160px;">
      <label>Year</label>
      <input class="input" type="number" name="year" value="<?= $year ?>" min="2000" max="2100">
    </div>
    <div style="min-width:140px;">
      <label>Month</label>
      <input class="input" type="number" name="month" value="<?= $month ?>" min="1" max="12">
    </div>
    <div>
      <button class="btn" type="submit">Apply</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="grid-2">
    <div class="card">
      <div class="small">Income</div>
      <div class="money money-pos" style="font-size: 26px; font-weight: 700; margin-top: 6px;">
        <?= number_format((float)$sum['income'], 2, ',', '.') ?>
      </div>
    </div>
    <div class="card">
      <div class="small">Spending</div>
      <div class="money money-neg" style="font-size: 26px; font-weight: 700; margin-top: 6px;">
        <?= number_format((float)$sum['spending'], 2, ',', '.') ?>
      </div>
    </div>
    <div class="card" style="grid-column: 1 / -1;">
      <div class="small">Net</div>
      <div class="money" style="font-size: 26px; font-weight: 700; margin-top: 6px;">
        <?= number_format((float)$sum['net'], 2, ',', '.') ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <h2>By category</h2>
  <table class="table">
    <thead>
      <tr>
        <th>Category</th>
        <th>Income</th>
        <th>Spending</th>
        <th>Net</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($breakdown)): ?>
        <tr><td colspan="4" class="small">No data.</td></tr>
      <?php endif; ?>

      <?php foreach ($breakdown as $b): ?>
        <tr>
          <td><?= h((string)$b['category']) ?></td>
          <td class="money money-pos"><?= number_format((float)$b['income'], 2, ',', '.') ?></td>
          <td class="money money-neg"><?= number_format((float)$b['spending'], 2, ',', '.') ?></td>
          <td class="money"><?= number_format((float)$b['net'], 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
