<?php
require_once __DIR__ . '/../app/core/bootstrap.php';
require_once __DIR__ . '/../app/core/layout.php';
require_once __DIR__ . '/../app/core/repo.php';
require_login();

$months = list_months();
$m = $_GET['m'] ?? ($months[0] ?? date('Y-m'));

$res = month_results((string)$m);

render_header('Results');
?>
<div class="card">
  <div class="row" style="align-items:end">
    <div class="col">
      <h2>Results <?=h((string)$m)?></h2>
      <div class="small muted"><?=h((string)$res['count'])?> transactions</div>
    </div>
    <div class="col" style="max-width:260px">
      <form method="get">
        <label>Month</label>
        <select name="m" onchange="this.form.submit()">
          <?php foreach ($months as $mm): ?>
            <option value="<?=h($mm)?>" <?= $mm===$m ? 'selected' : '' ?>><?=h($mm)?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <div class="row" style="margin-top:14px">
    <div class="col">
      <div class="card">
        <h3>Totals</h3>
        <table class="table">
          <tbody>
            <tr><th>Income</th><td><?=h(number_format((float)$res['income'], 2, ',', '.'))?></td></tr>
            <tr><th>Spending</th><td><?=h(number_format((float)$res['expense'], 2, ',', '.'))?></td></tr>
            <tr><th>Transfers</th><td><?=h(number_format((float)$res['transfer'], 2, ',', '.'))?></td></tr>
            <tr><th>Net</th><td><strong><?=h(number_format((float)$res['net'], 2, ',', '.'))?></strong></td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="col">
      <div class="card">
        <h3>By category</h3>
        <table class="table">
          <thead><tr><th>Category</th><th>Amount</th></tr></thead>
          <tbody>
          <?php foreach ($res['by_category'] as $cat => $amt): ?>
            <tr>
              <td><?=h($cat)?></td>
              <td><?=h(number_format((float)$amt, 2, ',', '.'))?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div style="margin-top:12px">
    <a class="btn" href="/month.php?m=<?=h((string)$m)?>">Back to transactions</a>
  </div>
</div>
<?php render_footer();
