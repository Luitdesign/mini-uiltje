<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
$error = '';

if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    if ($saved === 'added') {
        $info = 'Category added.';
    } elseif ($saved === 'updated') {
        $info = 'Categories saved.';
    } else {
        $info = 'Changes saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? 'add');
    if ($action === 'bulk_update') {
        $categories = $_POST['categories'] ?? [];
        if (!is_array($categories) || $categories === []) {
            $error = 'No categories to update.';
        } else {
            try {
                repo_bulk_update_categories($db, $categories);
                redirect('/categories.php?saved=updated');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $error = 'Category name cannot be empty.';
        } else {
            $id = repo_create_category($db, $name);
            if ($id) {
                redirect('/categories.php?saved=added');
            } else {
                $error = 'Could not save category.';
            }
        }
    }
}

$cats = repo_list_categories($db);

render_header('Categories', 'categories');
?>

<div class="card">
  <h1>Categories</h1>
  <p class="small">Create categories that you can assign to transactions.</p>

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

  <form method="post" action="/categories.php" class="row" style="align-items:flex-end; margin-top: 12px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="add">
    <div style="flex: 1; min-width: 260px;">
      <label>New category</label>
      <input class="input" name="name" placeholder="e.g. Groceries" required>
    </div>
    <div>
      <button class="btn" type="submit">Add</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Existing</h2>
  <form method="post" action="/categories.php">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="bulk_update">
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($cats)): ?>
          <tr><td class="small">No categories yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td>
              <input class="input" name="categories[<?= h((string)$c['id']) ?>][name]" value="<?= h($c['name']) ?>">
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!empty($cats)): ?>
      <button class="btn primary floating-save" type="submit">Save all categories</button>
    <?php endif; ?>
  </form>
</div>

<?php render_footer(); ?>
