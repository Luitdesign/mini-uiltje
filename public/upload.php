<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

$okMsg = '';
$errMsg = '';

function parse_ini_size(?string $value): ?int {
    if ($value === null) return null;
    $value = trim($value);
    if ($value === '') return null;
    if (!preg_match('/^(\d+)([kKmMgG])?$/', $value, $matches)) return null;
    $size = (int)$matches[1];
    $unit = strtolower($matches[2] ?? '');
    return match ($unit) {
        'g' => $size * 1024 * 1024 * 1024,
        'm' => $size * 1024 * 1024,
        'k' => $size * 1024,
        default => $size,
    };
}

function upload_max_bytes(): ?int {
    $uploadMax = parse_ini_size(ini_get('upload_max_filesize'));
    $postMax = parse_ini_size(ini_get('post_max_size'));
    if ($uploadMax === null) return $postMax;
    if ($postMax === null) return $uploadMax;
    return min($uploadMax, $postMax);
}

function format_bytes(?int $bytes): ?string {
    if ($bytes === null || $bytes <= 0) return null;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return sprintf('%.1f %s', $size, $units[$unit]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $file = $_FILES['csv'] ?? null;
    $fileErr = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fileErr !== UPLOAD_ERR_OK) {
        $maxBytes = upload_max_bytes();
        $maxText = format_bytes($maxBytes);
        $errMsg = match ($fileErr) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $maxText
                ? "File is too large. Maximum upload size is {$maxText}."
                : 'File is too large for the current upload limits.',
            UPLOAD_ERR_PARTIAL => 'File upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a CSV file.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Server error: upload stopped by a PHP extension.',
            default => 'Upload failed. Please try again.',
        };
    } elseif (empty($file['tmp_name'])) {
        $errMsg = 'Please choose a CSV file.';
    } else {
        $tmp = (string)$file['tmp_name'];
        $name = (string)($file['name'] ?? 'upload.csv');

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

  <?php if ($okMsg !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ <?= h($okMsg) ?>
      <div class="small" style="margin-top: 8px;">
        Go to <a href="/months.php">Months</a>
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
