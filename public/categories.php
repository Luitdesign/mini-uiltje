<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
$error = '';

function parse_category_entry(string $raw): array {
    $value = trim($raw);
    if ($value === '') {
        return [];
    }
    foreach ([' - ', ' → '] as $delimiter) {
        if (strpos($value, $delimiter) !== false) {
            [$parent, $child] = array_map('trim', explode($delimiter, $value, 2));
            if ($parent !== '' && $child !== '') {
                return ['name' => $child, 'parent' => $parent];
            }
        }
    }
    return ['name' => $value, 'parent' => null];
}

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
    } elseif ($saved === 'deleted') {
        $info = 'Category deleted.';
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
        $parentIdRaw = (string)($_POST['parent_id'] ?? '');
        $parentId = $parentIdRaw === '' ? null : (int)$parentIdRaw;
        if ($parentId === null) {
            $parsed = parse_category_entry($name);
            if (($parsed['parent'] ?? null) !== null) {
                $parentName = $parsed['parent'];
                $parentId = repo_find_category_id($db, $parentName, null);
                if (!$parentId) {
                    $parentId = repo_create_category($db, $parentName, null);
                }
                $name = (string)$parsed['name'];
            }
        }
        try {
            repo_update_category($db, $categoryId, $name, $parentId);
            redirect('/categories.php?saved=updated');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'add_parent') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $error = 'Category name cannot be empty.';
        } else {
            $id = repo_create_category($db, $name, null);
            if ($id) {
                redirect('/categories.php?saved=added');
            } else {
                $error = 'Could not save category.';
            }
        }
    } elseif ($action === 'bulk_add') {
        $bulk = (string)($_POST['bulk_names'] ?? '');
        $names = preg_split('/[\r\n,]+/', $bulk) ?: [];
        $names = array_values(array_filter($names, static fn(string $value): bool => trim($value) !== ''));
        if ($names === []) {
            $error = 'Please enter at least one category name.';
        } else {
            try {
                $entries = [];
                foreach ($names as $value) {
                    $parsed = parse_category_entry((string)$value);
                    if ($parsed === []) {
                        continue;
                    }
                    $parentName = $parsed['parent'];
                    $parentId = null;
                    if ($parentName !== null) {
                        $parentId = repo_find_category_id($db, $parentName, null);
                        if (!$parentId) {
                            $parentId = repo_create_category($db, $parentName, null);
                        }
                    }
                    $entries[] = [
                        'name' => $parsed['name'],
                        'parent_id' => $parentId,
                    ];
                }
                $result = repo_bulk_create_categories($db, $entries);
                $addedCount = count($result['created_ids']);
                $skippedCount = (int)$result['skipped'];
                redirect('/categories.php?saved=bulk&added=' . $addedCount . '&skipped=' . $skippedCount);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $categoryId = (int)($_POST['id'] ?? 0);
        try {
            repo_delete_category($db, $categoryId);
            redirect('/categories.php?saved=deleted');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $parentIdRaw = (string)($_POST['parent_id'] ?? '');
        $parentId = $parentIdRaw === '' ? null : (int)$parentIdRaw;
        if ($parentId === null) {
            $parsed = parse_category_entry($name);
            if (($parsed['parent'] ?? null) !== null) {
                $parentName = $parsed['parent'];
                $parentId = repo_find_category_id($db, $parentName, null);
                if (!$parentId) {
                    $parentId = repo_create_category($db, $parentName, null);
                }
                $name = (string)$parsed['name'];
            }
        }
        if ($name === '') {
            $error = 'Category name cannot be empty.';
        } else {
            $id = repo_create_category($db, $name, $parentId);
            if ($id) {
                redirect('/categories.php?saved=added');
            } else {
                $error = 'Could not save category.';
            }
        }
    }
}

$cats = repo_list_categories($db);
$parentOptions = array_values(array_filter($cats, static fn(array $cat): bool => empty($cat['parent_id'])));
$hasChildrenMap = [];
foreach ($cats as $cat) {
    if (!empty($cat['parent_id'])) {
        $hasChildrenMap[(int)$cat['parent_id']] = true;
    }
}
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($editId > 0) {
    $parentOptions = array_values(array_filter(
        $parentOptions,
        static fn(array $cat): bool => (int)$cat['id'] !== $editId
    ));
}
$parents = array_values(array_filter($cats, static fn(array $cat): bool => $cat['parent_id'] === null));
usort($parents, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
$parentLookup = [];
foreach ($parents as $parent) {
    $parentLookup[(int)$parent['id']] = $parent;
}
$childrenByParent = [];
$orphanChildren = [];
foreach ($cats as $cat) {
    if ($cat['parent_id'] === null) {
        continue;
    }
    $parentId = (int)$cat['parent_id'];
    if (isset($parentLookup[$parentId])) {
        $childrenByParent[$parentId][] = $cat;
    } else {
        $orphanChildren[] = $cat;
    }
}
foreach ($childrenByParent as $parentId => $children) {
    usort($children, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    $childrenByParent[$parentId] = $children;
}
usort($orphanChildren, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

render_header('Categories', 'categories');
?>

<div class="card">
  <h1>Categories</h1>
  <p class="small">Create categories that you can assign to transactions. Parent categories are used for grouping and are not selectable on transactions.</p>

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
    <input type="hidden" name="action" value="add_parent">
    <div style="flex: 1; min-width: 260px;">
      <label>New parent category</label>
      <input class="input" name="name" placeholder="e.g. Household" required>
    </div>
    <div>
      <button class="btn" type="submit">Add parent</button>
    </div>
  </form>

  <form method="post" action="/categories.php" class="row" style="align-items:flex-end; margin-top: 12px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="add">
    <div style="min-width: 220px;">
      <label>Parent category</label>
      <select class="input" name="parent_id">
        <option value="">None</option>
        <?php foreach ($parentOptions as $parent): ?>
          <option value="<?= h((string)$parent['id']) ?>"><?= h($parent['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex: 1; min-width: 260px;">
      <label>New category</label>
      <input class="input" name="name" placeholder="e.g. Boodschappen" required>
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
      <textarea class="input" name="bulk_names" rows="4" placeholder="Household - Boodschappen&#10;Household - Gezondheid&#10;Travel"></textarea>
      <div class="small">Enter one per line or separate with commas. Use "Parent - Child" to create two-level categories.</div>
    </div>
    <div>
      <button class="btn" type="submit">Add all</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Existing</h2>
  <?php if (empty($cats)): ?>
    <div class="small muted">No categories yet.</div>
  <?php endif; ?>

  <?php foreach ($parents as $parent): ?>
    <?php $parentId = (int)$parent['id']; ?>
    <div class="card" style="margin-top: 12px;">
      <div class="row" style="align-items: flex-start; justify-content: space-between;">
        <div style="flex: 1; min-width: 240px;">
          <?php if ($editId === $parentId): ?>
            <?php $formId = 'category-edit-' . $parentId; ?>
            <form id="<?= h($formId) ?>" method="post" action="/categories.php" class="row" style="gap: 8px; align-items: flex-end;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= h((string)$parent['id']) ?>">
              <div style="min-width: 180px;">
                <label class="small">Parent</label>
                <select class="input" name="parent_id">
                  <option value="">None</option>
                  <?php foreach ($parentOptions as $option): ?>
                    <option value="<?= h((string)$option['id']) ?>" <?= ((int)$parent['parent_id'] === (int)$option['id']) ? 'selected' : '' ?>>
                      <?= h($option['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="min-width: 200px;">
                <label class="small">Name</label>
                <input class="input" name="name" value="<?= h($parent['name']) ?>">
              </div>
            </form>
            <?php if (!empty($hasChildrenMap[$parentId])): ?>
              <div class="small muted" style="margin-top: 6px;">This category has child categories. Move them first before assigning a parent.</div>
            <?php endif; ?>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 4px;">
              <strong><?= h($parent['name']) ?></strong>
              <span class="small muted">Parent category</span>
            </div>
          <?php endif; ?>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
          <?php if ($editId === $parentId): ?>
            <button class="btn" type="submit" form="<?= h($formId) ?>">Save</button>
            <a class="btn" href="/categories.php">Cancel</a>
          <?php else: ?>
            <a class="btn" href="/categories.php?edit=<?= h((string)$parent['id']) ?>" aria-label="Edit category <?= h($parent['name']) ?>">✏️ Edit</a>
            <form method="post" action="/categories.php" onsubmit="return confirm('Delete this category? Transactions will become uncategorized.');">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string)$parent['id']) ?>">
              <button class="btn" type="submit" aria-label="Delete category <?= h($parent['name']) ?>">🗑️ Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php $children = $childrenByParent[$parentId] ?? []; ?>
      <?php if (!empty($children)): ?>
        <table class="table" style="margin-top: 12px;">
          <thead>
            <tr>
              <th>Subcategory</th>
              <th>Path</th>
              <th style="width: 120px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($children as $c): ?>
              <tr>
                <td>
                  <?php if ($editId === (int)$c['id']): ?>
                    <?php $formId = 'category-edit-' . (int)$c['id']; ?>
                    <form id="<?= h($formId) ?>" method="post" action="/categories.php" class="row" style="gap: 8px; align-items: flex-end;">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
                      <div style="min-width: 160px;">
                        <label class="small">Parent</label>
                        <select class="input" name="parent_id">
                          <option value="">None</option>
                          <?php foreach ($parentOptions as $option): ?>
                            <option value="<?= h((string)$option['id']) ?>" <?= ((int)$c['parent_id'] === (int)$option['id']) ? 'selected' : '' ?>>
                              <?= h($option['name']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div style="min-width: 200px;">
                        <label class="small">Name</label>
                        <input class="input" name="name" value="<?= h($c['name']) ?>">
                      </div>
                    </form>
                  <?php else: ?>
                    <span class="muted">↳</span> <?= h($c['name']) ?>
                  <?php endif; ?>
                </td>
                <td><?= h($c['label']) ?></td>
                <td class="action-cell">
                  <?php if ($editId === (int)$c['id']): ?>
                    <div class="inline-actions">
                      <button class="btn" type="submit" form="<?= h($formId) ?>">Save</button>
                      <a class="btn" href="/categories.php">Cancel</a>
                    </div>
                  <?php else: ?>
                    <div class="inline-actions">
                      <a class="btn" href="/categories.php?edit=<?= h((string)$c['id']) ?>" aria-label="Edit category <?= h($c['name']) ?>">✏️ Edit</a>
                      <form method="post" action="/categories.php" onsubmit="return confirm('Delete this category? Transactions will become uncategorized.');">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
                        <button class="btn" type="submit" aria-label="Delete category <?= h($c['name']) ?>">🗑️ Delete</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="small muted" style="margin-top: 12px;">No subcategories yet.</div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($orphanChildren)): ?>
    <div class="card" style="margin-top: 12px;">
      <strong>Ungrouped categories</strong>
      <div class="small muted" style="margin-top: 4px;">These categories have a missing parent.</div>
      <table class="table" style="margin-top: 12px;">
        <thead>
          <tr>
            <th>Name</th>
            <th>Path</th>
            <th style="width: 120px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orphanChildren as $c): ?>
            <tr>
              <td>
                <?php if ($editId === (int)$c['id']): ?>
                  <?php $formId = 'category-edit-' . (int)$c['id']; ?>
                  <form id="<?= h($formId) ?>" method="post" action="/categories.php" class="row" style="gap: 8px; align-items: flex-end;">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
                    <div style="min-width: 160px;">
                      <label class="small">Parent</label>
                      <select class="input" name="parent_id">
                        <option value="">None</option>
                        <?php foreach ($parentOptions as $option): ?>
                          <option value="<?= h((string)$option['id']) ?>" <?= ((int)$c['parent_id'] === (int)$option['id']) ? 'selected' : '' ?>>
                            <?= h($option['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div style="min-width: 200px;">
                      <label class="small">Name</label>
                      <input class="input" name="name" value="<?= h($c['name']) ?>">
                    </div>
                  </form>
                <?php else: ?>
                  <?= h($c['name']) ?>
                <?php endif; ?>
              </td>
              <td><?= h($c['label']) ?></td>
              <td class="action-cell">
                <?php if ($editId === (int)$c['id']): ?>
                  <div class="inline-actions">
                    <button class="btn" type="submit" form="<?= h($formId) ?>">Save</button>
                    <a class="btn" href="/categories.php">Cancel</a>
                  </div>
                <?php else: ?>
                  <div class="inline-actions">
                    <a class="btn" href="/categories.php?edit=<?= h((string)$c['id']) ?>" aria-label="Edit category <?= h($c['name']) ?>">✏️ Edit</a>
                    <form method="post" action="/categories.php" onsubmit="return confirm('Delete this category? Transactions will become uncategorized.');">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= h((string)$c['id']) ?>">
                      <button class="btn" type="submit" aria-label="Delete category <?= h($c['name']) ?>">🗑️ Delete</button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
