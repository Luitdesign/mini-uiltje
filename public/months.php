<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
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
        <tr><td colspan="6" class="small">No transactions yet. Go to <a href="/upload.php">Upload</a>.</td></tr>
      <?php endif; ?>

      <?php foreach ($months as $m):
        $y = (int)$m['y'];
        $mo = (int)$m['m'];
        $label = sprintf('%04d-%02d', $y, $mo);
      ?>
        <tr>
          <td><span class="badge"><?= h($label) ?></span></td>
          <td><?= (int)$m['cnt'] ?></td>
          <td class="money money-pos"><?= number_format((float)$m['income'], 2, ',', '.') ?></td>
          <td class="money money-neg"><?= number_format((float)$m['spending'], 2, ',', '.') ?></td>
          <td class="money"><?= number_format((float)$m['net'], 2, ',', '.') ?></td>
          <td>
            <a href="/transactions.php?year=<?= $y ?>&month=<?= $mo ?>">Transactions</a>
            &nbsp;|&nbsp;
            <a href="/summary.php?year=<?= $y ?>&month=<?= $mo ?>">Summary</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
