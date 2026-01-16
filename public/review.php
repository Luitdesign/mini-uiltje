<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/repo.php';
require_once __DIR__ . '/../src/ui.php';
require_login();

$months = list_months();
$m = $_GET['m'] ?? ($months[0] ?? date('Y-m'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'set_category') {
        $tid = (int)($_POST['tx_id'] ?? 0);
        $cat = $_POST['manual_category_id'] ?? '';
        $catId = $cat === '' ? null : (int)$cat;
        update_manual_category($tid, $catId, true);
        flash_set('Saved.', 'info');
        redirect('/review.php?m=' . urlencode((string)($_POST['m'] ?? $m)));
    } elseif ($action === 'accept_auto') {
        $tid = (int)($_POST['tx_id'] ?? 0);
        confirm_transaction($tid);
        flash_set('Auto category accepted.', 'info');
        redirect('/review.php?m=' . urlencode((string)($_POST['m'] ?? $m)));
    } elseif ($action === 'bulk_confirm') {
        $month = (string)($_POST['m'] ?? $m);
        $n = confirm_all_in_month($month);
        flash_set("Confirmed $n transactions in $month.", 'info');
        redirect('/review.php?m=' . urlencode($month));
    }
}

$cats = categories_for_select();
$tx = fetch_transactions_for_month((string)$m, 'review');

render_header('Review');
?>
<div class="card">
  <div class="row" style="align-items:end">
    <div class="col">
      <h2>Review queue</h2>
      <p class="muted">Transactions that are not confirmed or have no category.</p>
    </div>
    <div class="col" style="max-width:260px">
      <form method="get">
        <label>Month</label>
        <select name="m" onchange="this.form.submit()">
          <?php foreach ($months as $mm): ?>
            <option value="<?=h($mm)?>" <?= $mm===$m ? 'selected' : '' ?>><?=h($mm)?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <form method="post" style="margin-top:12px">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="bulk_confirm">
    <input type="hidden" name="m" value="<?=h((string)$m)?>">
    <button class="btn" type="submit">Bulk confirm all in this month</button>
  </form>

  <div style="margin-top:14px">
    <?php if (!$tx): ?>
      <p class="muted">Nothing to review for this month 🎉</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Amount</th>
            <th>Auto</th>
            <th>Manual</th>
            <th>Final</th>
            <th>Confirm</th>
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
            </td>
            <td>
              <form method="post">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="set_category">
                <input type="hidden" name="tx_id" value="<?=h((string)$t['id'])?>">
                <input type="hidden" name="m" value="<?=h((string)$m)?>">
                <?=render_category_select('manual_category_id', $cats, $t['manual_category_id'] ? (int)$t['manual_category_id'] : null)?>
                <div style="margin-top:6px;display:flex;gap:8px">
                  <button class="btn primary" type="submit">Save</button>
                </div>
              </form>
            </td>
            <td class="small"><?=h($t['final_category_name'] ?? '')?></td>
            <td>
              <?php if (!empty($t['auto_category_id']) && empty($t['manual_category_id'])): ?>
                <form method="post">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="accept_auto">
                  <input type="hidden" name="tx_id" value="<?=h((string)$t['id'])?>">
                  <input type="hidden" name="m" value="<?=h((string)$m)?>">
                  <button class="btn" type="submit">Accept auto</button>
                </form>
              <?php else: ?>
                <span class="small muted">—</span>
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
