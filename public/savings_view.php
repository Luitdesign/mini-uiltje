<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
$error = '';

if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    $info = $saved === 'updated' ? 'Changes saved.' : 'Saving updated.';
}

$savingId = (int)($_GET['id'] ?? $_POST['saving_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? 'topup');

    if ($action === 'topup') {
        $date = trim((string)($_POST['date'] ?? ''));
        $amountRaw = trim((string)($_POST['amount'] ?? ''));

        if ($savingId <= 0) {
            $error = 'Select a valid saving.';
        } elseif ($date === '') {
            $error = 'Top-up date is required.';
        } elseif ($amountRaw === '' || !is_numeric($amountRaw)) {
            $error = 'Top-up amount must be numeric.';
        } else {
            $amount = (float)$amountRaw;
            try {
                repo_add_savings_topup($db, current_data_user_id($db), $savingId, $date, $amount);
                redirect('/savings_view.php?id=' . $savingId . '&saved=updated');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$saving = $savingId > 0 ? repo_find_saving_with_balance($db, $savingId) : null;
if (!$saving && $error === '') {
    $error = 'Saving not found.';
}

$ledgerView = (string)($_GET['ledger_view'] ?? 'all');
$ledgerView = $ledgerView === 'latest' ? 'latest' : 'all';
$ledgerLimit = $ledgerView === 'latest' ? 5 : null;
$ledgerToggleParams = ['id' => $savingId];
$ledgerToggleParams['ledger_view'] = $ledgerView === 'latest' ? 'all' : 'latest';
$ledgerToggleUrl = '/savings_view.php' . ($ledgerToggleParams ? '?' . http_build_query($ledgerToggleParams) : '');
$ledgerToggleLabel = $ledgerView === 'latest' ? 'Show all ledger entries' : 'Show latest 5 entries';
$entries = $saving ? repo_list_savings_entries($db, (int)$saving['id'], $ledgerLimit) : [];
$timelineEntries = $saving ? repo_list_savings_entries($db, (int)$saving['id']) : [];

$chartPoints = [];
$chartMin = null;
$chartMax = null;
$showZeroLine = false;
if ($saving) {
    $runningBalance = (float)$saving['start_amount'];
    $orderedTimelineEntries = $timelineEntries;
    usort(
        $orderedTimelineEntries,
        static function (array $left, array $right): int {
            $leftDate = (string)($left['date'] ?? '');
            $rightDate = (string)($right['date'] ?? '');
            if ($leftDate === $rightDate) {
                return ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
            }
            return $leftDate <=> $rightDate;
        }
    );

    $chartPoints[] = [
        'label' => 'Start',
        'date' => '',
        'balance' => $runningBalance,
    ];
    foreach ($orderedTimelineEntries as $timelineEntry) {
        $runningBalance += (float)($timelineEntry['amount'] ?? 0);
        $chartPoints[] = [
            'label' => (string)($timelineEntry['date'] ?? ''),
            'date' => (string)($timelineEntry['date'] ?? ''),
            'balance' => $runningBalance,
        ];
    }

    $balances = array_column($chartPoints, 'balance');
    if (!empty($balances)) {
        $chartMin = (float)min($balances);
        $chartMax = (float)max($balances);
        $showZeroLine = $chartMin < 0.0;
        if ($showZeroLine && $chartMax < 0.0) {
            $chartMax = 0.0;
        }
    }
}

render_header('Saving details', 'savings');
?>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center;">
    <div>
      <h1>Saving details</h1>
      <p class="small">Review balances, add top-ups, and browse ledger entries.</p>
    </div>
    <a class="btn" href="/savings.php">Back to savings</a>
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

  <?php if ($saving): ?>
    <div class="row" style="justify-content: space-between; align-items: center; margin-top: 12px;">
      <div>
        <h2 style="margin: 0;"><?= h((string)$saving['name']) ?></h2>
        <div class="small">
          Status: <?= !empty($saving['active']) ? 'Active' : 'Inactive' ?> · Sort order: <?= h((string)$saving['sort_order']) ?>
        </div>
      </div>
      <a class="btn" href="/savings_edit.php?id=<?= h((string)$saving['id']) ?>">Edit</a>
    </div>

    <div class="grid-2" style="margin-top: 12px;">
      <div class="card">
        <div class="small">Current balance</div>
        <div class="money" style="font-size: 22px; font-weight: 700; margin-top: 6px;">
          <?= number_format((float)$saving['balance'], 2, ',', '.') ?>
        </div>
        <div class="small" style="margin-top: 6px;">
          Start amount: <?= number_format((float)$saving['start_amount'], 2, ',', '.') ?>
        </div>
      </div>
      <div class="card">
        <div class="small">Default monthly amount</div>
        <div class="money" style="font-size: 22px; font-weight: 700; margin-top: 6px;">
          <?= number_format((float)$saving['monthly_amount'], 2, ',', '.') ?>
        </div>
      </div>
    </div>

    <form method="post" action="/savings_view.php?id=<?= h((string)$saving['id']) ?>" class="row" style="align-items: flex-end; margin-top: 12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
      <input type="hidden" name="action" value="topup">
      <input type="hidden" name="saving_id" value="<?= (int)$saving['id'] ?>">
      <div style="min-width: 180px;">
        <label>Date</label>
        <input class="input" name="date" type="date" value="<?= h(date('Y-m-d')) ?>">
      </div>
      <div style="min-width: 180px;">
        <label>Amount</label>
        <input class="input" name="amount" type="number" step="0.01" value="<?= h((string)$saving['monthly_amount']) ?>">
      </div>
      <div>
        <button class="btn" type="submit">Add top-up</button>
      </div>
    </form>

    <div class="card" style="margin-top: 12px;">
      <div class="small" style="margin-bottom: 8px;">Savings development over time</div>
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
          if ($range <= 0.0) {
              $range = 1.0;
          }
          $polylinePoints = [];
          foreach ($chartPoints as $index => $point) {
              $x = $paddingLeft + ($pointCount === 1 ? 0 : ($index / ($pointCount - 1)) * $plotWidth);
              $normalized = (((float)$point['balance'] - (float)$chartMin) / $range);
              $y = $paddingTop + ($plotHeight - ($normalized * $plotHeight));
              $polylinePoints[] = sprintf('%.2f,%.2f', $x, $y);
          }
          $polyline = implode(' ', $polylinePoints);
          $latestPoint = $chartPoints[$pointCount - 1];
          $zeroLineY = null;
          if ($showZeroLine) {
              $zeroNormalized = (0.0 - (float)$chartMin) / $range;
              $zeroLineY = $paddingTop + ($plotHeight - ($zeroNormalized * $plotHeight));
          }
        ?>
        <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" width="100%" aria-label="Savings balance development" role="img" style="display: block; width: 100%; height: auto; background: rgba(148,163,184,0.08); border-radius: 10px;">
          <line x1="<?= $paddingLeft ?>" y1="<?= $paddingTop ?>" x2="<?= $paddingLeft ?>" y2="<?= $paddingTop + $plotHeight ?>" stroke="rgba(148,163,184,0.4)" stroke-width="1" />
          <line x1="<?= $paddingLeft ?>" y1="<?= $paddingTop + $plotHeight ?>" x2="<?= $paddingLeft + $plotWidth ?>" y2="<?= $paddingTop + $plotHeight ?>" stroke="rgba(148,163,184,0.4)" stroke-width="1" />
          <?php if ($showZeroLine && $zeroLineY !== null): ?>
            <line x1="<?= $paddingLeft ?>" y1="<?= $zeroLineY ?>" x2="<?= $paddingLeft + $plotWidth ?>" y2="<?= $zeroLineY ?>" stroke="rgba(220,38,38,0.9)" stroke-width="2" stroke-dasharray="5 4" />
          <?php endif; ?>
          <polyline fill="none" stroke="var(--accent)" stroke-width="3" points="<?= h($polyline) ?>" />
          <text x="<?= $paddingLeft ?>" y="<?= $paddingTop - 2 ?>" class="small" fill="currentColor">€ <?= h(number_format((float)$chartMax, 2, ',', '.')) ?></text>
          <text x="<?= $paddingLeft ?>" y="<?= $paddingTop + $plotHeight + 18 ?>" class="small" fill="currentColor">€ <?= h(number_format((float)$chartMin, 2, ',', '.')) ?></text>
          
          <text x="<?= $paddingLeft + $plotWidth ?>" y="<?= $chartHeight - 8 ?>" text-anchor="end" class="small" fill="currentColor">
            <?= h((string)($latestPoint['label'] !== '' ? $latestPoint['label'] : 'Now')) ?>
          </text>
        </svg>
      <?php endif; ?>
    </div>

    <div style="margin-top: 12px;">
      <div class="row" style="justify-content: space-between; align-items: center;">
        <div class="small">Ledger entries</div>
        <a class="small" href="<?= h($ledgerToggleUrl) ?>"><?= h($ledgerToggleLabel) ?></a>
      </div>
      <table class="table" style="margin-top: 8px;">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Description</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($entries)): ?>
            <tr><td colspan="4" class="small">No ledger entries yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($entries as $entry): ?>
            <?php $entryAmount = (float)$entry['amount']; ?>
            <?php $friendlyName = trim((string)($entry['friendly_name'] ?? '')); ?>
            <tr>
              <td><?= h((string)$entry['date']) ?></td>
              <td><span class="badge"><?= h((string)$entry['entry_type']) ?></span></td>
              <td><?= h($friendlyName !== '' ? $friendlyName : (string)($entry['transaction_description'] ?? $entry['note'] ?? '—')) ?></td>
              <td class="money <?= $entryAmount >= 0 ? 'money-pos' : 'money-neg' ?>">
                <?= number_format($entryAmount, 2, ',', '.') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
