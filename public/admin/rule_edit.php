<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/repo.php';
require_once __DIR__ . '/../../src/rules_repo.php';
require_once __DIR__ . '/../../src/rules_engine.php';
require_once __DIR__ . '/../../src/ui.php';
require_login();
require_admin();

$fields = rule_match_fields();
$ops = [
    'contains' => 'contains',
    'starts_with' => 'starts with',
    'equals' => 'equals',
    'regex' => 'regex',
];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $id > 0;
$rule = $editing ? get_rule($id) : null;
$errors = [];

$maxPosition = count_rules() + ($editing ? 0 : 1);

$form = [
    'is_active' => $rule ? (int)$rule['is_active'] : 1,
    'position' => $rule ? (int)$rule['position'] : $maxPosition,
    'active_from' => $rule ? (string)$rule['active_from'] : date('Y-m-d'),
    'match_field' => $rule ? (string)$rule['match_field'] : array_key_first($fields),
    'match_op' => $rule ? (string)$rule['match_op'] : 'contains',
    'match_value' => $rule ? (string)$rule['match_value'] : '',
    'category_id' => $rule ? (int)$rule['category_id'] : 0,
];

$testResults = null;
$applyPreview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'save';

    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $form['position'] = (int)($_POST['position'] ?? $form['position']);
    $form['active_from'] = trim((string)($_POST['active_from'] ?? ''));
    $form['match_field'] = (string)($_POST['match_field'] ?? $form['match_field']);
    $form['match_op'] = (string)($_POST['match_op'] ?? $form['match_op']);
    $form['match_value'] = trim((string)($_POST['match_value'] ?? ''));
    $form['category_id'] = (int)($_POST['category_id'] ?? 0);

    if ($form['active_from'] === '') {
        $errors[] = 'Active from date is required.';
    }
    if ($form['match_value'] === '') {
        $errors[] = 'Match value is required.';
    }
    if (!isset($fields[$form['match_field']])) {
        $errors[] = 'Match field is invalid.';
    }
    if (!isset($ops[$form['match_op']])) {
        $errors[] = 'Match operation is invalid.';
    }
    if ($form['category_id'] <= 0) {
        $errors[] = 'Category is required.';
    }
    if ($form['position'] < 1 || $form['position'] > $maxPosition) {
        $errors[] = 'Position must be between 1 and ' . $maxPosition . '.';
    }
    if ($form['match_op'] === 'regex' && $form['match_value'] !== '' && !rules_regex_is_valid($form['match_value'])) {
        $errors[] = 'Regex pattern is invalid.';
    }

    if ($errors === []) {
        if ($action === 'save') {
            if ($editing) {
                update_rule($id, $form);
                flash_set('Rule updated.', 'info');
            } else {
                $id = create_rule($form);
                flash_set('Rule created.', 'info');
            }
            redirect('/admin/rules.php');
        } elseif ($action === 'test_rule') {
            $testMonth = (string)($_POST['test_month'] ?? '');
            if ($testMonth === '') {
                $errors[] = 'Select a month to test.';
            } else {
                $stmt = db()->prepare("SELECT * FROM transactions WHERE DATE_FORMAT(tx_date,'%Y-%m') = ? ORDER BY tx_date DESC, id DESC");
                $stmt->execute([$testMonth]);
                $rows = $stmt->fetchAll();
                $matches = [];
                $count = 0;
                foreach ($rows as $row) {
                    if (match_rule($form, $row)) {
                        $count++;
                        if (count($matches) < 10) {
                            $matches[] = $row;
                        }
                    }
                }
                $testResults = [
                    'month' => $testMonth,
                    'count' => $count,
                    'matches' => $matches,
                ];
            }
        } elseif ($action === 'preview_apply' && $editing) {
            $applyPreview = preview_apply_rules_from_active_date($id);
        } elseif ($action === 'apply_from_active' && $editing) {
            $updated = apply_rules_from_active_date($id);
            flash_set("Applied rules from active_from. Updated $updated transactions.", 'info');
            redirect('/admin/rule_edit.php?id=' . urlencode((string)$id));
        }
    }
}

$categories = categories_for_select();
$months = list_months();

