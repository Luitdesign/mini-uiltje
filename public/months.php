<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = $_POST['action'] ?? '';
    if ($action === 'rerun_auto') {
        $year = (int)($_POST['year'] ?? 0);
        $month = (int)($_POST['month'] ?? 0);
        if ($year > 0 && $month > 0) {
            repo_reapply_auto_categories($db, $userId, $year, $month);
        }
        header('Location: /months.php');
        exit;
    }
}

$months = repo_list_months($db, $userId);

render_header('Months', 'months');
?>

<div class="card">
  <h1>Months</h1>
  <p class="small">Start here: pick a month to categorize and view the summary.</p>
</div>

<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>Month</th>
        <th>Transactions</th>
        <th>Income</th>
        <th>Spending</th>
        <th>Net</th>
        
        <th>Links</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($months)): ?>
        <tr><td colspan="7" class="small">No transactions yet. Go to <a href="/upload.php">Upload</a>.</td></tr>
      <?php endif; ?>

      <?php foreach ($months as $m):
        $y = (int)$m['y'];
        $mo = (int)$m['m'];
        $label = sprintf('%04d-%02d', $y, $mo);
      ?>
        <tr>
          <td><span class="badge"><?= h($label) ?></span></td>
          <td><?= (int)$m['cnt'] ?>&nbsp;/<a style="color:var(--danger); " href="/transactions.php?year=<?= $y ?>&month=<?= $mo ?>&category_id=0">
              <?= (int)$m['uncategorized'] ?>
            </a></td>
          <td class="money money-pos"><?= number_format((float)$m['income'], 2, ',', '.') ?></td>
          <td class="money money-neg"><?= number_format((float)$m['spending'], 2, ',', '.') ?></td>
          <td class="money"><?= number_format((float)$m['net'], 2, ',', '.') ?></td>
          
          <td>
            <a href="/transactions.php?year=<?= $y ?>&month=<?= $mo ?>">Transactions</a>
            &nbsp;|&nbsp;
            <a href="/summary.php?year=<?= $y ?>&month=<?= $mo ?>">Summary</a>
            &nbsp;|&nbsp;
            <form method="post" action="/months.php" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
              <input type="hidden" name="action" value="rerun_auto">
              <input type="hidden" name="year" value="<?= $y ?>">
              <input type="hidden" name="month" value="<?= $mo ?>">
              <button class="btn" type="submit">Auto categorize</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
