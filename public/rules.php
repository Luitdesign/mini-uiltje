<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
$error = '';
if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    if ($saved === 'created') {
        $info = 'Rule created.';
    } elseif ($saved === 'updated') {
        $info = 'Rule updated.';
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
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? '');
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
                    redirect('/rules.php?' . $query);
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}

$userId = current_user_id();
$rules = repo_list_rules($db, $userId);

function rule_match_label(?string $match): string {
    return match ($match) {
        'contains' => 'contains',
        'starts' => 'starts with',
        'equals' => 'equals',
        default => '',
    };
}

function rule_summary(array $rule): string {
    $parts = [];
    $fromText = trim((string)($rule['from_text'] ?? ''));
    if ($fromText !== '') {
        $label = rule_match_label($rule['from_text_match'] ?? null);
        $parts[] = $label !== ''
            ? sprintf('Beschrijving text %s "%s"', $label, $fromText)
            : sprintf('Beschrijving text "%s"', $fromText);
    }

    $fromIban = trim((string)($rule['from_iban'] ?? ''));
    if ($fromIban !== '') {
        $parts[] = sprintf('Beschrijving IBAN %s', $fromIban);
    }

    $medText = trim((string)($rule['mededelingen_text'] ?? ''));
    if ($medText !== '') {
        $label = rule_match_label($rule['mededelingen_match'] ?? null);
        $parts[] = $label !== ''
            ? sprintf('Mededelingen %s "%s"', $label, $medText)
            : sprintf('Mededelingen "%s"', $medText);
    }

    $rekening = trim((string)($rule['rekening_equals'] ?? ''));
    if ($rekening !== '') {
        $parts[] = sprintf('Rekening equals %s', $rekening);
    }

    $amountMin = trim((string)($rule['amount_min'] ?? ''));
    $amountMax = trim((string)($rule['amount_max'] ?? ''));
    if ($amountMin !== '' && $amountMax !== '') {
        $parts[] = sprintf('Amount between %s and %s', $amountMin, $amountMax);
    } elseif ($amountMin !== '') {
        $parts[] = sprintf('Amount >= %s', $amountMin);
    } elseif ($amountMax !== '') {
        $parts[] = sprintf('Amount <= %s', $amountMax);
    }

    if (empty($parts)) {
        return 'No conditions';
    }

    return implode('; ', $parts);
}

render_header('Rules', 'rules');
?>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center;">
    <div>
      <h1>Rules</h1>
      <p class="small">Manage automatic categorization rules.</p>
    </div>
    <div class="row" style="gap: 8px; flex-wrap: wrap;">
      <a class="btn primary" href="/rule_edit.php?new=1">+ New rule</a>
      <form method="post" action="/rules.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
        <input type="hidden" name="action" value="export_rules_categories">
        <button class="btn" type="submit">Export rules & categories</button>
      </form>
      <form method="post" action="/rules.php" enctype="multipart/form-data" class="row" style="gap: 8px; align-items: center;">
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

  <?php if (empty($rules)): ?>
    <div class="small muted">No rules yet.</div>
  <?php endif; ?>

  <?php if (!empty($rules)): ?>
    <div class="row small" style="align-items: center; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
      <span><strong>Visible columns:</strong></span>
      <label><input class="js-column-toggle" type="checkbox" data-column="name" checked> Name</label>
    </div>
    <table class="table" style="margin-top: 12px;">
      <thead>
        <tr>
          <th style="width: 90px;">Priority</th>
          <th data-col="name">Name</th>
          <th style="width: 90px;">Active</th>
          <th style="width: 200px;">Category</th>
          <th>Conditions</th>
          <th style="width: 120px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rules as $rule): ?>
          <tr>
            <td><?= h((string)$rule['priority']) ?></td>
            <td data-col="name"><?= h($rule['name']) ?></td>
            <td><?= !empty($rule['active']) ? 'Yes' : 'No' ?></td>
            <td><?= h($rule['category_name'] ?? '—') ?></td>
            <td class="small"><?= h(rule_summary($rule)) ?></td>
            <td>
              <a class="btn" href="/rule_edit.php?id=<?= h((string)$rule['id']) ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
  (function () {
    const storageKey = 'rules.visibleColumns';
    const toggles = Array.from(document.querySelectorAll('.js-column-toggle'));
    const table = document.querySelector('.table');

    if (!toggles.length || !table) {
      return;
    }

    const applyVisibility = (column, isVisible) => {
      table.querySelectorAll(`[data-col="${column}"]`).forEach((cell) => {
        cell.style.display = isVisible ? '' : 'none';
      });
    };

    const saved = window.localStorage.getItem(storageKey);
    if (saved) {
      try {
        const visibleColumns = JSON.parse(saved);
        toggles.forEach((toggle) => {
          const column = toggle.dataset.column;
          if (typeof visibleColumns[column] === 'boolean') {
            toggle.checked = visibleColumns[column];
          }
        });
      } catch (error) {
        window.localStorage.removeItem(storageKey);
      }
    }

    const persist = () => {
      const state = {};
      toggles.forEach((toggle) => {
        state[toggle.dataset.column] = toggle.checked;
      });
      window.localStorage.setItem(storageKey, JSON.stringify(state));
    };

    toggles.forEach((toggle) => {
      applyVisibility(toggle.dataset.column, toggle.checked);
      toggle.addEventListener('change', () => {
        applyVisibility(toggle.dataset.column, toggle.checked);
        persist();
      });
    });
  })();
</script>

<?php render_footer(); ?>
