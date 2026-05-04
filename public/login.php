<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    redirect('/overview.php');
}

$error = '';
$maxAttempts = 5;
$lockoutWindowSeconds = 15 * 60;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $attempts = (int)($_SESSION['login_attempts'] ?? 0);
    $firstFailureTime = (int)($_SESSION['login_lockout_time'] ?? 0);
    $now = time();

    if ($firstFailureTime > 0 && ($now - $firstFailureTime) > $lockoutWindowSeconds) {
        $attempts = 0;
        $firstFailureTime = 0;
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_lockout_time'] = 0;
    }

    if ($attempts >= $maxAttempts && $firstFailureTime > 0 && ($now - $firstFailureTime) <= $lockoutWindowSeconds) {
        $error = 'Too many failed login attempts. Please wait 15 minutes before trying again.';
    } else if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else if (!auth_attempt_login($db, $username, $password)) {
        if ($attempts === 0 || $firstFailureTime === 0) {
            $firstFailureTime = $now;
        }

        $_SESSION['login_attempts'] = $attempts + 1;
        $_SESSION['login_lockout_time'] = $firstFailureTime;
        $error = 'Invalid credentials.';
    } else {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_lockout_time'] = 0;
        redirect('/overview.php');
    }
}

render_header('Login');
?>

<div class="card" style="max-width: 520px; margin: 40px auto;">
  <h1>Login</h1>
  <p class="small">Use the account you created in <code>/install.php</code>.</p>

  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/login.php">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">

    <div style="margin-bottom: 12px;">
      <label>Username</label>
      <input class="input" name="username" autocomplete="username" required>
    </div>

    <div style="margin-bottom: 14px;">
      <label>Password</label>
      <input class="input" type="password" name="password" autocomplete="current-password" required>
    </div>

    <button class="btn" type="submit">Login</button>
  </form>
</div>

<?php render_footer(); ?>
