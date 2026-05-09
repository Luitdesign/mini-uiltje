<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

render_header('Graphs', 'graphs');
?>

<div class="card" style="max-width: 980px; margin: 20px auto;">
  <h1>Graphs</h1>
  <p class="small">Graph views will be added here soon.</p>
</div>

<?php render_footer();
