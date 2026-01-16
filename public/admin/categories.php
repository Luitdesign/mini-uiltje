<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/repo.php';
require_login();
require_admin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $type = (string)($_POST['type'] ?? 'expense');
            $name = trim((string)($_POST['name'] ?? ''));
            $parent = $_POST['parent_id'] ?? '';
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') throw new RuntimeException('Name required');
            if (!in_array($type, ['expense','income','transfer'], true)) $type='expense';
            $parentId = ($parent === '' ? null : (int)$parent);
            $stmt = db()->prepare('INSERT INTO categories (type, parent_id, name, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$type, $parentId, $name, $sort]);
            flash_set('Category added.', 'info');
        } elseif ($action === 'bulk_update') {
            $categories = $_POST['categories'] ?? [];
            if (!is_array($categories) || $categories === []) {
                throw new RuntimeException('No categories to update');
            }
            $stmt = db()->prepare('UPDATE categories SET type=?, parent_id=?, name=?, sort_order=?, is_active=? WHERE id=?');
            db()->beginTransaction();
            try {
                foreach ($categories as $id => $values) {
                    $categoryId = (int)$id;
                    if ($categoryId <= 0 || !is_array($values)) {
                        continue;
                    }
                    $type = (string)($values['type'] ?? 'expense');
                    $name = trim((string)($values['name'] ?? ''));
                    $parent = $values['parent_id'] ?? '';
                    $sort = (int)($values['sort_order'] ?? 0);
                    $active = (int)($values['is_active'] ?? 0);
                    if ($name === '') {
                        throw new RuntimeException('Invalid category name');
                    }
                    if (!in_array($type, ['expense','income','transfer'], true)) $type='expense';
                    $parentId = ($parent === '' ? null : (int)$parent);
                    if ($parentId === $categoryId) $parentId = null;
                    $stmt->execute([$type, $parentId, $name, $sort, $active ? 1 : 0, $categoryId]);
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            flash_set('Categories saved.', 'info');
        }
        redirect('/admin/categories.php');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$all = db()->query('SELECT id, type, parent_id, name, sort_order, is_active FROM categories ORDER BY type, sort_order, name')->fetchAll();
$byId = [];
foreach ($all as $c) { $byId[(int)$c['id']] = $c; }

render_header('Categories');
?>
<div class="card">
  <h2>Categories</h2>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>

  <form method="post">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="bulk_update">
    <table class="table">
      <thead><tr><th>ID</th><th>Type</th><th>Parent</th><th>Name</th><th>Sort</th><th>Active</th></tr></thead>
      <tbody>
      <?php foreach ($all as $c): ?>
        <tr>
          <td><?=h((string)$c['id'])?></td>
          <td>
            <select name="categories[<?=h((string)$c['id'])?>][type]">
              <option value="expense" <?= $c['type']==='expense'?'selected':'' ?>>expense</option>
              <option value="income" <?= $c['type']==='income'?'selected':'' ?>>income</option>
              <option value="transfer" <?= $c['type']==='transfer'?'selected':'' ?>>transfer</option>
            </select>
          </td>
          <td>
            <select name="categories[<?=h((string)$c['id'])?>][parent_id]">
              <option value="">-- none --</option>
              <?php foreach ($all as $p): if ((int)$p['id'] === (int)$c['id']) continue; ?>
                <option value="<?=h((string)$p['id'])?>" <?= ((int)$c['parent_id'] === (int)$p['id']) ? 'selected' : '' ?>><?=h($p['name'])?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input name="categories[<?=h((string)$c['id'])?>][name]" value="<?=h($c['name'])?>"></td>
          <td style="max-width:90px"><input name="categories[<?=h((string)$c['id'])?>][sort_order]" value="<?=h((string)$c['sort_order'])?>"></td>
          <td style="text-align:center">
            <input type="hidden" name="categories[<?=h((string)$c['id'])?>][is_active]" value="0">
            <input type="checkbox" name="categories[<?=h((string)$c['id'])?>][is_active]" value="1" <?= ((int)$c['is_active']===1)?'checked':'' ?>>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button class="btn primary floating-save" type="submit">Save all categories</button>
  </form>

  <h3 style="margin-top:18px">Add category</h3>
  <form method="post">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="add">
    <div class="row">
      <div class="col">
        <label>Type</label>
        <select name="type">
          <option value="expense">expense</option>
          <option value="income">income</option>
          <option value="transfer">transfer</option>
        </select>
      </div>
      <div class="col">
        <label>Parent (optional)</label>
        <select name="parent_id">
          <option value="">-- none --</option>
          <?php foreach ($all as $p): ?>
            <option value="<?=h((string)$p['id'])?>"><?=h($p['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label>Name</label>
        <input name="name" required>
      </div>
      <div class="col">
        <label>Sort order</label>
        <input name="sort_order" value="0">
      </div>
    </div>
    <div style="margin-top:12px"><button class="btn primary" type="submit">Add</button></div>
  </form>
</div>
<?php render_footer();
