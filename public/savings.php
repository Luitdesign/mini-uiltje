<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
$error = '';

if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    if ($saved === 'added') {
        $info = 'Saving created.';
    } else {
        $info = 'Changes saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? 'add');

    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $startAmountRaw = trim((string)($_POST['start_amount'] ?? '0'));
        $monthlyAmountRaw = trim((string)($_POST['monthly_amount'] ?? '0'));
        $sortOrderRaw = trim((string)($_POST['sort_order'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '') {
            $error = 'Saving name cannot be empty.';
        } elseif ($startAmountRaw !== '' && !is_numeric($startAmountRaw)) {
            $error = 'Start amount must be numeric.';
        } elseif ($monthlyAmountRaw !== '' && !is_numeric($monthlyAmountRaw)) {
            $error = 'Default monthly amount must be numeric.';
        } elseif ($sortOrderRaw !== '' && !is_numeric($sortOrderRaw)) {
            $error = 'Sort order must be numeric.';
        } else {
            $startAmount = $startAmountRaw === '' ? 0.0 : (float)$startAmountRaw;
            $monthlyAmount = $monthlyAmountRaw === '' ? 0.0 : (float)$monthlyAmountRaw;
            $sortOrder = $sortOrderRaw === '' ? repo_next_savings_sort_order($db) : (int)$sortOrderRaw;
            $id = repo_create_saving($db, $name, $startAmount, $monthlyAmount, $active, $sortOrder, null);
            if ($id) {
                redirect('/savings.php?saved=added');
            } else {
                $error = 'Could not save saving.';
            }
        }
    } elseif ($action === 'move_to') {
        $savingId = (int)($_POST['saving_id'] ?? 0);
        $newPositionRaw = trim((string)($_POST['new_position'] ?? ''));

        if ($savingId <= 0) {
            $error = 'Select a saving to move.';
        } elseif ($newPositionRaw === '' || !ctype_digit($newPositionRaw)) {
            $error = 'Enter a valid position.';
        } else {
            $orderedSavings = repo_list_savings($db);
            $currentIndex = null;
            foreach ($orderedSavings as $index => $saving) {
                if ((int)$saving['id'] === $savingId) {
                    $currentIndex = $index;
                    break;
                }
            }

            if ($currentIndex === null) {
                $error = 'Saving not found.';
            } else {
                $maxIndex = count($orderedSavings) - 1;
                $requestedIndex = max(0, min($maxIndex, (int)$newPositionRaw - 1));
                if ($requestedIndex !== $currentIndex) {
                    $selected = $orderedSavings[$currentIndex];
                    array_splice($orderedSavings, $currentIndex, 1);
                    array_splice($orderedSavings, $requestedIndex, 0, [$selected]);
                    $orderedIds = array_map(static fn(array $saving): int => (int)$saving['id'], $orderedSavings);
                    repo_reorder_savings($db, $orderedIds);
                }
                redirect('/savings.php?saved=updated');
            }
        }
    }
}

$savings = repo_list_savings_with_balance($db);
$totalBalance = 0.0;
foreach ($savings as $saving) {
    $totalBalance += (float)$saving['balance'];
}
$defaultSortOrder = repo_next_savings_sort_order($db);
$dateRange = repo_savings_transaction_date_range($db);
$defaultStartDate = (string)($dateRange['first_date'] ?? '');
$defaultEndDate = (string)($dateRange['latest_date'] ?? '');
$chartStartDate = trim((string)($_GET['start_date'] ?? $defaultStartDate));
$chartEndDate = trim((string)($_GET['end_date'] ?? $defaultEndDate));
if ($chartStartDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $chartStartDate)) {
    $chartStartDate = $defaultStartDate;
}
if ($chartEndDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $chartEndDate)) {
    $chartEndDate = $defaultEndDate;
}
if ($chartStartDate !== '' && $chartEndDate !== '' && $chartStartDate > $chartEndDate) {
    [$chartStartDate, $chartEndDate] = [$chartEndDate, $chartStartDate];
}
$timelineEntries = repo_list_savings_entries_until($db, null, $chartEndDate !== '' ? $chartEndDate : null);
$chartPoints = [];
$runningBalance = repo_total_savings_start_amount($db);
$chartPoints[] = ['label' => $chartStartDate !== '' ? $chartStartDate : 'Start', 'balance' => $runningBalance];
foreach ($timelineEntries as $timelineEntry) {
    $entryDate = (string)($timelineEntry['date'] ?? '');
    $runningBalance += (float)($timelineEntry['amount'] ?? 0);
    if ($chartStartDate !== '' && $entryDate < $chartStartDate) {
        $chartPoints[0]['balance'] = $runningBalance;
        continue;
    }
    $chartPoints[] = ['label' => $entryDate, 'balance' => $runningBalance];
}
$chartMin = null;
$chartMax = null;
$showZeroLine = false;
$balances = array_column($chartPoints, 'balance');
if (!empty($balances)) {
    $chartMin = (float)min($balances);
    $chartMax = (float)max($balances);
    $showZeroLine = $chartMin < 0.0;
    if ($showZeroLine && $chartMax < 0.0) {
        $chartMax = 0.0;
    }
}

