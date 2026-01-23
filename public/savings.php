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
            $id = repo_create_saving($db, $name, $startAmount, $monthlyAmount, $active, $sortOrder);
            if ($id) {
                redirect('/savings.php?saved=added');
            } else {
                $error = 'Could not save saving.';
            }
        }
    }
    if ($action === 'topup') {
        $savingId = (int)($_POST['saving_id'] ?? 0);
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
                redirect('/savings.php?saved=updated');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$savings = repo_list_savings_with_balance($db);
$defaultSortOrder = repo_next_savings_sort_order($db);

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
  <?php endif; ?>

  <?php foreach ($savings as $saving): ?>
    <?php $entries = repo_list_savings_entries($db, (int)$saving['id'], 5); ?>
    <div class="card" style="margin-top: 12px;">
      <div class="row" style="justify-content: space-between; align-items: center;">
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

      <form method="post" action="/savings.php" class="row" style="align-items: flex-end; margin-top: 12px;">
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
        <div class="small">Latest ledger entries</div>
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
    </div>
  <?php endforeach; ?>
</div>

<?php render_footer(); ?>
