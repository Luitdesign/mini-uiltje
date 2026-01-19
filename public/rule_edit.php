<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
$ruleId = (int)($_GET['id'] ?? 0);
$info = '';
$error = '';

$matchOptions = [
    '' => '—',
    'contains' => 'Contains',
    'starts' => 'Starts with',
    'equals' => 'Equals',
];

$categories = repo_list_categories($db);

$rule = null;
if ($ruleId > 0) {
    $rule = repo_find_rule($db, $userId, $ruleId);
    if (!$rule) {
        $error = 'Rule not found.';
        $ruleId = 0;
    }
}

if (!$rule) {
    $rule = [
        'id' => 0,
        'active' => 1,
        'priority' => repo_get_max_priority($db, $userId) + 1,
        'name' => '',
        'from_text' => '',
        'from_text_match' => '',
        'from_iban' => '',
        'mededelingen_text' => '',
        'mededelingen_match' => '',
        'rekening_equals' => '',
        'amount_min' => '',
        'amount_max' => '',
        'target_category_id' => '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $name = trim((string)($_POST['name'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? ''));
    $targetCategoryId = (int)($_POST['target_category_id'] ?? 0);

    if ($name === '') {
        $error = 'Name is required.';
    } elseif ($targetCategoryId <= 0) {
        $error = 'Category is required.';
    } else {
        $data = [
            'name' => $name,
            'priority' => $priority === '' ? null : (int)$priority,
            'active' => isset($_POST['active']) ? 1 : 0,
            'from_text' => trim((string)($_POST['from_text'] ?? '')),
            'from_text_match' => (string)($_POST['from_text_match'] ?? ''),
            'from_iban' => trim((string)($_POST['from_iban'] ?? '')),
            'mededelingen_text' => trim((string)($_POST['mededelingen_text'] ?? '')),
            'mededelingen_match' => (string)($_POST['mededelingen_match'] ?? ''),
            'rekening_equals' => trim((string)($_POST['rekening_equals'] ?? '')),
            'amount_min' => trim((string)($_POST['amount_min'] ?? '')),
            'amount_max' => trim((string)($_POST['amount_max'] ?? '')),
            'target_category_id' => $targetCategoryId,
        ];

        if ($ruleId > 0) {
            repo_update_rule($db, $userId, $ruleId, $data);
            redirect('/rules.php?saved=updated');
        }

        repo_create_rule($db, $userId, $data);
        redirect('/rules.php?saved=created');
    }

    $rule = array_merge($rule, $data ?? []);
}

render_header($ruleId > 0 ? 'Edit Rule' : 'New Rule', 'rules');
?>

<div class="card" style="max-width: 860px; margin: 0 auto;">
  <div class="row" style="justify-content: space-between; align-items: center;">
    <h1><?= $ruleId > 0 ? 'Edit Rule' : 'New Rule' ?></h1>
    <a class="btn" href="/rules.php">Back to rules</a>
  </div>

  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($categories)): ?>
    <div class="card" style="border-color: var(--warning); background: rgba(250,204,21,0.08);">
      You need at least one category before creating rules.
    </div>
  <?php endif; ?>

  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">

    <div class="grid-2">
      <div>
        <label>Name</label>
        <input class="input" name="name" value="<?= h((string)$rule['name']) ?>" required>
      </div>
      <div>
        <label>Priority</label>
        <input class="input" type="number" name="priority" value="<?= h((string)$rule['priority']) ?>" min="0">
      </div>
    </div>

    <div style="margin-top: 12px;">
      <label>
        <input type="checkbox" name="active" value="1" <?= !empty($rule['active']) ? 'checked' : '' ?>>
        Active
      </label>
    </div>

    <h3 style="margin-top: 20px;">Conditions</h3>

    <div class="grid-2">
      <div>
        <label>From text</label>
        <input class="input" name="from_text" value="<?= h((string)$rule['from_text']) ?>">
      </div>
      <div>
        <label>From text match</label>
        <select class="input" name="from_text_match">
          <?php foreach ($matchOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= ((string)$rule['from_text_match'] === (string)$value) ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid-2" style="margin-top: 12px;">
      <div>
        <label>From IBAN</label>
        <input class="input" name="from_iban" value="<?= h((string)$rule['from_iban']) ?>">
      </div>
      <div>
        <label>Rekening equals</label>
        <input class="input" name="rekening_equals" value="<?= h((string)$rule['rekening_equals']) ?>">
      </div>
    </div>

    <div class="grid-2" style="margin-top: 12px;">
      <div>
        <label>Mededelingen text</label>
        <input class="input" name="mededelingen_text" value="<?= h((string)$rule['mededelingen_text']) ?>">
      </div>
      <div>
        <label>Mededelingen match</label>
        <select class="input" name="mededelingen_match">
          <?php foreach ($matchOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= ((string)$rule['mededelingen_match'] === (string)$value) ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid-2" style="margin-top: 12px;">
      <div>
        <label>Minimum amount</label>
        <input class="input" type="number" step="0.01" name="amount_min" value="<?= h((string)$rule['amount_min']) ?>">
      </div>
      <div>
        <label>Maximum amount</label>
        <input class="input" type="number" step="0.01" name="amount_max" value="<?= h((string)$rule['amount_max']) ?>">
      </div>
    </div>

    <div style="margin-top: 12px;">
      <label>Target category</label>
      <select class="input" name="target_category_id" required>
        <option value="">Select a category</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= h((string)$cat['id']) ?>" <?= ((string)$rule['target_category_id'] === (string)$cat['id']) ? 'selected' : '' ?>>
            <?= h($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="row" style="margin-top: 18px; gap: 10px;">
      <button class="btn primary" type="submit" <?= empty($categories) ? 'disabled' : '' ?>>Save rule</button>
      <a class="btn" href="/rules.php">Cancel</a>
    </div>
  </form>
</div>

<?php render_footer(); ?>
