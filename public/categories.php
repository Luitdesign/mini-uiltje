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
        $info = 'Category updated.';
    } else {
        $info = 'Changes saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? 'add');
    if ($action === 'update') {
        $categoryId = (int)($_POST['id'] ?? 0);
        $name = (string)($_POST['name'] ?? '');
        try {
            repo_update_category($db, $categoryId, $name);
            redirect('/categories.php?saved=updated');
        } catch (Throwable $e) {
            $error = $e->getMessage();
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
            <form method="post" action="/categories.php" class="row" style="gap: 8px; align-items: center;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
              <input class="input" name="name" value="<?= h($c['name']) ?>">
              <button class="btn" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
