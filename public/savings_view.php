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
                repo_add_savings_topup($db, current_user_id(), $savingId, $date, $amount);
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
$ledgerOrder = (string)($_GET['ledger_order'] ?? 'newest');
$ledgerOrder = $ledgerOrder === 'oldest' ? 'oldest' : 'newest';
$ledgerLimit = $ledgerView === 'latest' ? 5 : null;
$ledgerToggleParams = ['id' => $savingId, 'ledger_order' => $ledgerOrder];
$ledgerToggleParams['ledger_view'] = $ledgerView === 'latest' ? 'all' : 'latest';
$ledgerToggleUrl = '/savings_view.php' . ($ledgerToggleParams ? '?' . http_build_query($ledgerToggleParams) : '');
$ledgerToggleLabel = $ledgerView === 'latest' ? 'Show all ledger entries' : 'Show latest 5 entries';
$ledgerOrderNewestParams = ['id' => $savingId, 'ledger_view' => $ledgerView, 'ledger_order' => 'newest'];
$ledgerOrderOldestParams = ['id' => $savingId, 'ledger_view' => $ledgerView, 'ledger_order' => 'oldest'];
$ledgerOrderNewestUrl = '/savings_view.php' . ($ledgerOrderNewestParams ? '?' . http_build_query($ledgerOrderNewestParams) : '');
$ledgerOrderOldestUrl = '/savings_view.php' . ($ledgerOrderOldestParams ? '?' . http_build_query($ledgerOrderOldestParams) : '');
$entries = $saving ? repo_list_savings_entries($db, (int)$saving['id'], $ledgerLimit, $ledgerOrder) : [];

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

    <div style="margin-top: 12px;">
      <div class="row" style="justify-content: space-between; align-items: center;">
        <div class="small">Ledger entries</div>
        <div class="row" style="gap: 12px;">
          <a class="small" href="<?= h($ledgerToggleUrl) ?>"><?= h($ledgerToggleLabel) ?></a>
          <div class="row" style="gap: 6px; align-items: center;">
            <span class="small">Order</span>
            <?php if ($ledgerOrder === 'oldest'): ?>
              <span class="small" title="Oldest first">⬆️</span>
            <?php else: ?>
              <a class="small" href="<?= h($ledgerOrderOldestUrl) ?>" title="Oldest first">⬆️</a>
            <?php endif; ?>
            <?php if ($ledgerOrder === 'newest'): ?>
              <span class="small" title="Newest first">⬇️</span>
            <?php else: ?>
              <a class="small" href="<?= h($ledgerOrderNewestUrl) ?>" title="Newest first">⬇️</a>
            <?php endif; ?>
          </div>
        </div>
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
            <tr>
              <td><?= h((string)$entry['date']) ?></td>
              <td><span class="badge"><?= h((string)$entry['entry_type']) ?></span></td>
              <td><?= h((string)($entry['transaction_description'] ?? $entry['note'] ?? '—')) ?></td>
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
