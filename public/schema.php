<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$schemaFile = __DIR__ . '/../sql/schema.sql';
$info = '';
$error = '';

function execute_schema(PDO $db, string $sql): void {
    // Strip SQL comments so we don't accidentally skip CREATE statements
    // that are preceded by header comments.
    // Supports:
    //  - "-- comment" lines
    //  - "# comment" lines
    //  - "/* block comments */"
    $sql = preg_replace('/^\s*(--|#).*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Execute each statement separately.
    $statements = preg_split('/;\s*(\r?\n|$)/', $sql);
    foreach ($statements as $stmtSql) {
        $stmtSql = trim($stmtSql);
        if ($stmtSql === '') continue;
        $db->exec($stmtSql);
    }
}

$schemaText = '';
if (file_exists($schemaFile)) {
    $schemaText = (string)file_get_contents($schemaFile);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? 'save');
    $postedText = (string)($_POST['schema_sql'] ?? '');

    if ($postedText === '') {
        $error = 'Schema SQL cannot be empty.';
    } else {
        try {
            if ($action === 'save' || $action === 'save_execute') {
                if (file_put_contents($schemaFile, $postedText) === false) {
                    throw new RuntimeException('Could not write to schema file.');
                }
                $schemaText = $postedText;
                $info = 'Schema file saved.';
            }

            if ($action === 'execute' || $action === 'save_execute') {
                execute_schema($db, $postedText);
                $info = ($info !== '') ? $info . ' Schema executed.' : 'Schema executed.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

render_header('Schema', 'schema');
?>

<div class="card" style="max-width: 920px; margin: 20px auto;">
  <h1>Schema</h1>
  <p class="small">
    Edit <code>sql/schema.sql</code> and execute it against the database.
    Use with care on production data.
  </p>

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

  <form method="post" action="/schema.php">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <label>schema.sql</label>
    <textarea class="input" name="schema_sql" rows="18" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?= h($schemaText) ?></textarea>
    <div class="row" style="margin-top: 12px; gap: 10px; flex-wrap: wrap;">
      <button class="btn" type="submit" name="action" value="save">Save</button>
      <button class="btn" type="submit" name="action" value="execute">Execute</button>
      <button class="btn primary" type="submit" name="action" value="save_execute">Save & Execute</button>
    </div>
  </form>
</div>

<?php render_footer(); ?>
