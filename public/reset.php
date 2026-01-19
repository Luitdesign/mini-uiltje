<?php
require_once __DIR__ . '/../app/bootstrap.php';

$ok = false;
$error = '';
$databaseName = '';
$droppedTables = [];
$tables = [];

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

    if ($confirmation !== 'RESET') {
        $error = 'Type RESET to confirm dropping all tables.';
    } elseif ($databaseName === '') {
        $error = 'No database selected.';
    } else {
        try {
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tables as $table) {
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
    This action drops <strong>all tables</strong> in the configured database.
    After the reset, go to <a href="/install.php">/install.php</a> to reinstall the schema.
  </p>

  <?php if ($ok): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ Database reset. You can now <a href="/install.php">run the installer</a>.
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

    <label>Type RESET to confirm</label>
    <input class="input" name="confirmation" placeholder="RESET" required>

    <button class="btn" type="submit" style="margin-top: 12px;">Drop all tables</button>
  </form>
</div>

<?php render_footer(); ?>
