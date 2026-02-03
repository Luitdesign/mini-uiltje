<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$savingId = (int)($_GET['id'] ?? 0);
$info = '';
$error = '';

$saving = null;
if ($savingId > 0) {
    $saving = repo_find_saving($db, $savingId);
    if (!$saving) {
        $error = 'Saving not found.';
        $savingId = 0;
    }
}

if (!$saving) {
    $saving = [
        'id' => 0,
        'name' => '',
        'active' => 1,
        'sort_order' => repo_next_savings_sort_order($db),
        'start_amount' => 0,
        'monthly_amount' => 0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $name = trim((string)($_POST['name'] ?? ''));
    $startAmountRaw = trim((string)($_POST['start_amount'] ?? '0'));
    $monthlyAmountRaw = trim((string)($_POST['monthly_amount'] ?? '0'));
    $sortOrderRaw = trim((string)($_POST['sort_order'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;

    if ($savingId <= 0) {
        $error = 'Select a saving to edit.';
    } elseif ($name === '') {
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

        repo_update_saving($db, $savingId, $name, $startAmount, $monthlyAmount, $active, $sortOrder);
        redirect('/savings.php?saved=updated');
    }

    $saving = array_merge($saving, [
        'name' => $name,
        'active' => $active,
        'sort_order' => $sortOrderRaw,
        'start_amount' => $startAmountRaw,
        'monthly_amount' => $monthlyAmountRaw,
    ]);
}

render_header('Edit Saving', 'savings');
?>

<div class="card" style="max-width: 680px; margin: 0 auto;">
  <div class="row" style="justify-content: space-between; align-items: center;">
    <h1>Edit Saving</h1>
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

  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">

    <div style="margin-top: 12px;">
      <label>Name</label>
      <input class="input" name="name" value="<?= h((string)$saving['name']) ?>" required>
    </div>

    <div class="grid-2" style="margin-top: 12px;">
      <div>
        <label>Start amount</label>
        <input class="input" name="start_amount" type="number" step="0.01" value="<?= h((string)$saving['start_amount']) ?>">
      </div>
      <div>
        <label>Default monthly amount</label>
        <input class="input" name="monthly_amount" type="number" step="0.01" value="<?= h((string)$saving['monthly_amount']) ?>">
      </div>
    </div>

    <div class="grid-2" style="margin-top: 12px;">
      <div>
        <label>Sort order</label>
        <input class="input" name="sort_order" type="number" step="1" value="<?= h((string)$saving['sort_order']) ?>">
      </div>
      <div>
        <label>Active</label>
        <label class="row small" style="gap: 8px; margin: 0;">
          <input type="checkbox" name="active" <?= !empty($saving['active']) ? 'checked' : '' ?>>
          Enabled
        </label>
      </div>
    </div>

    <div class="row" style="margin-top: 18px; gap: 10px;">
      <button class="btn primary" type="submit">Save saving</button>
      <a class="btn" href="/savings.php">Cancel</a>
    </div>
  </form>
</div>

<?php render_footer(); ?>
