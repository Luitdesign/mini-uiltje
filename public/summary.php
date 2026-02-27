<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_data_user_id($db);
$latest = repo_get_latest_month($db, $userId);
$year = (int)($_GET['year'] ?? ($latest['y'] ?? (int)date('Y')));
$month = (int)($_GET['month'] ?? ($latest['m'] ?? (int)date('n')));
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$categoryView = (string)($_GET['category_view'] ?? 'category');
$groupByParentCategory = $categoryView === 'parent';
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
$breakdown = repo_period_breakdown_by_category(
    $db,
    $userId,
    $year,
    $month,
    $rangeStart,
    $rangeEnd,
    $groupByParentCategory
);

$chartCategory = trim((string)($_GET['chart_category'] ?? ''));
$monthlyCategoryNet = [];
if ($isYearView && $year > 0) {
    $chartCategoryOptions = array_values(array_map(
        static fn(array $row): string => (string)$row['category'],
        $breakdown
    ));
    if ($chartCategory === '' || !in_array($chartCategory, $chartCategoryOptions, true)) {
        $chartCategory = $chartCategoryOptions[0] ?? '';
    }
    if ($chartCategory !== '') {
        $monthlyCategoryNet = repo_year_monthly_totals_for_category(
            $db,
            $userId,
            $year,
            $chartCategory,
            $groupByParentCategory
        );
    }
}

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
$linkParams['category_view'] = $groupByParentCategory ? 'parent' : 'category';
if ($chartCategory !== '') {
    $linkParams['chart_category'] = $chartCategory;
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

  <form method="get" action="/summary.php" class="row" style="margin-top:8px; align-items:center;">
    <input type="hidden" name="year" value="<?= h((string)$year) ?>">
    <input type="hidden" name="month" value="<?= h((string)$month) ?>">
    <input type="hidden" name="start_date" value="<?= h($startDate) ?>">
    <input type="hidden" name="end_date" value="<?= h($endDate) ?>">
    <input type="hidden" name="all_time" value="<?= $allTime ? '1' : '0' ?>">
    <input type="hidden" name="chart_category" value="<?= h($chartCategory) ?>">
    <div style="display:flex; gap:12px; align-items:center;">
      <span class="small">Group by:</span>
      <label style="display:flex; gap:6px; align-items:center; color:var(--text); font-size:14px;">
        <input type="radio" name="category_view" value="category" <?= !$groupByParentCategory ? 'checked' : '' ?> onchange="this.form.submit()">
        Categories
      </label>
      <label style="display:flex; gap:6px; align-items:center; color:var(--text); font-size:14px;">
        <input type="radio" name="category_view" value="parent" <?= $groupByParentCategory ? 'checked' : '' ?> onchange="this.form.submit()">
        Parent categories
      </label>
    </div>
  </form>
</div>

<?php if ($isYearView && $year > 0): ?>
  <div class="card">
    <h2>Monthly bars by <?= $groupByParentCategory ? 'parent category' : 'category' ?></h2>

    <form method="get" action="/summary.php" class="row" style="align-items:flex-end; margin-bottom:12px;">
      <input type="hidden" name="year" value="<?= h((string)$year) ?>">
      <input type="hidden" name="month" value="0">
      <input type="hidden" name="start_date" value="<?= h($startDate) ?>">
      <input type="hidden" name="end_date" value="<?= h($endDate) ?>">
      <input type="hidden" name="all_time" value="<?= $allTime ? '1' : '0' ?>">
      <input type="hidden" name="category_view" value="<?= $groupByParentCategory ? 'parent' : 'category' ?>">
      <div style="min-width:280px;">
        <label>Category for chart</label>
        <select class="input" name="chart_category" onchange="this.form.submit()">
          <?php foreach (($chartCategoryOptions ?? []) as $option): ?>
            <option value="<?= h($option) ?>" <?= $chartCategory === $option ? 'selected' : '' ?>><?= h($option) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <?php
      $chartValues = [];
      for ($m = 1; $m <= 12; $m++) {
          $chartValues[$m] = (float)($monthlyCategoryNet[$m] ?? 0.0);
      }
      $chartMin = min(0.0, min($chartValues));
      $chartMax = max(0.0, max($chartValues));
      $chartRange = $chartMax - $chartMin;
      if ($chartRange <= 0) {
          $chartRange = 1.0;
      }
      $chartHeight = 260;
      $zeroY = 20 + (($chartMax / $chartRange) * ($chartHeight - 60));
      $barWidth = 28;
      $barGap = 14;
      $chartWidth = 60 + (12 * ($barWidth + $barGap));
    ?>

    <?php if ($chartCategory === ''): ?>
      <p class="small">No categories available for this year.</p>
    <?php else: ?>
      <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" width="100%" height="<?= $chartHeight ?>" role="img" aria-label="Monthly totals for <?= h($chartCategory) ?>" style="display:block; background: rgba(148,163,184,0.08); border-radius: 10px;">
        <line x1="40" y1="<?= $zeroY ?>" x2="<?= $chartWidth - 8 ?>" y2="<?= $zeroY ?>" stroke="rgba(148,163,184,0.7)" stroke-width="1" />
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <?php
            $value = $chartValues[$m];
            $x = 50 + (($m - 1) * ($barWidth + $barGap));
            $yValue = 20 + ((($chartMax - $value) / $chartRange) * ($chartHeight - 60));
            $y = min($yValue, $zeroY);
            $height = max(2, abs($zeroY - $yValue));
            $isPositive = $value >= 0;
          ?>
          <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $barWidth ?>" height="<?= $height ?>" rx="4" fill="<?= $isPositive ? 'var(--ok)' : 'var(--bad)' ?>">
            <title><?= h(date('F', mktime(0, 0, 0, $m, 1))) ?>: <?= number_format($value, 2, ',', '.') ?></title>
          </rect>
          <text x="<?= $x + ($barWidth / 2) ?>" y="<?= $chartHeight - 10 ?>" text-anchor="middle" font-size="10" fill="currentColor"><?= h(date('M', mktime(0, 0, 0, $m, 1))) ?></text>
        <?php endfor; ?>
      </svg>
      <p class="small" style="margin-top:8px;">Income months are shown above zero, expense months below zero.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

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
  <h2>By <?= $groupByParentCategory ? 'parent category' : 'category' ?></h2>
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
