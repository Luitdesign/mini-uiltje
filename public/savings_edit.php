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
        'topup_category_id' => null,
    ];
}

$categories = repo_list_categories($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $name = trim((string)($_POST['name'] ?? ''));
    $startAmountRaw = trim((string)($_POST['start_amount'] ?? '0'));
    $monthlyAmountRaw = trim((string)($_POST['monthly_amount'] ?? '0'));
    $sortOrderRaw = trim((string)($_POST['sort_order'] ?? ''));
    $topupCategoryRaw = trim((string)($_POST['topup_category_id'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;

    $topupCategoryId = null;
    $topupCategoryError = '';
    if ($topupCategoryRaw !== '' && $topupCategoryRaw !== '0') {
        if (!is_numeric($topupCategoryRaw)) {
            $topupCategoryError = 'Top-up category must be numeric.';
        } else {
            $topupCategoryId = (int)$topupCategoryRaw;
            if ($topupCategoryId <= 0) {
                $topupCategoryId = null;
            }
        }
    }

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
    } elseif ($topupCategoryError !== '') {
        $error = $topupCategoryError;
    } elseif ($topupCategoryId !== null && !repo_get_category($db, $topupCategoryId)) {
        $error = 'Select a valid top-up category.';
    } else {
        $startAmount = $startAmountRaw === '' ? 0.0 : (float)$startAmountRaw;
        $monthlyAmount = $monthlyAmountRaw === '' ? 0.0 : (float)$monthlyAmountRaw;
        $sortOrder = $sortOrderRaw === '' ? repo_next_savings_sort_order($db) : (int)$sortOrderRaw;

        repo_update_saving(
            $db,
            $savingId,
            $name,
            $startAmount,
            $monthlyAmount,
            $active,
            $sortOrder,
            $topupCategoryId
        );
        redirect('/savings.php?saved=updated');
    }

    $saving = array_merge($saving, [
        'name' => $name,
        'active' => $active,
        'sort_order' => $sortOrderRaw,
        'start_amount' => $startAmountRaw,
        'monthly_amount' => $monthlyAmountRaw,
        'topup_category_id' => $topupCategoryId,
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

    <div style="margin-top: 12px;">
      <label>Top-up category</label>
      <select class="input" name="topup_category_id">
        <option value="">No category</option>
        <?php foreach ($categories as $category): ?>
          <?php $selected = (int)($saving['topup_category_id'] ?? 0) === (int)$category['id']; ?>
          <option value="<?= h((string)$category['id']) ?>" <?= $selected ? 'selected' : '' ?>>
            <?= h((string)$category['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="small muted">New top-ups will use this category.</div>
    </div>

    <div class="row" style="margin-top: 18px; gap: 10px;">
      <button class="btn primary" type="submit">Save saving</button>
      <a class="btn" href="/savings.php">Cancel</a>
    </div>
  </form>
</div>

<?php render_footer(); ?>
