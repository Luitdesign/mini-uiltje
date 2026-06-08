<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$savingId = (int)($_GET['id'] ?? 0);
$saving = $savingId > 0 ? repo_find_saving_with_balance($db, $savingId) : null;
$entries = $saving ? repo_list_savings_entries($db, (int)$saving['id']) : [];

$topupCount = 0;
$transactionCount = 0;
$topupTotal = 0.0;
$transactionTotal = 0.0;

foreach ($entries as $entry) {
    $amount = (float)($entry['amount'] ?? 0);
    if ((string)($entry['entry_type'] ?? '') === 'topup') {
        $topupCount++;
        $topupTotal += $amount;
    } else {
        $transactionCount++;
        $transactionTotal += $amount;
    }
}

render_header('Ledger transactions', 'savings');
?>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center;">
    <div>
      <h1>Ledger transactions</h1>
      <?php if ($saving): ?>
        <p class="small">All transactions and top-ups connected to <?= h((string)$saving['name']) ?>.</p>
      <?php else: ?>
        <p class="small">Select a valid savings ledger.</p>
      <?php endif; ?>
    </div>
    <a class="btn" href="<?= $saving ? '/savings_view.php?id=' . (int)$saving['id'] : '/savings.php' ?>">
      <?= $saving ? 'Back to saving' : 'Back to savings' ?>
    </a>
  </div>

  <?php if (!$saving): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      Saving not found.
    </div>
  <?php else: ?>
    <div class="grid-2" style="margin-top: 12px;">
      <div class="card">
        <div class="small">Transactions</div>
        <div style="font-size: 22px; font-weight: 700; margin-top: 6px;"><?= number_format($transactionCount) ?></div>
        <div class="money <?= $transactionTotal >= 0 ? 'money-pos' : 'money-neg' ?> small" style="margin-top: 6px;">
          Net: <?= number_format($transactionTotal, 2, ',', '.') ?>
        </div>
      </div>
      <div class="card">
        <div class="small">Top-ups</div>
        <div style="font-size: 22px; font-weight: 700; margin-top: 6px;"><?= number_format($topupCount) ?></div>
        <div class="money money-pos small" style="margin-top: 6px;">
          Total: <?= number_format($topupTotal, 2, ',', '.') ?>
        </div>
      </div>
    </div>

    <table class="table" style="margin-top: 12px;">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Description</th>
          <th>Category</th>
          <th>Tags</th>
          <th>Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($entries)): ?>
          <tr><td colspan="6" class="small">No transactions or top-ups are connected to this ledger yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $entry): ?>
          <?php
            $entryAmount = (float)$entry['amount'];
            $friendlyName = trim((string)($entry['friendly_name'] ?? ''));
            $description = $friendlyName !== ''
                ? $friendlyName
                : trim((string)($entry['transaction_description'] ?? $entry['note'] ?? ''));
            $entryType = (string)($entry['entry_type'] ?? '');
            $entryTypeLabel = $entryType === 'topup' ? 'Top-up' : 'Transaction';
            $currency = trim((string)($entry['currency'] ?? 'EUR'));
          ?>
          <tr>
            <td><?= h((string)$entry['date']) ?></td>
            <td><span class="badge <?= $entryType === 'topup' ? 'badge-savings' : '' ?>"><?= h($entryTypeLabel) ?></span></td>
            <td><?= h($description !== '' ? $description : '—') ?></td>
            <td><?= h(trim((string)($entry['category_name'] ?? '')) ?: '—') ?></td>
            <td><?= h(trim((string)($entry['tag'] ?? '')) ?: '—') ?></td>
            <td class="money <?= $entryAmount >= 0 ? 'money-pos' : 'money-neg' ?>">
              <?= number_format($entryAmount, 2, ',', '.') ?> <?= h($currency !== '' ? $currency : 'EUR') ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
