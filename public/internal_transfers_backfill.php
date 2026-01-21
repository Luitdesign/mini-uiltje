<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
$error = '';
$updatedRows = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    try {
        $sql = "
            UPDATE transactions
            SET is_internal_transfer =
              CASE
                WHEN LOWER(description) LIKE '%van oranje spaarrekening%'
                  OR LOWER(description) LIKE '%naar oranje spaarrekening%'
                  OR LOWER(description) LIKE '%oranje spaarrekening%'
                THEN 1 ELSE 0
              END
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $updatedRows = $stmt->rowCount();
        $info = 'Backfill completed.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('Backfill Internal Transfers', 'internal-transfers-backfill');
?>

<div class="card" style="max-width: 900px; margin: 20px auto;">
  <h1>Backfill Internal Transfers</h1>
  <p class="small">
    This will mark existing transactions that contain "van oranje spaarrekening",
    "naar oranje spaarrekening", or "oranje spaarrekening" (case-insensitive) as internal transfers.
  </p>

  <?php if ($info !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ <?= h($info) ?>
      <?php if ($updatedRows !== null): ?>
        <div class="small" style="margin-top: 6px; color: var(--muted);">
          Rows updated: <?= (int)$updatedRows ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/internal_transfers_backfill.php">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <button class="btn" type="submit">Run backfill</button>
  </form>
</div>

<?php render_footer(); ?>
