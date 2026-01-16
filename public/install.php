<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/repo.php';

// If already installed (any user exists), block install.
try {
    if (db_has_tables()) {
        $c = db()->query('SELECT COUNT(*) AS c FROM users')->fetch();
        if ($c && (int)$c['c'] > 0) {
            redirect('/login.php');
        }
    }
} catch (Throwable $e) {
    // likely DB not created or no permissions
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if ($email === '' || $pass === '') {
        $err = 'Email and password are required.';
    } else {
        try {
            $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Missing schema.sql');
            }
            // Split statements on ; newline (good enough for this schema)
            $stmts = preg_split('/;\s*\n/', $schema);
            db()->beginTransaction();
            foreach ($stmts as $s) {
                $s = trim($s);
                if ($s === '' || str_starts_with($s, '--')) continue;
                db()->exec($s);
            }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $ins = db()->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, \"admin\")');
            $ins->execute([$email, $hash]);
            db()->commit();

            flash_set('Installed. You can now log in.', 'info');
            redirect('/login.php');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $err = 'Install failed: ' . $e->getMessage();
        }
    }
}

render_header('Install');
?>
<div class="card">
  <h2>Install Mini Uiltje</h2>
  <p class="muted">This will create database tables and create the first admin user.</p>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>
  <form method="post">
    <?=csrf_field()?>
    <div class="row">
      <div class="col">
        <label>Admin email</label>
        <input name="email" type="email" required>
      </div>
      <div class="col">
        <label>Admin password</label>
        <input name="password" type="password" required>
      </div>
    </div>
    <div style="margin-top:12px">
      <button class="btn primary" type="submit">Install</button>
      <a class="btn" href="/login.php">Go to login</a>
    </div>
  </form>
  <p class="small muted" style="margin-top:12px">If you get a DB error, check config/config.php and make sure the database and user exist.</p>
</div>
<?php render_footer();
