<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/repo.php';
require_login();

$err = '';
$res = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Please select a CSV file.';
    } else {
        $file = $_FILES['csv'];
        $tmp = $file['tmp_name'];
        $name = $file['name'];
        try {
            $u = current_user();
            $res = import_ing_file($tmp, $name, (int)$u['id']);
            flash_set("Import done. Inserted {$res['inserted']} tx, skipped {$res['duplicates']} duplicates.", 'info');
            redirect('/dashboard.php');
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

render_header('Upload');
?>
<div class="card" style="max-width:720px">
  <h2>Upload ING transactions CSV</h2>
  <p class="muted">Export "Transactions" from ING (not the balance export).</p>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?>
    <div style="margin-bottom:10px">
      <label>CSV file</label>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </div>
    <button class="btn primary" type="submit">Import</button>
  </form>
</div>
<?php render_footer();
