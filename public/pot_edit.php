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
    $pot = repo_get_pot($db, $userId, $potId);
    if (!$pot) {
        $error = 'Pot not found.';
        $potId = 0;
    }
}

if (!$pot) {
    $pot = [
        'id' => 0,
        'name' => '',
        'archived' => 0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $name = trim((string)($_POST['name'] ?? ''));
    $archived = isset($_POST['archived']);

    if ($name === '') {
        $error = 'Pot name cannot be empty.';
    } else {
        try {
            if ($potId > 0) {
                repo_update_pot($db, $userId, $potId, $name, $archived);
                redirect('/pots.php?saved=updated');
            }
            repo_create_pot($db, $userId, $name, $archived);
            redirect('/pots.php?saved=created');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $pot['name'] = $name;
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
