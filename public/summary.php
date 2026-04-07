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
$amountView = (string)($_GET['amount_view'] ?? 'default');
$useLedgerAmountsWithoutTopups = $amountView === 'ledger_no_topups';
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
$sum = repo_period_summary($db, $userId, $year, $month, $rangeStart, $rangeEnd, $useLedgerAmountsWithoutTopups);
$breakdown = repo_period_breakdown_by_category(
    $db,
    $userId,
    $year,
    $month,
    $rangeStart,
    $rangeEnd,
    $groupByParentCategory,
    $useLedgerAmountsWithoutTopups
);

$chartCategory = trim((string)($_GET['chart_category'] ?? ''));
$monthlyCategoryTotals = [];
$chartCategoryOptions = [];
$showMonthlyCategoryBars = !$hasDateRange ? ($month === 0) : true;
if ($showMonthlyCategoryBars) {
    $chartCategoryOptions = array_values(array_map(
        static fn(array $row): string => (string)$row['category'],
        $breakdown
    ));
    if ($chartCategory === '' || !in_array($chartCategory, $chartCategoryOptions, true)) {
        $chartCategory = $chartCategoryOptions[0] ?? '';
    }
    if ($chartCategory !== '') {
        if ($isYearView && $year > 0) {
            $monthlyCategoryTotals = repo_year_monthly_totals_for_category(
                $db,
                $userId,
                $year,
                $chartCategory,
                $groupByParentCategory,
                $useLedgerAmountsWithoutTopups
            );
        } else {
            $monthlyCategoryTotals = repo_period_monthly_totals_for_category(
                $db,
                $userId,
                $year,
                $month,
                $rangeStart,
                $rangeEnd,
                $chartCategory,
                $groupByParentCategory,
                $useLedgerAmountsWithoutTopups
            );
        }
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
$linkParams['amount_view'] = $useLedgerAmountsWithoutTopups ? 'ledger_no_topups' : 'default';
if ($chartCategory !== '') {
    $linkParams['chart_category'] = $chartCategory;
}
?>

<div class="card">
  <h1>Summary</h1>
  <p class="small">
    <?= h($periodLabel) ?>: <strong><?= h($periodValue) ?></strong>
    &nbsp;|&nbsp;
    Amount mode: <strong><?= $useLedgerAmountsWithoutTopups ? 'Without top-ups, include ledgers' : 'Current' ?></strong>
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
    <input type="hidden" name="amount_view" value="<?= $useLedgerAmountsWithoutTopups ? 'ledger_no_topups' : 'default' ?>">
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

  <form method="get" action="/summary.php" class="row" style="margin-top:8px; align-items:center;">
    <input type="hidden" name="year" value="<?= h((string)$year) ?>">
    <input type="hidden" name="month" value="<?= h((string)$month) ?>">
    <input type="hidden" name="start_date" value="<?= h($startDate) ?>">
    <input type="hidden" name="end_date" value="<?= h($endDate) ?>">
    <input type="hidden" name="all_time" value="<?= $allTime ? '1' : '0' ?>">
    <input type="hidden" name="chart_category" value="<?= h($chartCategory) ?>">
    <input type="hidden" name="category_view" value="<?= $groupByParentCategory ? 'parent' : 'category' ?>">
    <div style="display:flex; gap:12px; align-items:center;">
      <span class="small">Amounts:</span>
      <label style="display:flex; gap:6px; align-items:center; color:var(--text); font-size:14px;">
        <input type="radio" name="amount_view" value="default" <?= !$useLedgerAmountsWithoutTopups ? 'checked' : '' ?> onchange="this.form.submit()">
        Current
      </label>
      <label style="display:flex; gap:6px; align-items:center; color:var(--text); font-size:14px;">
        <input type="radio" name="amount_view" value="ledger_no_topups" <?= $useLedgerAmountsWithoutTopups ? 'checked' : '' ?> onchange="this.form.submit()">
        Exclude top-ups + include ledgers
      </label>
    </div>
  </form>
</div>

<?php if ($showMonthlyCategoryBars): ?>
  <div class="card">
    <h2>Monthly bars by <?= $groupByParentCategory ? 'parent category' : 'category' ?></h2>

    <form method="get" action="/summary.php" class="row" style="align-items:flex-end; margin-bottom:12px;">
      <input type="hidden" name="year" value="<?= h((string)$year) ?>">
      <input type="hidden" name="month" value="0">
      <input type="hidden" name="start_date" value="<?= h($startDate) ?>">
      <input type="hidden" name="end_date" value="<?= h($endDate) ?>">
      <input type="hidden" name="all_time" value="<?= $allTime ? '1' : '0' ?>">
      <input type="hidden" name="category_view" value="<?= $groupByParentCategory ? 'parent' : 'category' ?>">
      <input type="hidden" name="amount_view" value="<?= $useLedgerAmountsWithoutTopups ? 'ledger_no_topups' : 'default' ?>">
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
      $chartPoints = [];
      if ($isYearView && $year > 0) {
          for ($m = 1; $m <= 12; $m++) {
              $chartPoints[] = [
                  'label' => date('M', mktime(0, 0, 0, $m, 1)),
                  'full_label' => date('F', mktime(0, 0, 0, $m, 1)),
                  'income' => (float)($monthlyCategoryTotals[$m]['income'] ?? 0.0),
                  'spending' => (float)($monthlyCategoryTotals[$m]['spending'] ?? 0.0),
              ];
          }
      } else {
          foreach ($monthlyCategoryTotals as $row) {
              $rowYear = (int)($row['year_number'] ?? 0);
              $rowMonth = (int)($row['month_number'] ?? 0);
              if ($rowYear <= 0 || $rowMonth <= 0) {
                  continue;
              }
              $chartPoints[] = [
                  'label' => sprintf('%02d/%02d', $rowMonth, $rowYear % 100),
                  'full_label' => sprintf('%04d-%02d', $rowYear, $rowMonth),
                  'income' => (float)($row['income'] ?? 0.0),
                  'spending' => (float)($row['spending'] ?? 0.0),
              ];
          }
      }
      $incomeValues = array_map(static fn(array $point): float => (float)$point['income'], $chartPoints);
      $spendingValues = array_map(static fn(array $point): float => (float)$point['spending'], $chartPoints);
      $chartMax = max(1.0, !empty($incomeValues) ? max($incomeValues) : 0.0, !empty($spendingValues) ? max($spendingValues) : 0.0);
      $chartHeight = 280;
      $topPadding = 18;
      $bottomPadding = 30;
      $zeroY = (int)(($chartHeight - $bottomPadding + $topPadding) / 2);
      $barAreaHeight = min($zeroY - $topPadding, ($chartHeight - $bottomPadding) - $zeroY);
      $pointCount = max(1, count($chartPoints));
      $chartWidth = 1000;
      $chartLeftPadding = 44;
      $chartRightPadding = 8;
      $chartPlotWidth = max(120.0, $chartWidth - $chartLeftPadding - $chartRightPadding);
      $barSlotWidth = $chartPlotWidth / $pointCount;
      $barWidth = max(6.0, min(24.0, $barSlotWidth * 0.55));
      $barRx = min(3.0, $barWidth / 2);
      $yAxisTicks = [0.0, 0.25, 0.5, 0.75, 1.0];
    ?>

    <?php if ($chartCategory === ''): ?>
      <p class="small">No categories available for this year.</p>
    <?php else: ?>
      <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" width="100%" height="<?= $chartHeight ?>" role="img" aria-label="Monthly income and spending totals for <?= h($chartCategory) ?>" style="display:block; background: rgba(148,163,184,0.08); border-radius: 10px;">
        <line x1="<?= $chartLeftPadding ?>" y1="<?= $topPadding ?>" x2="<?= $chartLeftPadding ?>" y2="<?= $chartHeight - $bottomPadding ?>" stroke="rgba(148,163,184,0.7)" stroke-width="1" />
        <?php foreach ($yAxisTicks as $tick): ?>
          <?php
            $positiveY = $zeroY - ($tick * $barAreaHeight);
            $negativeY = $zeroY + ($tick * $barAreaHeight);
            $tickValue = $chartMax * $tick;
          ?>
          <line x1="<?= $chartLeftPadding ?>" y1="<?= $positiveY ?>" x2="<?= $chartWidth - $chartRightPadding ?>" y2="<?= $positiveY ?>" stroke="rgba(148,163,184,<?= $tick === 0.0 ? '0.7' : '0.25' ?>)" stroke-width="1" />
          <text x="<?= $chartLeftPadding - 4 ?>" y="<?= $positiveY + 3 ?>" text-anchor="end" font-size="10" fill="currentColor"><?= number_format($tickValue, 0, ',', '.') ?></text>
          <?php if ($tick > 0.0): ?>
            <line x1="<?= $chartLeftPadding ?>" y1="<?= $negativeY ?>" x2="<?= $chartWidth - $chartRightPadding ?>" y2="<?= $negativeY ?>" stroke="rgba(148,163,184,0.25)" stroke-width="1" />
            <text x="<?= $chartLeftPadding - 4 ?>" y="<?= $negativeY + 3 ?>" text-anchor="end" font-size="10" fill="currentColor">-<?= number_format($tickValue, 0, ',', '.') ?></text>
          <?php endif; ?>
        <?php endforeach; ?>
        <line x1="<?= $chartLeftPadding ?>" y1="<?= $zeroY ?>" x2="<?= $chartWidth - $chartRightPadding ?>" y2="<?= $zeroY ?>" stroke="rgba(148,163,184,0.7)" stroke-width="1" />
        <?php foreach ($chartPoints as $index => $point): ?>
          <?php
            $income = (float)$point['income'];
            $spending = (float)$point['spending'];
            $groupX = $chartLeftPadding + ($index * $barSlotWidth) + (($barSlotWidth - $barWidth) / 2);
            $incomeHeight = $income > 0 ? max(2, ($income / $chartMax) * $barAreaHeight) : 0;
            $spendingHeight = $spending > 0 ? max(2, ($spending / $chartMax) * $barAreaHeight) : 0;
            $incomeY = $zeroY - $incomeHeight;
            $spendingY = $zeroY;
            $labelX = $chartLeftPadding + ($index * $barSlotWidth) + ($barSlotWidth / 2);
          ?>
          <rect x="<?= $groupX ?>" y="<?= $incomeY ?>" width="<?= $barWidth ?>" height="<?= $incomeHeight ?>" rx="<?= $barRx ?>" fill="var(--accent)">
            <title><?= h((string)$point['full_label']) ?> income: <?= number_format($income, 2, ',', '.') ?></title>
          </rect>
          <rect x="<?= $groupX ?>" y="<?= $spendingY ?>" width="<?= $barWidth ?>" height="<?= $spendingHeight ?>" rx="<?= $barRx ?>" fill="var(--danger)">
            <title><?= h((string)$point['full_label']) ?> spending: <?= number_format($spending, 2, ',', '.') ?></title>
          </rect>
          <text x="<?= $labelX ?>" y="<?= $chartHeight - 10 ?>" text-anchor="middle" font-size="10" fill="currentColor"><?= h((string)$point['label']) ?></text>
        <?php endforeach; ?>
      </svg>
      <p class="small" style="margin-top:8px;">Green bar = income above the baseline, red bar = spending below the baseline for the same month.</p>

      <details style="margin-top:16px;">
        <summary style="cursor:pointer; user-select:none;"><?= h($chartCategory) ?> by month</summary>
        <table class="table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Month</th>
              <th>Income</th>
              <th>Spending</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($chartPoints as $point): ?>
              <tr>
                <td><?= h((string)$point['full_label']) ?></td>
                <td class="money money-pos"><?= number_format((float)$point['income'], 2, ',', '.') ?></td>
                <td class="money money-neg"><?= number_format((float)$point['spending'], 2, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
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
