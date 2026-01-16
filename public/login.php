<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/repo.php';

if (!db_has_tables()) {
    // still allow access but suggest install
}

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    try {
        if (login($email, $password)) {
            redirect('/dashboard.php');
        }
        $error = 'Invalid credentials.';
    } catch (Throwable $e) {
        $error = 'Login failed: ' . $e->getMessage();
    }
}

render_header('Login');
?>
<div class="card" style="max-width:520px;margin:0 auto">
  <h2>Login</h2>
  <?php if ($error): ?><div class="error"><?=h($error)?></div><?php endif; ?>
  <form method="post">
    <?=csrf_field()?>
    <div style="margin-bottom:10px">
      <label>Email</label>
      <input type="email" name="email" required>
    </div>
    <div style="margin-bottom:10px">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <button class="btn primary" type="submit">Login</button>
  </form>
  <div class="footer" style="margin-top:14px">
    <?php if (!db_has_tables()): ?>
      <div class="error">Database tables not found. If this is the first run, go to <a href="/install.php">/install.php</a>.</div>
    <?php else: ?>
      <span class="muted">Version <?=h(app_version())?></span>
    <?php endif; ?>
  </div>
</div>
<?php render_footer();
