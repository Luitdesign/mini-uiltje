<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
$info = '';
$error = '';

if (isset($_GET['saved'])) {
    $info = 'Category mappings saved.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $mapping = $_POST['category_pot'] ?? [];
    if (!is_array($mapping)) {
        $mapping = [];
    }
    try {
        repo_bulk_set_category_pots($db, $userId, $mapping);
        redirect('/pots_categories.php?saved=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$categories = repo_list_categories($db);
$pots = repo_list_pots($db, $userId);
$potMap = repo_get_category_pot_map($db, $userId);

render_header('Pot Categories', 'pots-categories');
?>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center; gap: 12px;">
    <div>
      <h1>Pot categories</h1>
      <p class="small">Assign each category to a pot.</p>
    </div>
    <div class="row" style="gap: 8px; flex-wrap: wrap;">
      <a class="btn" href="/pots.php">Back to pots</a>
    </div>
  </div>

  <?php if ($info !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ <?= h($info) ?>
    </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($categories)): ?>
    <div class="small muted">No categories available.</div>
  <?php else: ?>
    <form method="post" action="/pots_categories.php">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
      <table class="table" style="margin-top: 12px;">
        <thead>
          <tr>
            <th>Category</th>
            <th style="width: 260px;">Pot</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $category): ?>
            <?php $categoryId = (int)$category['id']; ?>
            <tr>
              <td><?= h($category['name']) ?></td>
              <td>
                <select class="input" name="category_pot[<?= h((string)$categoryId) ?>]">
                  <option value="">—</option>
                  <?php foreach ($pots as $pot): ?>
                    <option value="<?= h((string)$pot['id']) ?>" <?= ((int)($potMap[$categoryId] ?? 0) === (int)$pot['id']) ? 'selected' : '' ?>>
                      <?= h($pot['name']) ?><?= !empty($pot['archived']) ? ' (archived)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="row" style="margin-top: 16px;">
        <button class="btn primary" type="submit">Save mappings</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
