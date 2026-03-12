<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_data_user_id($db);

$okMsg = '';
$errMsg = '';

$stmtLatestImport = $db->prepare(
    'SELECT imported_at
     FROM imports
     WHERE user_id = :uid
     ORDER BY imported_at DESC
     LIMIT 1'
);
$stmtLatestImport->execute([':uid' => $userId]);
$latestImportAt = $stmtLatestImport->fetchColumn();

$stmtLatestTxn = $db->prepare(
    'SELECT MAX(txn_date)
     FROM transactions
     WHERE user_id = :uid
       AND import_id IS NOT NULL'
);
$stmtLatestTxn->execute([':uid' => $userId]);
$latestUploadedTxnDate = $stmtLatestTxn->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    if (empty($_FILES['csv']['tmp_name'])) {
        $errMsg = 'Please choose a CSV file.';
    } else {
        $tmp = (string)$_FILES['csv']['tmp_name'];
        $name = (string)($_FILES['csv']['name'] ?? 'upload.csv');

        try {
            $result = ing_import_csv($db, $userId, $tmp, $name);
            $okMsg = "Imported. Inserted {$result['inserted']}, skipped {$result['skipped']} (duplicates/invalid).";
        } catch (Throwable $e) {
            $errMsg = $e->getMessage();
        }
    }
}

render_header('Upload', 'upload');
?>

<div class="card">
  <h1>Upload CSV</h1>
  <p class="small">Upload an ING CSV export (semicolon separated). Import is idempotent: duplicates are skipped.</p>

  <div class="small" style="margin-bottom: 12px;">
    <div>Latest file upload: <strong><?= h($latestImportAt !== false && $latestImportAt !== null ? (string)$latestImportAt : '—') ?></strong></div>
    <div>Latest uploaded transaction date: <strong><?= h($latestUploadedTxnDate !== false && $latestUploadedTxnDate !== null ? (string)$latestUploadedTxnDate : '—') ?></strong></div>
  </div>

  <?php if ($okMsg !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ <?= h($okMsg) ?>
      <div class="small" style="margin-top: 8px;">
        Go to <a href="/overview.php">Months</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($errMsg !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($errMsg) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/upload.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">

    <div style="margin-bottom: 12px;">
      <label>Select CSV file</label>
      <input class="input" type="file" name="csv" accept=".csv,text/csv" required>
    </div>

    <button class="btn" type="submit">Import</button>
  </form>
</div>

<?php render_footer(); ?>
