<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/rules_repo.php';
require_once __DIR__ . '/../src/ui.php';
require_login();

$months = list_months();
$m = $_GET['m'] ?? ($months[0] ?? date('Y-m'));
$mode = $_GET['mode'] ?? 'all'; // all|review|confirmed
if (!in_array($mode, ['all','review','confirmed'], true)) { $mode = 'all'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $month = (string)($_POST['m'] ?? $m);
    if ($action === 'set_category') {
        $tid = (int)($_POST['tx_id'] ?? 0);
        $cat = $_POST['manual_category_id'] ?? '';
        $catId = $cat === '' ? null : (int)$cat;
        update_manual_category($tid, $catId, true);
        flash_set('Saved.', 'info');
        redirect('/month.php?m=' . urlencode($month) . '&mode=' . urlencode((string)($_POST['mode'] ?? $mode)));
    } elseif ($action === 'accept_auto') {
        $tid = (int)($_POST['tx_id'] ?? 0);
        confirm_transaction($tid);
        flash_set('Auto category accepted.', 'info');
        redirect('/month.php?m=' . urlencode($month) . '&mode=' . urlencode((string)($_POST['mode'] ?? $mode)));
    } elseif ($action === 'recategorize_month') {
        $updated = recategorize_month_with_rules($month);
        flash_set("Re-categorized $updated transactions for $month.", 'info');
        redirect('/month.php?m=' . urlencode($month) . '&mode=' . urlencode((string)($_POST['mode'] ?? $mode)));
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
      </form>
    </div>
  </div>

  <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn <?= $mode==='all'?'primary':'' ?>" href="/month.php?m=<?=h((string)$m)?>&mode=all">All</a>
    <a class="btn <?= $mode==='review'?'primary':'' ?>" href="/month.php?m=<?=h((string)$m)?>&mode=review">Needs review</a>
    <a class="btn <?= $mode==='confirmed'?'primary':'' ?>" href="/month.php?m=<?=h((string)$m)?>&mode=confirmed">Confirmed</a>
    <a class="btn" href="/results.php?m=<?=h((string)$m)?>">Results</a>
    <form method="post" style="margin:0">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="recategorize_month">
      <input type="hidden" name="m" value="<?=h((string)$m)?>">
      <input type="hidden" name="mode" value="<?=h((string)$mode)?>">
      <button class="btn" type="submit">Re-categorize month using rules</button>
    </form>
  </div>

  <div style="margin-top:14px">
    <?php if (!$tx): ?>
      <p class="muted">No transactions.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Amount</th>
            <th>Auto category</th>
            <th>Manual category</th>
            <th>Final</th>
            <th>Confirmed</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tx as $t): ?>
          <tr>
            <td><?=h($t['tx_date'])?></td>
            <td>
              <div><strong><?=h($t['name_description'])?></strong></div>
              <div class="small muted"><?=h($t['mutation_type'] ?? '')?> <?=h($t['code'] ?? '')?></div>
              <div class="small muted"><?=h($t['counterparty_iban'] ?? '')?></div>
            </td>
            <td><?=h(number_format((float)$t['amount_signed'], 2, ',', '.'))?></td>
            <td class="small">
              <div><?=h($t['auto_category_name'] ?? '')?></div>
              <?php if (!empty($t['auto_rule_id'])): ?>
                <div class="small muted">Rule #<?=h((string)$t['auto_rule_id'])?></div>
              <?php endif; ?>
              <?php if ((int)$t['is_confirmed'] === 0 && empty($t['manual_category_id']) && !empty($t['auto_category_id'])): ?>
                <form method="post" style="margin-top:6px">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="accept_auto">
                  <input type="hidden" name="tx_id" value="<?=h((string)$t['id'])?>">
                  <input type="hidden" name="m" value="<?=h((string)$m)?>">
                  <input type="hidden" name="mode" value="<?=h((string)$mode)?>">
                  <button class="btn" type="submit">Accept auto</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <form method="post">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="set_category">
                <input type="hidden" name="tx_id" value="<?=h((string)$t['id'])?>">
                <input type="hidden" name="m" value="<?=h((string)$m)?>">
                <input type="hidden" name="mode" value="<?=h((string)$mode)?>">
                <?=render_category_select('manual_category_id', $cats, $t['manual_category_id'] ? (int)$t['manual_category_id'] : null)?>
                <div style="margin-top:6px"><button class="btn primary" type="submit">Save</button></div>
              </form>
            </td>
            <td class="small"><?=h($t['final_category_name'] ?? '')?></td>
            <td>
              <?php if ((int)$t['is_confirmed'] === 1): ?>
                <span class="badge">Yes</span>
              <?php else: ?>
                <span class="badge">No</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php render_footer();
