<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
$latest = repo_get_latest_month($db, $userId);
$year = (int)($_GET['year'] ?? ($latest['y'] ?? (int)date('Y')));
$month = (int)($_GET['month'] ?? ($latest['m'] ?? (int)date('n')));
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$allTime = (string)($_GET['all_time'] ?? '') === '1';
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

$rangeStart = $startDate !== '' ? $startDate : null;
$rangeEnd = $endDate !== '' ? $endDate : null;
$sum = repo_period_summary($db, $userId, $year, $month, $rangeStart, $rangeEnd);
$breakdown = repo_period_breakdown_by_category($db, $userId, $year, $month, $rangeStart, $rangeEnd);

render_header('Summary', 'summary');
$yearInputValue = $year > 0 ? (string)$year : '';
$disableYearMonth = $hasDateRange || $allTime;
$disableDateRange = $allTime;
$linkParams = [
    'year' => $year,
    'month' => $month,
];
if ($allTime) {
    $linkParams['all_time'] = 1;
}
if ($startDate !== '') {
    $linkParams['start_date'] = $startDate;
}
if ($endDate !== '') {
    $linkParams['end_date'] = $endDate;
}
?>

<div class="card">
  <h1>Summary</h1>
  <p class="small">
    <?= h($periodLabel) ?>: <strong><?= h($periodValue) ?></strong>
    &nbsp;|&nbsp;
    <a href="/transactions.php?<?= h(http_build_query($linkParams)) ?>">View transactions</a>
  </p>

  <form method="get" action="/summary.php" class="row" style="align-items:flex-end;">
    <div style="min-width:160px;">
      <label>Year</label>
      <input class="input" type="number" name="year" value="<?= h($yearInputValue) ?>" min="2000" max="2100" <?= $disableYearMonth ? 'disabled' : '' ?>>
    </div>
    <div style="min-width:180px;">
      <label>Month</label>
      <select class="input" name="month" <?= $disableYearMonth ? 'disabled' : '' ?>>
        <option value="0" <?= $isYearView ? 'selected' : '' ?>>All year</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= h(date('F', mktime(0, 0, 0, $m, 1))) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div style="min-width:180px;">
      <label>Start date</label>
      <input class="input" type="date" name="start_date" value="<?= h($startDate) ?>" <?= $disableDateRange ? 'disabled' : '' ?>>
    </div>
    <div style="min-width:180px;">
      <label>End date</label>
      <input class="input" type="date" name="end_date" value="<?= h($endDate) ?>" <?= $disableDateRange ? 'disabled' : '' ?>>
    </div>
    <div style="min-width:140px;">
      <label>&nbsp;</label>
      <label style="display:flex; gap:8px; align-items:center; color:var(--text); font-size:14px;">
        <input type="checkbox" name="all_time" value="1" <?= $allTime ? 'checked' : '' ?>>
        All time
      </label>
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
      </tr>
    </thead>
    <tbody>
      <?php if (empty($breakdown)): ?>
        <tr><td colspan="3" class="small">No data.</td></tr>
      <?php endif; ?>

      <?php foreach ($breakdown as $b): ?>
        <tr>
          <td><?= h((string)$b['category']) ?></td>
          <td class="money money-pos"><?= number_format((float)$b['income'], 2, ',', '.') ?></td>
          <td class="money money-neg"><?= number_format((float)$b['spending'], 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
