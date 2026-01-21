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
}

$savings = repo_list_savings($db);
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

  <?php if (!empty($savings)): ?>
    <table class="table" style="margin-top: 12px;">
      <thead>
        <tr>
          <th>Name</th>
          <th style="width: 90px;">Active</th>
          <th style="width: 110px;">Sort order</th>
          <th style="width: 160px;">Start amount</th>
          <th style="width: 200px;">Default monthly amount</th>
          <th style="width: 160px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savings as $saving): ?>
          <tr>
            <td><?= h((string)$saving['name']) ?></td>
            <td><?= !empty($saving['active']) ? 'Yes' : 'No' ?></td>
            <td><?= h((string)$saving['sort_order']) ?></td>
            <td class="money"><?= number_format((float)$saving['start_amount'], 2, ',', '.') ?></td>
            <td class="money"><?= number_format((float)$saving['monthly_amount'], 2, ',', '.') ?></td>
            <td>
              <div class="row" style="gap: 6px; flex-wrap: wrap;">
                <a class="btn" href="/savings_edit.php?id=<?= h((string)$saving['id']) ?>">Edit</a>
                <button class="btn" type="button" disabled>
                  <?= !empty($saving['active']) ? 'Deactivate' : 'Activate' ?>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
