<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

require_admin();

$pdo = db();
$migrationsDir = realpath(__DIR__ . '/../../migrations');

function migration_files(?string $dir): array {
    if (!$dir || !is_dir($dir)) {
        return [];
    }
    $files = array_values(array_filter(scandir($dir), function (string $file): bool {
        return $file !== '.' && $file !== '..' && str_ends_with($file, '.sql');
    }));
    sort($files);
    return $files;
}

function schema_migrations_exist(PDO $pdo): bool {
    $stmt = $pdo->query("SHOW TABLES LIKE 'schema_migrations'");
    return (bool)$stmt->fetchColumn();
}

function applied_migrations(PDO $pdo): array {
    if (!schema_migrations_exist($pdo)) {
        return [];
    }
    $stmt = $pdo->query('SELECT filename FROM schema_migrations ORDER BY id');
    return array_column($stmt->fetchAll(), 'filename');
}

function split_sql(string $sql): array {
    $statements = [];
    $buffer = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        if ($inString) {
            if ($char === $stringChar) {
                $escaped = $i > 0 && $sql[$i - 1] === '\\';
                if (!$escaped) {
                    $inString = false;
                    $stringChar = '';
                }
            }
            $buffer .= $char;
            continue;
        }
        if ($char === '\'' || $char === '"') {
            $inString = true;
            $stringChar = $char;
            $buffer .= $char;
            continue;
        }
        if ($char === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}

function run_migration(PDO $pdo, string $path, string $filename): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration file.');
    }
    foreach (split_sql($sql) as $statement) {
        $pdo->exec($statement);
    }
    $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())');
    $stmt->execute([$filename]);
}

$availableMigrations = migration_files($migrationsDir);
$applied = [];
$loadError = null;

try {
    $applied = applied_migrations($pdo);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$pending = array_values(array_diff($availableMigrations, $applied));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $toRun = [];

    if ($action === 'run_all') {
        $toRun = $pending;
    } elseif ($action === 'run_one') {
        $requested = basename((string)($_POST['file'] ?? ''));
        if (in_array($requested, $pending, true)) {
            $toRun = [$requested];
        } else {
            flash_set('Selected migration is not pending.', 'error');
            redirect('/admin/migrate.php');
        }
    } else {
        flash_set('Unknown migration action.', 'error');
        redirect('/admin/migrate.php');
    }

    if (!$toRun) {
        flash_set('No pending migrations to run.', 'info');
        redirect('/admin/migrate.php');
    }

    $results = [];
    $transactionStarted = false;
    try {
        $transactionStarted = $pdo->beginTransaction();
    } catch (Throwable $e) {
        $transactionStarted = false;
    }

    $failed = false;
    foreach ($toRun as $filename) {
        $path = $migrationsDir . '/' . $filename;
        try {
            run_migration($pdo, $path, $filename);
            $results[] = [
                'file' => $filename,
                'status' => 'success',
                'message' => 'Applied successfully.',
            ];
        } catch (Throwable $e) {
            $results[] = [
                'file' => $filename,
                'status' => 'failure',
                'message' => $e->getMessage(),
            ];
            $failed = true;
            break;
        }
    }

    if ($transactionStarted && $pdo->inTransaction()) {
        if ($failed) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    }

    $_SESSION['_migration_results'] = $results;
    if ($failed) {
        flash_set('Migration run failed. Review the output below.', 'error');
    } else {
        flash_set('Migrations applied successfully.', 'info');
    }
    redirect('/admin/migrate.php');
}

$lastRun = $_SESSION['_migration_results'] ?? null;
unset($_SESSION['_migration_results']);

render_header('Database migrations');
?>
<div class="card">
  <h2>Database migrations</h2>
  <?php if ($loadError): ?>
    <p class="error">Unable to load applied migrations: <?=h($loadError)?></p>
  <?php endif; ?>
  <?php if (!$migrationsDir): ?>
    <p class="error">The migrations directory is missing.</p>
  <?php elseif (!$availableMigrations): ?>
    <p class="muted">No migration files found.</p>
  <?php else: ?>
    <p class="muted">Found <?=h((string)count($availableMigrations))?> migration file(s).</p>
  <?php endif; ?>
</div>

<?php if ($lastRun): ?>
  <div class="card">
    <h2>Last run</h2>
    <table class="table">
      <thead><tr><th>Migration</th><th>Status</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($lastRun as $result): ?>
        <tr>
          <td><?=h($result['file'])?></td>
          <td><?=h(ucfirst($result['status']))?></td>
          <td><?=h($result['message'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Pending migrations</h2>
  <?php if (!$pending): ?>
    <p class="muted">All migrations have been applied.</p>
  <?php else: ?>
    <form method="post" style="margin-bottom: 1rem;">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="run_all">
      <button class="btn primary" type="submit">Run all</button>
    </form>
    <table class="table">
      <thead><tr><th>Migration</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pending as $file): ?>
        <tr>
          <td><?=h($file)?></td>
          <td>
            <form method="post">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="run_one">
              <input type="hidden" name="file" value="<?=h($file)?>">
              <button class="btn" type="submit">Run</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
render_footer();
