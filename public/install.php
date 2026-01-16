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

            // Execute each statement separately.
            $statements = preg_split('/;\s*\n/', $sql);
            foreach ($statements as $stmtSql) {
                $stmtSql = trim($stmtSql);
                if ($stmtSql === '' || str_starts_with($stmtSql, '--')) continue;
                $db->exec($stmtSql);
            }

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
