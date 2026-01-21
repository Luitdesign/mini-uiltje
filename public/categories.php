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
    } elseif ($saved === 'uncategorized') {
        $info = 'Uncategorized color updated.';
    } elseif ($saved === 'deleted') {
        $info = 'Category deleted.';
    } elseif ($saved === 'imported') {
        $createdCategories = (int)($_GET['created_categories'] ?? 0);
        $skippedCategories = (int)($_GET['skipped_categories'] ?? 0);
        $createdRules = (int)($_GET['created_rules'] ?? 0);
        $skippedRules = (int)($_GET['skipped_rules'] ?? 0);
        $info = sprintf(
            'Import complete. Categories: %d created, %d skipped. Rules: %d created, %d skipped.',
            $createdCategories,
            $skippedCategories,
            $createdRules,
            $skippedRules
        );
    } else {
        $info = 'Changes saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? 'add');
    if ($action === 'export_rules_categories') {
        $payload = repo_export_rules_categories($db, current_user_id());
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode export data.');
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rules-categories-export.json"');
        echo $json;
        exit;
    }
    if ($action === 'import_rules_categories') {
        $file = $_FILES['import_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Please choose a JSON export file to import.';
        } else {
            $contents = file_get_contents($file['tmp_name']);
            $payload = $contents !== false ? json_decode($contents, true) : null;
            if (!is_array($payload)) {
                $error = 'Import file is not valid JSON.';
            } else {
                try {
                    $result = repo_import_rules_categories($db, current_user_id(), $payload);
                    $query = http_build_query([
                        'saved' => 'imported',
                        'created_categories' => $result['created_categories'],
                        'skipped_categories' => $result['skipped_categories'],
                        'created_rules' => $result['created_rules'],
                        'skipped_rules' => $result['skipped_rules'],
                    ]);
                    redirect('/categories.php?' . $query);
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    } elseif ($action === 'update') {
        $categoryId = (int)($_POST['id'] ?? 0);
        $category = repo_get_category($db, $categoryId);
        if (!$category) {
            $error = 'Category not found.';
        } else {
            $name = (string)$category['name'];
        }
        $useColor = isset($_POST['use_color']);
        $color = $useColor ? (string)($_POST['color'] ?? '') : null;
        $savingsIdRaw = trim((string)($_POST['savings_id'] ?? ''));
        $savingsId = $savingsIdRaw !== '' ? (int)$savingsIdRaw : null;
        if ($savingsId !== null && $savingsId <= 0) {
            $savingsId = null;
        }
        if ($error === '' && $savingsId !== null) {
            $saving = repo_find_saving($db, $savingsId);
            if (!$saving) {
                $error = 'Selected savings account not found.';
            }
        }
        if ($error === '') {
            try {
                repo_update_category($db, $categoryId, $name, $color, $savingsId);
                redirect('/categories.php?saved=updated');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'update_uncategorized_color') {
        $color = '#ff0000';
        try {
            $color = normalize_hex_color($color);
            repo_set_setting($db, 'uncategorized_color', $color);
            redirect('/categories.php?saved=uncategorized');
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
        $useColor = isset($_POST['use_color']);
        $color = $useColor ? (string)($_POST['color'] ?? '') : null;
        $savingsIdRaw = trim((string)($_POST['savings_id'] ?? ''));
        $savingsId = $savingsIdRaw !== '' ? (int)$savingsIdRaw : null;
        if ($savingsId !== null && $savingsId <= 0) {
            $savingsId = null;
        }
        if ($name === '') {
            $error = 'Category name cannot be empty.';
        } elseif ($savingsId !== null && !repo_find_saving($db, $savingsId)) {
            $error = 'Selected savings account not found.';
        } else {
            $id = repo_create_category($db, $name, $color, $savingsId);
            if ($id) {
                redirect('/categories.php?saved=added');
            } else {
                $error = 'Could not save category.';
            }
        }
    }
}

$cats = repo_list_categories($db);
$savings = repo_list_savings($db);
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$uncategorizedColor = '#ff0000';
$storedUncategorizedColor = repo_get_setting($db, 'uncategorized_color');
if ($storedUncategorizedColor !== $uncategorizedColor) {
    repo_set_setting($db, 'uncategorized_color', $uncategorizedColor);
}

render_header('Categories', 'categories');
?>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center; gap: 12px;">
    <div>
      <h1>Categories</h1>
      <p class="small">Create categories that you can assign to transactions.</p>
    </div>
    <div class="row" style="gap: 8px; flex-wrap: wrap;">
      <form method="post" action="/categories.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
        <input type="hidden" name="action" value="export_rules_categories">
        <button class="btn" type="submit">Export rules & categories</button>
      </form>
      <form method="post" action="/categories.php" enctype="multipart/form-data" class="row" style="gap: 8px; align-items: center;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
        <input type="hidden" name="action" value="import_rules_categories">
        <label class="small" style="margin: 0;">
          <input class="input" type="file" name="import_file" accept="application/json">
        </label>
        <button class="btn" type="submit">Import rules & categories</button>
      </form>
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

  <form method="post" action="/categories.php" class="row" style="align-items:flex-end; margin-top: 12px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="add">
    <div style="flex: 1; min-width: 260px;">
      <label>New category</label>
      <input class="input" name="name" placeholder="e.g. Boodschappen" required>
    </div>
    <div style="min-width: 220px;">
      <label>Linked savings account</label>
      <select class="input" name="savings_id">
        <option value="">None</option>
        <?php foreach ($savings as $saving): ?>
          <option value="<?= h((string)$saving['id']) ?>"><?= h((string)$saving['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="small">Optional. Used for savings tracking.</div>
    </div>
    <div style="min-width: 200px;">
      <label>Category color</label>
      <div class="row" style="align-items: center;">
        <label class="small" style="margin: 0;">
          <input type="checkbox" name="use_color" value="1">
          Use color
        </label>
        <input class="input" type="color" name="color" value="#6ee7b7" style="width: 56px; height: 44px; padding: 4px;">
      </div>
      <div class="small">Applied softly to transaction rows.</div>
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
      <textarea class="input" name="bulk_names" rows="4" placeholder="Boodschappen&#10;Gezondheid&#10;Travel"></textarea>
      <div class="small">Enter one per line or separate with commas.</div>
    </div>
    <div>
      <button class="btn" type="submit">Add all</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center; gap: 12px;">
    <h2>Existing</h2>
    <button class="btn" type="button" id="js-row-color-toggle">Row colours: On</button>
  </div>
  <table class="table category-table" style="margin-top: 12px;">
    <thead>
      <tr>
        <th>Name</th>
        <th style="width: 220px;">Savings</th>
        <th style="width: 200px;">Color</th>
        <th style="width: 200px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php $uncategorizedRowColor = rgba_from_hex($uncategorizedColor, 0.12); ?>
      <tr<?= $uncategorizedRowColor ? ' style="--row-color: ' . h($uncategorizedRowColor) . ';" data-row-color="1"' : '' ?>>
        <td style="height:44px;">
          <span >Uncategorized</span>
        </td>
        <td><span class="small muted">None</span></td>
        <td>
          <?php $swatch = rgba_from_hex($uncategorizedColor, 0.18); ?>
          <span class="badge" style="background: <?= h((string)$swatch) ?>; color: var(--text); border-color: <?= h($uncategorizedColor) ?>;">
            <?= h($uncategorizedColor) ?>
          </span>
        </td>
        <td class="action-cell"></td>
      </tr>
      <?php foreach ($cats as $cat): ?>
        <?php $catId = (int)$cat['id']; ?>
        <?php $catRowColor = !empty($cat['color']) ? rgba_from_hex($cat['color'], 0.12) : null; ?>
        <?php $formId = $editId === $catId ? 'category-edit-' . $catId : null; ?>
        <tr<?= $catRowColor ? ' style="--row-color: ' . h((string)$catRowColor) . ';" data-row-color="1"' : '' ?>>
          <td><?= h($cat['name']) ?></td>
          <td>
            <?php if ($editId === $catId): ?>
              <select class="input" name="savings_id" form="<?= h((string)$formId) ?>">
                <option value="">None</option>
                <?php foreach ($savings as $saving): ?>
                  <?php $selected = (int)$cat['savings_id'] === (int)$saving['id']; ?>
                  <option value="<?= h((string)$saving['id']) ?>" <?= $selected ? 'selected' : '' ?>>
                    <?= h((string)$saving['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php elseif (!empty($cat['savings_name'])): ?>
              <?= h((string)$cat['savings_name']) ?>
            <?php else: ?>
              <span class="small muted">None</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($editId === $catId): ?>
              <form id="<?= h($formId) ?>" method="post" action="/categories.php" class="row" style="gap: 8px; align-items: center;">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= h((string)$cat['id']) ?>">
                <label class="small" style="margin: 0;">
                  <input type="checkbox" name="use_color" value="1" <?= $cat['color'] ? 'checked' : '' ?>>
                  Use color
                </label>
                <input class="input" type="color" name="color" value="<?= h($cat['color'] ?: '#6ee7b7') ?>" style="width: 56px; height: 44px; padding: 4px;">
              </form>
            <?php elseif (!empty($cat['color'])): ?>
              <?php $swatch = rgba_from_hex($cat['color'], 0.18); ?>
              <span class="badge" style="background: <?= h((string)$swatch) ?>; color: var(--text); border-color: <?= h($cat['color']) ?>;">
                <?= h($cat['color']) ?>
              </span>
            <?php else: ?>
              <span class="small muted">None</span>
            <?php endif; ?>
          </td>
          <td class="action-cell">
            <?php if ($editId === $catId): ?>
              <div class="inline-actions">
                <button class="btn" type="submit" form="<?= h($formId) ?>">Save</button>
                <a class="btn" href="/categories.php">Cancel</a>
              </div>
            <?php else: ?>
              <div class="inline-actions">
                <a class="btn" href="/categories.php?edit=<?= h((string)$cat['id']) ?>" aria-label="Edit category">✏️ Edit</a>
                <form method="post" action="/categories.php" onsubmit="return confirm('Delete this category? Transactions will become uncategorized.');">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= h((string)$cat['id']) ?>">
                  <button class="btn" type="submit" aria-label="Delete category">🗑️ Delete</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (empty($cats)): ?>
    <div class="small muted" style="margin-top: 8px;">No categories yet.</div>
  <?php endif; ?>
</div>

<script>
  (function () {
    const rowColorStorageKey = 'categories.showRowColors';
    const rowColorToggle = document.getElementById('js-row-color-toggle');

    if (!rowColorToggle) {
      return;
    }

    const applyRowColors = (enabled) => {
      document.body.classList.toggle('show-row-colors', enabled);
      rowColorToggle.textContent = enabled ? 'Row colours: On' : 'Row colours: Off';
    };

    let rowColorsEnabled = true;
    const savedRowColors = window.localStorage.getItem(rowColorStorageKey);
    if (savedRowColors !== null) {
      rowColorsEnabled = savedRowColors === '1';
    }
    applyRowColors(rowColorsEnabled);

    rowColorToggle.addEventListener('click', () => {
      rowColorsEnabled = !rowColorsEnabled;
      applyRowColors(rowColorsEnabled);
      window.localStorage.setItem(rowColorStorageKey, rowColorsEnabled ? '1' : '0');
    });
  })();
</script>

<?php render_footer(); ?>
