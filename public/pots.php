<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    if ($saved === 'created') {
        $info = 'Pot created.';
    } elseif ($saved === 'updated') {
        $info = 'Pot updated.';
    }
}

$userId = current_user_id();
$pots = repo_list_pots_with_balances($db, $userId);

render_header('Pots', 'pots');
?>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center; gap: 12px;">
    <div>
      <h1>Pots</h1>
      <p class="small">Track balances and allocations per pot.</p>
    </div>
    <div class="row" style="gap: 8px; flex-wrap: wrap;">
      <a class="btn" href="/pots_categories.php">Manage category links</a>
      <a class="btn primary" href="/pot_edit.php?new=1">+ New pot</a>
    </div>
  </div>

  <?php if ($info !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ <?= h($info) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($pots)): ?>
    <div class="small muted">No pots yet.</div>
  <?php endif; ?>

  <?php if (!empty($pots)): ?>
    <table class="table" style="margin-top: 12px;">
      <thead>
        <tr>
          <th>Pot name</th>
          <th style="width: 140px;">Balance</th>
          <th style="width: 160px;">Allocated total</th>
          <th style="width: 140px;">Spent total</th>
          <th style="width: 160px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pots as $pot): ?>
          <tr>
            <td>
              <?= h($pot['name']) ?>
              <?php if (!empty($pot['archived'])): ?>
                <span class="badge" style="margin-left: 6px;">Archived</span>
              <?php endif; ?>
            </td>
            <td class="money"><?= number_format((float)$pot['balance'], 2, ',', '.') ?></td>
            <td class="money"><?= number_format((float)$pot['allocated_total'], 2, ',', '.') ?></td>
            <td class="money"><?= number_format((float)$pot['spent_total'], 2, ',', '.') ?></td>
            <td>
              <div class="inline-actions">
                <a class="btn" href="/pot_edit.php?id=<?= h((string)$pot['id']) ?>">Edit</a>
                <a class="btn" href="/pot_rules.php?pot_id=<?= h((string)$pot['id']) ?>">Rules</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