render_header($editing ? 'Edit rule' : 'Add rule');
?>
<div class="card">
  <h2><?= $editing ? 'Edit rule' : 'Add rule' ?></h2>
  <p class="muted">Rules match on a single field. Matching ignores case and all whitespace.</p>

  <?php if ($errors): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?=h($e)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <?=csrf_field()?>
    <?php if ($editing): ?>
      <input type="hidden" name="id" value="<?=h((string)$id)?>">
    <?php endif; ?>

    <div class="grid-2">
      <div>
        <label>Active from</label>
        <input class="input" type="date" name="active_from" value="<?=h($form['active_from'])?>" required>
      </div>
      <div>
        <label>Position (1 = highest priority)</label>
        <input class="input" type="number" name="position" min="1" max="<?=h((string)$maxPosition)?>" value="<?=h((string)$form['position'])?>" required>
      </div>
    </div>

    <div class="grid-2" style="margin-top:12px">
      <div>
        <label>Match field</label>
        <select name="match_field">
          <?php foreach ($fields as $key => $label): ?>
            <option value="<?=h($key)?>" <?= $form['match_field'] === $key ? 'selected' : '' ?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Match operation</label>
        <select name="match_op">
          <?php foreach ($ops as $key => $label): ?>
            <option value="<?=h($key)?>" <?= $form['match_op'] === $key ? 'selected' : '' ?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="margin-top:12px">
      <label>Match value</label>
      <input class="input" name="match_value" value="<?=h($form['match_value'])?>" required>
      <div class="small muted" style="margin-top:6px">Regex runs against the normalized (lowercase, no whitespace) field value.</div>
    </div>

    <div style="margin-top:12px">
      <label>Category</label>
      <?=render_category_select('category_id', $categories, $form['category_id'] ?: null)?>
    </div>

    <div style="margin-top:12px">
      <label><input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>> Active</label>
    </div>

    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn primary" type="submit" name="action" value="save">Save rule</button>
      <a class="btn" href="/admin/rules.php">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Test rule</h3>
  <p class="muted">Preview matches for a month without saving.</p>
  <form method="post">
    <?=csrf_field()?>
    <?php if ($editing): ?>
      <input type="hidden" name="id" value="<?=h((string)$id)?>">
    <?php endif; ?>
    <input type="hidden" name="active_from" value="<?=h($form['active_from'])?>">
    <input type="hidden" name="position" value="<?=h((string)$form['position'])?>">
    <input type="hidden" name="match_field" value="<?=h($form['match_field'])?>">
    <input type="hidden" name="match_op" value="<?=h($form['match_op'])?>">
    <input type="hidden" name="match_value" value="<?=h($form['match_value'])?>">
    <input type="hidden" name="category_id" value="<?=h((string)$form['category_id'])?>">
    <?php if ($form['is_active']): ?>
      <input type="hidden" name="is_active" value="1">
    <?php endif; ?>

    <label>Month</label>
    <select name="test_month">
      <option value="">-- pick a month --</option>
      <?php foreach ($months as $month): ?>
        <option value="<?=h($month)?>" <?= ($testResults['month'] ?? '') === $month ? 'selected' : '' ?>><?=h($month)?></option>
      <?php endforeach; ?>
    </select>
    <div style="margin-top:10px">
      <button class="btn" type="submit" name="action" value="test_rule">Test rule</button>
    </div>
  </form>

  <?php if ($testResults): ?>
    <div style="margin-top:14px">
      <strong><?=h((string)$testResults['count'])?></strong> matches in <?=h($testResults['month'])?>.
      <?php if ($testResults['matches']): ?>
        <table class="table" style="margin-top:10px">
          <thead>
            <tr>
              <th>Date</th>
              <th>Amount</th>
              <th>Description</th>
              <th>Field</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($testResults['matches'] as $row): ?>
              <tr>
                <td><?=h($row['tx_date'])?></td>
                <td><?=h(number_format((float)$row['amount_signed'], 2, ',', '.'))?></td>
                <td><?=h($row['name_description'] ?? '')?></td>
                <td class="small"><?=h($row[$form['match_field']] ?? '')?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($editing): ?>
  <div class="card">
    <h3>Apply from active_from</h3>
    <p class="muted">Applies full rule ordering to all transactions from <?=h($form['active_from'])?> onward (manual categories are never changed). If auto category changes, confirmation resets.</p>

    <?php if ($applyPreview !== null): ?>
      <p><strong><?=h((string)$applyPreview)?></strong> transactions would change auto category.</p>
    <?php endif; ?>

    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
      <?=csrf_field()?>
      <input type="hidden" name="id" value="<?=h((string)$id)?>">
      <input type="hidden" name="active_from" value="<?=h($form['active_from'])?>">
      <input type="hidden" name="position" value="<?=h((string)$form['position'])?>">
      <input type="hidden" name="match_field" value="<?=h($form['match_field'])?>">
      <input type="hidden" name="match_op" value="<?=h($form['match_op'])?>">
      <input type="hidden" name="match_value" value="<?=h($form['match_value'])?>">
      <input type="hidden" name="category_id" value="<?=h((string)$form['category_id'])?>">
      <?php if ($form['is_active']): ?>
        <input type="hidden" name="is_active" value="1">
      <?php endif; ?>
      <button class="btn" type="submit" name="action" value="preview_apply">Preview count</button>
      <button class="btn primary" type="submit" name="action" value="apply_from_active">Apply now</button>
    </form>
  </div>
<?php endif; ?>

<?php render_footer();