render_header('Savings', 'savings');
?>

<div class="card">
  <div>
    <h1>Savings</h1>
    <p class="small">Track your savings buckets and monthly contributions.</p>
  </div>

  <?php if ($info !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ <?= h($info) ?>
    </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/savings.php" class="row" style="align-items:flex-end; margin-top: 12px; flex-wrap: wrap; gap: 12px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="add">
    <div style="flex: 1; min-width: 220px;">
      <label>Name</label>
      <input class="input" name="name" placeholder="e.g. Holiday fund" required>
    </div>
    <div style="min-width: 160px;">
      <label>Start amount</label>
      <input class="input" name="start_amount" type="number" step="0.01" value="0">
    </div>
    <div style="min-width: 200px;">
      <label>Default monthly amount</label>
      <input class="input" name="monthly_amount" type="number" step="0.01" value="0">
    </div>
    <div style="min-width: 140px;">
      <label>Sort order (default: last)</label>
      <input class="input" name="sort_order" type="number" step="1" value="<?= h((string)$defaultSortOrder) ?>">
    </div>
    <div style="min-width: 120px;">
      <label>Active</label>
      <label class="row small" style="gap: 8px; margin: 0;">
        <input type="checkbox" name="active" checked>
        Enabled
      </label>
    </div>
    <div>
      <button class="btn" type="submit">Create saving</button>
    </div>
  </form>

  <?php if (empty($savings)): ?>
    <div class="small muted">No savings goals yet.</div>
  <?php else: ?>
    <div class="card" style="margin-top: 12px;">
      <div class="small" style="margin-bottom: 8px;">Savings development over time</div>
      <form method="get" action="/savings.php" class="row" style="align-items: flex-end; gap: 10px; margin-bottom: 12px; flex-wrap: wrap;">
        <div style="min-width: 160px;">
          <label>Start date</label>
          <input class="input" name="start_date" type="date" value="<?= h($chartStartDate) ?>">
        </div>
        <div style="min-width: 160px;">
          <label>End date</label>
          <input class="input" name="end_date" type="date" value="<?= h($chartEndDate) ?>">
        </div>
        <button class="btn" type="submit">Update graph</button>
        <?php if ($defaultStartDate !== '' && $defaultEndDate !== ''): ?>
          <a class="small" href="/savings.php">Reset to full range</a>
        <?php endif; ?>
      </form>
      <?php if (count($chartPoints) <= 1): ?>
        <div class="small">No ledger activity yet. Add a top-up or link transactions to see a graph.</div>
      <?php else: ?>
        <?php
          $chartWidth = 720;
          $chartHeight = 220;
          $paddingLeft = 52;
          $paddingRight = 16;
          $paddingTop = 16;
          $paddingBottom = 34;
          $plotWidth = $chartWidth - $paddingLeft - $paddingRight;
          $plotHeight = $chartHeight - $paddingTop - $paddingBottom;
          $pointCount = count($chartPoints);
          $range = (float)(($chartMax ?? 0) - ($chartMin ?? 0));
          if ($range <= 0.0) { $range = 1.0; }
          $polylinePoints = [];
          $sumX = 0.0;
          $sumY = 0.0;
          $sumXY = 0.0;
          $sumXX = 0.0;
          $trendlineStart = null;
          $trendlineEnd = null;
          foreach ($chartPoints as $index => $point) {
              $pointBalance = (float)$point['balance'];
              $x = $paddingLeft + ($pointCount === 1 ? 0 : ($index / ($pointCount - 1)) * $plotWidth);
              $normalized = (($pointBalance - (float)$chartMin) / $range);
              $y = $paddingTop + ($plotHeight - ($normalized * $plotHeight));
              $polylinePoints[] = sprintf('%.2f,%.2f', $x, $y);

              $xVal = (float)$index;
              $sumX += $xVal;
              $sumY += $pointBalance;
              $sumXY += ($xVal * $pointBalance);
              $sumXX += ($xVal * $xVal);
          }

          if ($pointCount >= 2) {
              $denominator = ($pointCount * $sumXX) - ($sumX * $sumX);
              if (abs($denominator) > 0.000001) {
                  $slope = (($pointCount * $sumXY) - ($sumX * $sumY)) / $denominator;
                  $intercept = ($sumY - ($slope * $sumX)) / $pointCount;

                  $startTrendBalance = $intercept;
                  $endTrendBalance = ($slope * ($pointCount - 1)) + $intercept;

                  $startTrendNormalized = (($startTrendBalance - (float)$chartMin) / $range);
                  $endTrendNormalized = (($endTrendBalance - (float)$chartMin) / $range);

                  $trendlineStartY = $paddingTop + ($plotHeight - ($startTrendNormalized * $plotHeight));
                  $trendlineEndY = $paddingTop + ($plotHeight - ($endTrendNormalized * $plotHeight));

                  $trendlineStart = sprintf('%.2f,%.2f', (float)$paddingLeft, $trendlineStartY);
                  $trendlineEnd = sprintf('%.2f,%.2f', (float)($paddingLeft + $plotWidth), $trendlineEndY);
              }
          }

          $polyline = implode(' ', $polylinePoints);
          $latestPoint = $chartPoints[$pointCount - 1];
          $zeroLineY = null;
          if ($showZeroLine) {
              $zeroNormalized = (0.0 - (float)$chartMin) / $range;
              $zeroLineY = $paddingTop + ($plotHeight - ($zeroNormalized * $plotHeight));
          }
        ?>
        <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" width="100%" aria-label="Total savings balance development" role="img" style="display: block; width: 100%; height: auto; background: rgba(148,163,184,0.08); border-radius: 10px;">
          <line x1="<?= $paddingLeft ?>" y1="<?= $paddingTop ?>" x2="<?= $paddingLeft ?>" y2="<?= $paddingTop + $plotHeight ?>" stroke="rgba(148,163,184,0.4)" stroke-width="1" />
          <line x1="<?= $paddingLeft ?>" y1="<?= $paddingTop + $plotHeight ?>" x2="<?= $paddingLeft + $plotWidth ?>" y2="<?= $paddingTop + $plotHeight ?>" stroke="rgba(148,163,184,0.4)" stroke-width="1" />
          <?php if ($showZeroLine && $zeroLineY !== null): ?>
            <line x1="<?= $paddingLeft ?>" y1="<?= $zeroLineY ?>" x2="<?= $paddingLeft + $plotWidth ?>" y2="<?= $zeroLineY ?>" stroke="rgba(220,38,38,0.9)" stroke-width="2" stroke-dasharray="5 4" />
          <?php endif; ?>
          <polyline fill="none" stroke="var(--accent)" stroke-width="3" points="<?= h($polyline) ?>" />
          <?php if ($trendlineStart !== null && $trendlineEnd !== null): ?>
            <?php [$trendlineStartX, $trendlineStartY] = array_map('trim', explode(',', $trendlineStart)); ?>
            <?php [$trendlineEndX, $trendlineEndY] = array_map('trim', explode(',', $trendlineEnd)); ?>
            <line
              x1="<?= h($trendlineStartX) ?>"
              y1="<?= h($trendlineStartY) ?>"
              x2="<?= h($trendlineEndX) ?>"
              y2="<?= h($trendlineEndY) ?>"
              stroke="rgba(14,165,233,0.95)"
              stroke-width="2"
              stroke-dasharray="7 5"
            />
          <?php endif; ?>
          <text x="<?= $paddingLeft ?>" y="<?= $paddingTop - 2 ?>" class="small" fill="currentColor">€ <?= h(number_format((float)$chartMax, 2, ',', '.')) ?></text>
          <text x="<?= $paddingLeft ?>" y="<?= $paddingTop + $plotHeight + 18 ?>" class="small" fill="currentColor">€ <?= h(number_format((float)$chartMin, 2, ',', '.')) ?></text>
          <text x="<?= $paddingLeft + $plotWidth ?>" y="<?= $chartHeight - 8 ?>" text-anchor="end" class="small" fill="currentColor"><?= h((string)$latestPoint['label']) ?></text>
        </svg>
      <?php endif; ?>
    </div>
    <div class="row" style="justify-content: flex-end; margin-top: 12px;">
      <div class="card" style="padding: 8px 12px;">
        <div class="small muted">Total balance</div>
        <div class="money"><?= number_format($totalBalance, 2, ',', '.') ?></div>
      </div>
    </div>
    <table class="table" style="margin-top: 12px;">
      <thead>
        <tr>
          <th>Name</th>
          <th>Current balance</th>
          <th>Default monthly amount</th>
          <th>Avg monthly income*</th>
          <th>Avg monthly spending*</th>
          <th>Net avg income-spending*</th>
          <th>Top-up category</th>
          <th style="width:280px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php $savingsCount = count($savings); ?>
          <?php foreach ($savings as $index => $saving): ?>
          <?php
            $position = $index + 1;
            $netAverage = (float)($saving['avg_monthly_income'] ?? 0) - (float)($saving['avg_monthly_spending'] ?? 0);
            $netAverageClass = $netAverage < 0 ? 'money-neg' : 'money-pos';
          ?>
          <tr>
            <td>
              <div><?= h((string)$saving['name']) ?></div>
              <div class="small">
                Status: <?= !empty($saving['active']) ? 'Active' : 'Inactive' ?> · Sort order: <?= h((string)$saving['sort_order']) ?>
              </div>
            </td>
            <td class="money"><?= number_format((float)$saving['balance'], 2, ',', '.') ?></td>
            <td class="money"><?= number_format((float)$saving['monthly_amount'], 2, ',', '.') ?></td>
            <td class="money money-pos"><?= number_format((float)($saving['avg_monthly_income'] ?? 0), 2, ',', '.') ?></td>
            <td class="money money-neg"><?= number_format((float)($saving['avg_monthly_spending'] ?? 0), 2, ',', '.') ?></td>
            <td class="money <?= h($netAverageClass) ?>"><?= number_format($netAverage, 2, ',', '.') ?></td>
            <td><?= !empty($saving['topup_category_name']) ? h((string)$saving['topup_category_name']) : '—' ?></td>
            <td>
              <div class="row" style="gap: 6px; flex-wrap: wrap;">
                <a class="btn" href="/savings_edit.php?id=<?= h((string)$saving['id']) ?>">Edit</a>
                <a class="btn" href="/savings_view.php?id=<?= h((string)$saving['id']) ?>">View details</a>
                <form method="post" action="/savings.php" class="row" style="gap: 4px;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                  <input type="hidden" name="action" value="move_to">
                  <input type="hidden" name="saving_id" value="<?= h((string)$saving['id']) ?>">
                  <input
                    class="input"
                    name="new_position"
                    type="number"
                    min="1"
                    max="<?= h((string)$savingsCount) ?>"
                    placeholder="<?= h((string)$position) ?>"
                    style="width: 64px;"
                    aria-label="New position"
                  >
                  <button class="btn" type="submit" title="Move to position">✓</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small muted" style="margin-top: 8px;">
      * Average counts only months containing at least one transaction for that saving; months without transactions are ignored.
    </p>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
