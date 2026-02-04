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
    } elseif ($action === 'move') {
        $savingId = (int)($_POST['saving_id'] ?? 0);
        $direction = (string)($_POST['direction'] ?? '');

        if ($savingId <= 0) {
            $error = 'Select a saving to move.';
        } elseif (!in_array($direction, ['up', 'down'], true)) {
            $error = 'Invalid move direction.';
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
                $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
                if (isset($orderedSavings[$targetIndex])) {
                    $currentSaving = $orderedSavings[$currentIndex];
                    $targetSaving = $orderedSavings[$targetIndex];
                    repo_swap_savings_sort_order(
                        $db,
                        (int)$currentSaving['id'],
                        (int)$currentSaving['sort_order'],
                        (int)$targetSaving['id'],
                        (int)$targetSaving['sort_order']
                    );
                    redirect('/savings.php?saved=updated');
                }
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
          <th>Top-up category</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php $savingsCount = count($savings); ?>
          <?php foreach ($savings as $index => $saving): ?>
          <?php
            $isFirst = $index === 0;
            $isLast = $index === $savingsCount - 1;
            $position = $index + 1;
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
            <td><?= !empty($saving['topup_category_name']) ? h((string)$saving['topup_category_name']) : '—' ?></td>
            <td>
              <div class="row" style="gap: 6px; flex-wrap: wrap;">
                <a class="btn" href="/savings_edit.php?id=<?= h((string)$saving['id']) ?>">Edit</a>
                <a class="btn" href="/savings_view.php?id=<?= h((string)$saving['id']) ?>">View details</a>
                <form method="post" action="/savings.php" class="row" style="gap: 4px;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                  <input type="hidden" name="action" value="move">
                  <input type="hidden" name="saving_id" value="<?= h((string)$saving['id']) ?>">
                  <button class="btn" type="submit" name="direction" value="up" <?= $isFirst ? 'disabled' : '' ?>>↑</button>
                  <button class="btn" type="submit" name="direction" value="down" <?= $isLast ? 'disabled' : '' ?>>↓</button>
                </form>
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
  <?php endif; ?>
</div>

<?php render_footer(); ?>
