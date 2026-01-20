<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
$potId = (int)($_GET['id'] ?? 0);
$isNew = isset($_GET['new']) && $potId === 0;
$info = '';
$error = '';

$pot = null;
if ($potId > 0) {
    $pot = repo_get_pot_with_balance($db, $userId, $potId);
    if (!$pot) {
        $error = 'Pot not found.';
        $potId = 0;
    }
}

if (!$pot) {
    $pot = [
        'id' => 0,
        'name' => '',
        'start_amount' => 0,
        'current_amount' => '',
        'archived' => 0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $name = trim((string)($_POST['name'] ?? ''));
    $startAmountRaw = trim((string)($_POST['start_amount'] ?? ''));
    $currentAmountRaw = trim((string)($_POST['current_amount'] ?? ''));
    $archived = isset($_POST['archived']);

    if ($name === '') {
        $error = 'Pot name cannot be empty.';
    } elseif ($startAmountRaw === '' && $currentAmountRaw === '') {
        $error = 'Enter a start amount or a current amount.';
    } elseif ($startAmountRaw !== '' && !is_numeric($startAmountRaw)) {
        $error = 'Start amount must be a number.';
    } elseif ($currentAmountRaw !== '' && !is_numeric($currentAmountRaw)) {
        $error = 'Current amount must be a number.';
    } else {
        try {
            $startAmount = (float)($startAmountRaw === '' ? 0 : $startAmountRaw);
            if ($currentAmountRaw !== '') {
                $currentAmount = (float)$currentAmountRaw;
                $allocatedTotal = 0.0;
                $spentTotal = 0.0;
                if ($potId > 0) {
                    $potWithBalance = repo_get_pot_with_balance($db, $userId, $potId);
                    if (!$potWithBalance) {
                        throw new RuntimeException('Pot not found.');
                    }
                    $allocatedTotal = (float)$potWithBalance['allocated_total'];
                    $spentTotal = (float)$potWithBalance['spent_total'];
                }
                $startAmount = $currentAmount - $allocatedTotal + $spentTotal;
            }
            if ($potId > 0) {
                repo_update_pot($db, $userId, $potId, $name, $startAmount, $archived);
                redirect('/pots.php?saved=updated');
            }
            repo_create_pot($db, $userId, $name, $startAmount, $archived);
            redirect('/pots.php?saved=created');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $pot['name'] = $name;
    $pot['start_amount'] = $startAmountRaw === '' ? 0 : (float)$startAmountRaw;
    $pot['current_amount'] = $currentAmountRaw;
    $pot['archived'] = $archived ? 1 : 0;
}

render_header($potId > 0 ? 'Edit Pot' : 'New Pot', 'pots');
?>

<div class="card" style="max-width: 720px; margin: 0 auto;">
  <div class="row" style="justify-content: space-between; align-items: center;">
    <h1><?= $potId > 0 ? 'Edit pot' : 'New pot' ?></h1>
    <a class="btn" href="/pots.php">Back to pots</a>
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
      <label>Pot name</label>
      <input class="input" name="name" value="<?= h((string)$pot['name']) ?>" required>
    </div>

    <div style="margin-top: 12px;">
      <label>Start amount</label>
      <input class="input" type="number" step="0.01" name="start_amount" value="<?= h((string)$pot['start_amount']) ?>">
      <div class="small muted">Set the amount already in this pot, or use the current amount below.</div>
    </div>

    <div style="margin-top: 12px;">
      <label>Current amount</label>
      <input class="input" type="number" step="0.01" name="current_amount" value="<?= h((string)($pot['current_amount'] !== '' ? $pot['current_amount'] : ($pot['balance'] ?? ''))) ?>">
      <div class="small muted">Enter the current amount and we will calculate the start amount.</div>
    </div>

    <div style="margin-top: 12px;">
      <label>Current amount</label>
      <div class="input" style="display: flex; align-items: center;">
        <?= number_format((float)($pot['balance'] ?? $pot['current_amount'] ?? 0), 2, ',', '.') ?>
      </div>
      <div class="small muted">Calculated from the start amount, allocations, and spending.</div>
    </div>

    <div style="margin-top: 12px;">
      <label>
        <input type="checkbox" name="archived" value="1" <?= !empty($pot['archived']) ? 'checked' : '' ?>>
        Archived
      </label>
      <div class="small muted">Archived pots stay in history and can be re-enabled later.</div>
    </div>

    <div class="row" style="margin-top: 18px; gap: 10px;">
      <button class="btn primary" type="submit">Save</button>
      <a class="btn" href="/pots.php">Cancel</a>
    </div>
  </form>
</div>

<?php render_footer(); ?>
