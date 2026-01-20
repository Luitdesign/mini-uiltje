<?php
require_once __DIR__ . '/../app/core/bootstrap.php';
require_once __DIR__ . '/../app/core/layout.php';
require_once __DIR__ . '/../app/core/repo.php';
require_login();

$months = list_months();

render_header('Dashboard');
?>
<div class="row">
  <div class="col">
    <div class="card">
      <h2>Months</h2>
      <?php if (!$months): ?>
        <p class="muted">No transactions yet. Upload a CSV to get started.</p>
        <a class="btn primary" href="/upload.php">Upload CSV</a>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Month</th><th>Total</th><th>Needs review</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($months as $m): $cnt = get_month_summary_counts($m); ?>
            <tr>
              <td><strong><?=h($m)?></strong></td>
              <td><?=h((string)$cnt['total'])?></td>
              <td><?=h((string)$cnt['needs_review'])?></td>
              <td>
                <a class="btn" href="/month.php?m=<?=h($m)?>">Transactions</a>
                <a class="btn" href="/results.php?m=<?=h($m)?>">Results</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col">
    <div class="card">
      <h2>Quick actions</h2>
      <p><a class="btn primary" href="/upload.php">Upload ING CSV</a></p>
      <p><a class="btn" href="/review.php">Go to review queue</a></p>
      <p class="muted small">Tip: new imports land in the review queue until confirmed.</p>
    </div>
  </div>
</div>
<?php render_footer();
