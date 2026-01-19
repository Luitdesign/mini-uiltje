<?php
require_once __DIR__ . '/../app/bootstrap.php';

$ok = false;
$error = '';
$databaseName = '';
$droppedTables = [];
$tables = [];
$selectedTables = [];

try {
    $databaseName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($error === '' && $databaseName !== '') {
    $stmt = $db->prepare('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = :schema');
    $stmt->execute([':schema' => $databaseName]);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $confirmation = strtoupper(trim((string)($_POST['confirmation'] ?? '')));
    $selectedTables = array_values(array_filter((array)($_POST['tables'] ?? []), 'is_string'));

    if ($confirmation !== 'RESET') {
        $error = 'Type RESET to confirm dropping the selected tables.';
    } elseif ($databaseName === '') {
        $error = 'No database selected.';
    } elseif ($selectedTables === []) {
        $error = 'Select at least one table to drop.';
    } else {
        try {
            $tablesToDrop = array_values(array_intersect($tables, $selectedTables));
            if ($tablesToDrop === []) {
                $error = 'Selected tables are not available.';
                throw new RuntimeException($error);
            }
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tablesToDrop as $table) {
                $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
                $droppedTables[] = $table;
            }
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
            $ok = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

render_header('Reset Database', 'reset');
?>

<div class="card" style="max-width: 720px; margin: 30px auto;">
  <h1>Reset database</h1>
  <p class="small">
    This action drops the selected tables in the configured database.
    After the reset, go to <a href="/install.php">/install.php</a> to reinstall the schema if needed.
  </p>

  <?php if ($ok): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ Selected tables dropped: <?= h(implode(', ', $droppedTables)) ?>.
      You can now <a href="/install.php">run the installer</a> if you need to recreate the schema.
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <?php if ($databaseName !== ''): ?>
    <div class="card" style="margin-top: 12px;">
      <strong>Database:</strong> <?= h($databaseName) ?>
      <div class="small" style="margin-top: 8px;">
        Tables found: <?= $tables ? h(implode(', ', $tables)) : 'None' ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="/reset.php" style="margin-top: 16px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">

    <?php if ($tables): ?>
      <div class="card" style="margin-bottom: 12px;">
        <strong>Select tables to drop</strong>
        <div class="small" style="margin-top: 6px;">Choose one or more tables.</div>
        <div style="margin-top: 10px; display: grid; gap: 6px;">
          <?php foreach ($tables as $table): ?>
            <label style="display: flex; align-items: center; gap: 8px;">
              <input type="checkbox" name="tables[]" value="<?= h($table) ?>"
                <?php if (in_array($table, $selectedTables, true)): ?>checked<?php endif; ?>>
              <span><?= h($table) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="small">No tables available to drop.</div>
    <?php endif; ?>

    <label>Type RESET to confirm</label>
    <input class="input" name="confirmation" placeholder="RESET" required>

    <button class="btn" type="submit" style="margin-top: 12px;" <?= $tables ? '' : 'disabled' ?>>
      Drop selected tables
    </button>
  </form>
</div>

<?php render_footer(); ?>
