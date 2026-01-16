<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/ui.php';
require_login();

$months = list_months();
$m = $_GET['m'] ?? ($months[0] ?? date('Y-m'));
$mode = $_GET['mode'] ?? 'all'; // all|review|confirmed
if (!in_array($mode, ['all','review','confirmed'], true)) { $mode = 'all'; }
$columnOptions = [
    'date' => 'Date',
    'description' => 'Description',
    'amount' => 'Amount',
    'manual' => 'Manual category',
    'auto' => 'Auto',
    'final' => 'Final',
    'confirmed' => 'Confirmed',
];
$selectedColumns = array_keys($columnOptions);
$colsInput = $_GET['cols'] ?? $_POST['cols'] ?? null;
if ($colsInput !== null) {
    $requested = $colsInput;
    if (!is_array($requested)) {
        $requested = array_filter(array_map('trim', explode(',', (string)$requested)));
    }
    $requested = array_values(array_intersect((array)$requested, array_keys($columnOptions)));
    if (!empty($requested)) {
        $selectedColumns = $requested;
    }
}
$selectedColumnSet = array_flip($selectedColumns);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $month = (string)($_POST['m'] ?? $m);
    $redirectQuery = http_build_query([
        'm' => $month,
        'mode' => (string)($_POST['mode'] ?? $mode),
        'cols' => $selectedColumns,
    ]);
    if ($action === 'set_category') {
        $tid = (int)($_POST['tx_id'] ?? 0);
        $cat = $_POST['manual_category_id'] ?? '';
        $catId = $cat === '' ? null : (int)$cat;
        update_manual_category($tid, $catId, true);
        flash_set('Saved.', 'info');
        redirect('/month.php?' . $redirectQuery);
    } elseif ($action === 'confirm') {
        $tid = (int)($_POST['tx_id'] ?? 0);
        confirm_transaction($tid);
        flash_set('Confirmed.', 'info');
        redirect('/month.php?' . $redirectQuery);
    }
}

$cats = categories_for_select();
$tx = fetch_transactions_for_month((string)$m, (string)$mode);
$counts = get_month_summary_counts((string)$m);
render_header('Transactions');
?>
<div class="card">
  <div class="row" style="align-items:end">
    <div class="col">
      <h2>Transactions <?=h((string)$m)?></h2>
      <div class="small muted"><?=h((string)$counts['total'])?> total • <?=h((string)$counts['needs_review'])?> need review</div>
    </div>
    <div class="col" style="max-width:260px">
      <form method="get">
        <label>Month</label>
        <select name="m" onchange="this.form.submit()">
          <?php foreach ($months as $mm): ?>
            <option value="<?=h($mm)?>" <?= $mm===$m ? 'selected' : '' ?>><?=h($mm)?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="mode" value="<?=h((string)$mode)?>">
        <input type="hidden" name="cols" value="<?=h(implode(',', $selectedColumns))?>">
      </form>
    </div>
  </div>

  <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn <?= $mode==='all'?'primary':'' ?>" href="/month.php?<?=h(http_build_query(['m' => $m, 'mode' => 'all', 'cols' => $selectedColumns]))?>">All</a>
    <a class="btn <?= $mode==='review'?'primary':'' ?>" href="/month.php?<?=h(http_build_query(['m' => $m, 'mode' => 'review', 'cols' => $selectedColumns]))?>">Needs review</a>
    <a class="btn <?= $mode==='confirmed'?'primary':'' ?>" href="/month.php?<?=h(http_build_query(['m' => $m, 'mode' => 'confirmed', 'cols' => $selectedColumns]))?>">Confirmed</a>
    <a class="btn" href="/results.php?m=<?=h((string)$m)?>">Results</a>
  </div>

  <form method="get" style="margin-top:12px">
    <input type="hidden" name="m" value="<?=h((string)$m)?>">
    <input type="hidden" name="mode" value="<?=h((string)$mode)?>">
    <div class="small" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:6px 12px;">
      <?php foreach ($columnOptions as $key => $label): ?>
        <label style="display:inline-flex;gap:6px;align-items:center;">
          <input type="checkbox" name="cols[]" value="<?=h($key)?>" <?= isset($selectedColumnSet[$key]) ? 'checked' : '' ?>>
          <span><?=h($label)?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:8px"><button class="btn" type="submit">Apply columns</button></div>
  </form>

  <div style="margin-top:14px">
    <?php if (!$tx): ?>
      <p class="muted">No transactions.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <?php if (isset($selectedColumnSet['date'])): ?>
              <th>Date</th>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['description'])): ?>
              <th>Description</th>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['amount'])): ?>
              <th>Amount</th>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['manual'])): ?>
              <th>Manual category</th>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['auto'])): ?>
              <th>Auto</th>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['final'])): ?>
              <th>Final</th>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['confirmed'])): ?>
              <th>Confirmed</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tx as $t): ?>
          <tr>
            <?php if (isset($selectedColumnSet['date'])): ?>
              <td><?=h($t['tx_date'])?></td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['description'])): ?>
              <td>
                <div><strong><?=h($t['name_description'])?></strong></div>
                <div class="small muted"><?=h($t['mutation_type'] ?? '')?> <?=h($t['code'] ?? '')?></div>
                <div class="small muted"><?=h($t['counterparty_iban'] ?? '')?></div>
              </td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['amount'])): ?>
              <td><?=h(number_format((float)$t['amount_signed'], 2, ',', '.'))?></td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['manual'])): ?>
              <td>
                <form method="post">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="set_category">
                  <input type="hidden" name="tx_id" value="<?=h((string)$t['id'])?>">
                  <input type="hidden" name="m" value="<?=h((string)$m)?>">
                  <input type="hidden" name="mode" value="<?=h((string)$mode)?>">
                  <input type="hidden" name="cols" value="<?=h(implode(',', $selectedColumns))?>">
                  <?=render_category_select('manual_category_id', $cats, $t['manual_category_id'] ? (int)$t['manual_category_id'] : null)?>
                  <div style="margin-top:6px"><button class="btn primary" type="submit">Save</button></div>
                </form>
              </td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['auto'])): ?>
              <td class="small"><?=h($t['auto_category_name'] ?? '')?></td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['final'])): ?>
              <td class="small"><?=h($t['final_category_name'] ?? '')?></td>
            <?php endif; ?>
            <?php if (isset($selectedColumnSet['confirmed'])): ?>
              <td>
                <?php if ((int)$t['is_confirmed'] === 1): ?>
                  <span class="badge">Yes</span>
                <?php else: ?>
                  <form method="post">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="tx_id" value="<?=h((string)$t['id'])?>">
                    <input type="hidden" name="m" value="<?=h((string)$m)?>">
                    <input type="hidden" name="mode" value="<?=h((string)$mode)?>">
                    <input type="hidden" name="cols" value="<?=h(implode(',', $selectedColumns))?>">
                    <button class="btn" type="submit">Confirm</button>
                  </form>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php render_footer();
