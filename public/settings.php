<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

render_header('Settings', 'settings');
?>

<div class="card" style="max-width: 720px; margin: 20px auto;">
  <h1>Settings</h1>
  <p class="small">Quick links for maintenance and admin tasks.</p>
  <div class="row" style="gap: 10px; margin-top: 12px;">
    <a class="btn" href="/rules.php">Rules</a>
    <a class="btn" href="/db-check.php">DB Check</a>
    <a class="btn" href="/schema.php">Schema</a>
    <a class="btn btn-danger" href="/reset.php">Reset DB</a>
    <a class="btn" href="/logout.php">Logout</a>
  </div>
</div>

<?php render_footer(); ?>
