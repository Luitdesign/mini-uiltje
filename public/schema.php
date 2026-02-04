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
        try {
            $db->exec($stmtSql);
        } catch (PDOException $e) {
            $errorInfo = $e->errorInfo ?? [];
            $errorCode = $errorInfo[1] ?? null;

            if ($errorCode === 1060) {
                continue;
            }

            throw $e;
        }
    }
}

$schemaText = '';
if (file_exists($schemaFile)) {
    $schemaText = (string)file_get_contents($schemaFile);
}

$sqlDir = dirname($schemaFile);
$serverSqlFiles = [];
if (is_dir($sqlDir)) {
    foreach (scandir($sqlDir) ?: [] as $file) {
        if ($file[0] === '.') {
            continue;
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, ['sql', 'mysql'], true)) {
            $serverSqlFiles[] = $file;
        }
    }
}
sort($serverSqlFiles, SORT_NATURAL | SORT_FLAG_CASE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? 'save');
    $postedText = (string)($_POST['schema_sql'] ?? '');

    try {
        if ($action === 'execute_server_file') {
            $selectedFile = (string)($_POST['server_sql_file'] ?? '');
            if ($selectedFile === '') {
                throw new RuntimeException('Please choose a server SQL file to execute.');
            }
            if (!in_array($selectedFile, $serverSqlFiles, true)) {
                throw new RuntimeException('Selected SQL file is not available on the server.');
            }

            $serverFilePath = $sqlDir . '/' . $selectedFile;
            $serverSql = (string)file_get_contents($serverFilePath);
            if ($serverSql === '') {
                throw new RuntimeException('Selected SQL file is empty.');
            }

            execute_schema($db, $serverSql);
            $info = 'Executed server file: ' . $selectedFile . '.';
        } elseif ($action === 'execute_upload') {
            $uploadedFile = $_FILES['update_file'] ?? null;
            if (!$uploadedFile || !isset($uploadedFile['error']) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please select a MySQL update file to execute.');
            }

            $uploadedName = (string)($uploadedFile['name'] ?? 'uploaded.sql');
            $uploadedSql = (string)file_get_contents($uploadedFile['tmp_name']);
            if ($uploadedSql === '') {
                throw new RuntimeException('Uploaded SQL file is empty.');
            }

            execute_schema($db, $uploadedSql);
            $info = 'Executed uploaded file: ' . $uploadedName . '.';
        } else {
            if ($postedText === '') {
                throw new RuntimeException('Schema SQL cannot be empty.');
            }

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
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
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

  <form method="post" action="/schema.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <label>schema.sql</label>
    <textarea class="input" name="schema_sql" rows="18" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?= h($schemaText) ?></textarea>
    <div class="row" style="margin-top: 12px; gap: 10px; flex-wrap: wrap;">
      <button class="btn" type="submit" name="action" value="save">Save</button>
      <button class="btn" type="submit" name="action" value="execute">Execute</button>
      <button class="btn primary" type="submit" name="action" value="save_execute">Save & Execute</button>
    </div>
    <div style="margin-top: 18px;">
      <label>MySQL update file</label>
      <input class="input" type="file" name="update_file" accept=".sql,.mysql">
      <div class="row" style="margin-top: 10px;">
        <button class="btn" type="submit" name="action" value="execute_upload">Execute uploaded file</button>
      </div>
    </div>
    <div style="margin-top: 18px;">
      <label>Server SQL files</label>
      <?php if (count($serverSqlFiles) > 0): ?>
        <select class="input" name="server_sql_file">
          <option value="">Select a file</option>
          <?php foreach ($serverSqlFiles as $serverSqlFile): ?>
            <option value="<?= h($serverSqlFile) ?>"><?= h($serverSqlFile) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="row" style="margin-top: 10px;">
          <button class="btn" type="submit" name="action" value="execute_server_file">Execute selected file</button>
        </div>
      <?php else: ?>
        <div class="small muted">No .sql or .mysql files found in <code>sql/</code>.</div>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php render_footer(); ?>
