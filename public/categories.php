<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
$error = '';

if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    if ($saved === 'added') {
        $info = 'Category added.';
    } elseif ($saved === 'bulk') {
        $addedCount = (int)($_GET['added'] ?? 0);
        $skippedCount = (int)($_GET['skipped'] ?? 0);
        $info = sprintf('Added %d categories. Skipped %d.', $addedCount, $skippedCount);
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
    } elseif ($action === 'bulk_add') {
        $bulk = (string)($_POST['bulk_names'] ?? '');
        $names = preg_split('/[\r\n,]+/', $bulk) ?: [];
        $names = array_values(array_filter($names, static fn(string $value): bool => trim($value) !== ''));
        if ($names === []) {
            $error = 'Please enter at least one category name.';
        } else {
            try {
                $result = repo_bulk_create_categories($db, $names);
                $addedCount = count($result['created_ids']);
                $skippedCount = (int)$result['skipped'];
                redirect('/categories.php?saved=bulk&added=' . $addedCount . '&skipped=' . $skippedCount);
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
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

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

  <form method="post" action="/categories.php" class="row" style="align-items:flex-end; margin-top: 16px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="bulk_add">
    <div style="flex: 1; min-width: 260px;">
      <label>Bulk add categories</label>
      <textarea class="input" name="bulk_names" rows="4" placeholder="Groceries&#10;Utilities&#10;Travel"></textarea>
      <div class="small">Enter one per line or separate with commas.</div>
    </div>
    <div>
      <button class="btn" type="submit">Add all</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Existing</h2>
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th style="width: 120px;">Edit</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($cats)): ?>
        <tr><td class="small" colspan="2">No categories yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($cats as $c): ?>
        <tr>
          <td>
            <?php if ($editId === (int)$c['id']): ?>
              <?php $formId = 'edit-category-' . (string)$c['id']; ?>
              <form id="<?= h($formId) ?>" method="post" action="/categories.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
                <input class="input" name="name" value="<?= h($c['name']) ?>" style="min-width: 160px;">
              </form>
            <?php else: ?>
              <?= h($c['name']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($editId === (int)$c['id']): ?>
              <button class="btn" type="submit" form="<?= h($formId) ?>">Save</button>
              <a class="btn" href="/categories.php">Cancel</a>
            <?php else: ?>
              <a class="btn" href="/categories.php?edit=<?= h((string)$c['id']) ?>" aria-label="Edit category <?= h($c['name']) ?>">✏️ Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
