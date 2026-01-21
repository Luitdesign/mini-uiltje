<?php
require_once __DIR__ . '/../app/bootstrap.php';

// Simple installer: creates tables and first user.

$schemaFile = __DIR__ . '/../sql/schema.sql';

$ok = false;
$error = '';

function table_exists(PDO $db, string $name): bool {
    $stmt = $db->prepare('SHOW TABLES LIKE :t');
    $stmt->execute([':t' => $name]);
    return (bool)$stmt->fetch();
}

function column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return ((int)$stmt->fetchColumn() > 0);
}

function index_exists(PDO $db, string $table, string $index): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
    );
    $stmt->execute([':table' => $table, ':index' => $index]);
    return ((int)$stmt->fetchColumn() > 0);
}

function constraint_exists(PDO $db, string $table, string $constraint): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = :table AND constraint_name = :constraint'
    );
    $stmt->execute([':table' => $table, ':constraint' => $constraint]);
    return ((int)$stmt->fetchColumn() > 0);
}

function ensure_transaction_extensions(PDO $db): void {
    if (!table_exists($db, 'transactions')) {
        return;
    }

    if (!column_exists($db, 'transactions', 'import_batch_id')) {
        $db->exec('ALTER TABLE transactions ADD COLUMN import_batch_id INT UNSIGNED NULL AFTER import_id');
    }
    if (!column_exists($db, 'transactions', 'rule_auto_id')) {
        $db->exec('ALTER TABLE transactions ADD COLUMN rule_auto_id INT UNSIGNED NULL AFTER category_auto_id');
    }
    if (!column_exists($db, 'transactions', 'auto_reason')) {
        $db->exec('ALTER TABLE transactions ADD COLUMN auto_reason VARCHAR(255) NULL AFTER rule_auto_id');
    }

    if (!index_exists($db, 'transactions', 'idx_transactions_import_batch')) {
        $db->exec('ALTER TABLE transactions ADD KEY idx_transactions_import_batch (import_batch_id)');
    }
    if (!index_exists($db, 'transactions', 'idx_transactions_rule_auto')) {
        $db->exec('ALTER TABLE transactions ADD KEY idx_transactions_rule_auto (rule_auto_id)');
    }

    if (!constraint_exists($db, 'transactions', 'fk_transactions_import_batch')) {
        $db->exec(
            'ALTER TABLE transactions ADD CONSTRAINT fk_transactions_import_batch FOREIGN KEY (import_batch_id) REFERENCES imports(id) ON DELETE SET NULL'
        );
    }
}

function has_any_users(PDO $db): bool {
    if (!table_exists($db, 'users')) return false;
    try {
        $stmt = $db->query('SELECT COUNT(*) AS c FROM users');
        $row = $stmt->fetch();
        return ((int)($row['c'] ?? 0) > 0);
    } catch (Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!file_exists($schemaFile)) {
        $error = 'Missing sql/schema.sql.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter a username and password.';
    } else {
        try {
            $sql = file_get_contents($schemaFile);
            if ($sql === false) throw new RuntimeException('Could not read schema file.');

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

            ensure_transaction_extensions($db);

            if (!has_any_users($db)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users(username, password_hash) VALUES(:u, :p)');
                $stmt->execute([':u' => $username, ':p' => $hash]);
            }

            $ok = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

render_header('Install');
?>

<div class="card" style="max-width: 720px; margin: 30px auto;">
  <h1>Install</h1>
  <p class="small">
    This page creates the database tables and the first user.<br>
    After success, <strong>delete <code>public/install.php</code></strong>.
  </p>

  <?php if ($ok): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ Installed. You can now <a href="/login.php">log in</a>.
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <h2>1) Create tables</h2>
  <p class="small">Schema file: <code>sql/schema.sql</code></p>

  <h2>2) Create first user</h2>

  <?php if (has_any_users($db)): ?>
    <p class="small">A user already exists. If you want to reset, drop the tables and run install again.</p>
  <?php endif; ?>

  <form method="post" action="/install.php">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">

    <div class="grid-2" style="margin-bottom: 12px;">
      <div>
        <label>Admin username</label>
        <input class="input" name="username" value="admin" required>
      </div>
      <div>
        <label>Password</label>
        <input class="input" type="password" name="password" required>
      </div>
    </div>

    <button class="btn" type="submit">Run install</button>
  </form>
</div>

<?php render_footer(); ?>
