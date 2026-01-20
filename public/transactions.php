<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

$latest = repo_get_latest_month($db, $userId);
$year = (int)($_GET['year'] ?? ($latest['y'] ?? (int)date('Y')));
$month = (int)($_GET['month'] ?? ($latest['m'] ?? (int)date('n')));
$q = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (string)($_GET['category_id'] ?? '');
$autoCategoryFilter = (string)($_GET['auto_category_id'] ?? '');
$saved = isset($_GET['saved']);
$autoUpdated = (int)($_GET['auto_updated'] ?? 0);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $action = (string)($_POST['action'] ?? '');
    $savedFlag = false;
    if ($action === 'update_categories') {
        try {
            $savedFlag = true;
            $canUpdatePots = table_exists($db, 'pots')
                && table_exists($db, 'pot_category_map')
                && column_exists($db, 'transactions', 'pot_id');
            $categoryPotMap = $canUpdatePots ? repo_get_category_pot_map($db, $userId) : [];
            $validCategoryIds = [];
            foreach (repo_list_assignable_categories($db) as $category) {
                $validCategoryIds[(int)$category['id']] = true;
            }
            $categoryIds = $_POST['category_ids'] ?? [];
            if (is_array($categoryIds)) {
                foreach ($categoryIds as $txnIdRaw => $categoryIdRaw) {
                    $txnId = (int)$txnIdRaw;
                    $categoryIdRaw = (string)$categoryIdRaw;
                    $categoryId = ($categoryIdRaw === '' ? null : (int)$categoryIdRaw);
                    if ($categoryId !== null && !isset($validCategoryIds[$categoryId])) {
                        $categoryId = null;
                    }
                    if ($txnId > 0) {
                        repo_update_transaction_category($db, $userId, $txnId, $categoryId);
                        if ($canUpdatePots) {
                            $potId = null;
                            if ($categoryId !== null && isset($categoryPotMap[$categoryId])) {
                                $potId = (int)$categoryPotMap[$categoryId];
                            }
                            repo_update_transaction_pot($db, $userId, $txnId, $potId);
                        }
                    }
                }
            }
            $friendlyNames = $_POST['friendly_names'] ?? [];
            if (is_array($friendlyNames)) {
                foreach ($friendlyNames as $txnIdRaw => $friendlyNameRaw) {
                    $txnId = (int)$txnIdRaw;
                    if ($txnId > 0) {
                        $friendlyName = is_string($friendlyNameRaw) ? $friendlyNameRaw : '';
                        repo_update_transaction_friendly_name($db, $userId, $txnId, $friendlyName);
                    }
                }
            }
        } catch (Throwable $e) {
            $savedFlag = false;
            $error = 'Saving categories failed. Please refresh the page and try again.';
        }
    }
    if ($action === 'rerun_auto') {
        $autoUpdated = repo_reapply_auto_categories($db, $userId, $year, $month);
    }

    // After POST, redirect to GET (PRG pattern) to avoid resubmission.
    if ($error === '') {
        $qsParams = [
            'year' => $year,
            'month' => $month,
            'q' => $q,
        ];
        if ($categoryFilter !== '') {
            $qsParams['category_id'] = $categoryFilter;
        }
        if ($autoCategoryFilter !== '') {
            $qsParams['auto_category_id'] = $autoCategoryFilter;
        }
        if ($savedFlag) {
            $qsParams['saved'] = 1;
        }
        if ($autoUpdated > 0) {
            $qsParams['auto_updated'] = $autoUpdated;
        }
        $qs = http_build_query($qsParams);
        redirect('/transactions.php?' . $qs);
    }
}

$categories = repo_list_assignable_categories($db);
$uncategorizedColor = repo_get_setting($db, 'uncategorized_color');
$txns = repo_list_transactions($db, $userId, $year, $month, $q, $categoryFilter, $autoCategoryFilter);
$incomeTxns = [];
$expenseTxns = [];

foreach ($txns as $txn) {
    $amt = (float)$txn['amount_signed'];
    if ($amt >= 0) {
        $incomeTxns[] = $txn;
    } else {
        $expenseTxns[] = $txn;
    }
}

render_header('Transactions', 'transactions');
?>

<div class="card">
  <h1>Transactions</h1>
  <p class="small">
    Month: <strong><?= h(sprintf('%04d-%02d', $year, $month)) ?></strong>
    &nbsp;|&nbsp;
    <a href="/summary.php?year=<?= $year ?>&month=<?= $month ?>">View summary</a>
  </p>

  <form method="get" action="/transactions.php" class="row" style="align-items: flex-end;">
    <input type="hidden" name="year" value="<?= $year ?>">
    <input type="hidden" name="month" value="<?= $month ?>">
    <div style="flex: 1; min-width: 220px;">
      <label>Search (description/notes)</label>
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="e.g. Albert Heijn">
    </div>
    <div style="min-width: 220px;">
      <label>Auto Category</label>
      <select class="input" name="auto_category_id">
        <option value="" <?= $autoCategoryFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="0" <?= $autoCategoryFilter === '0' ? 'selected' : '' ?>>Not set</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $autoCategoryFilter === (string)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="min-width: 220px;">
      <label>Category</label>
      <select class="input" name="category_id">
        <option value="" <?= $categoryFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="0" <?= $categoryFilter === '0' ? 'selected' : '' ?>>Not set</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $categoryFilter === (string)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button class="btn" type="submit">Apply</button>
    </div>
  </form>

  <form method="post" action="/transactions.php?<?= h(http_build_query([
      'year' => $year,
      'month' => $month,
      'q' => $q,
      'category_id' => $categoryFilter,
      'auto_category_id' => $autoCategoryFilter,
    ])) ?>" style="margin-top: 12px;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="rerun_auto">
    <button class="btn" type="submit">Auto categorie opnieuw toepassen</button>
  </form>

  <?php if ($saved): ?>
    <div class="small" style="margin-top: 10px; color: var(--accent);">Saved.</div>
  <?php endif; ?>
  <?php if ($autoUpdated > 0): ?>
    <div class="small" style="margin-top: 10px; color: var(--accent);">
      Auto categorie bijgewerkt voor <?= (int)$autoUpdated ?> transacties.
    </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="small" style="margin-top: 10px; color: var(--danger);"><?= h($error) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <form method="post" action="/transactions.php?<?= h(http_build_query([
      'year' => $year,
      'month' => $month,
      'q' => $q,
      'category_id' => $categoryFilter,
      'auto_category_id' => $autoCategoryFilter,
    ])) ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
    <input type="hidden" name="action" value="update_categories">

    <div class="row small" style="align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
      <span><strong>Visible columns:</strong></span>
      <label><input class="js-column-toggle" type="checkbox" data-column="date" checked> Date</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="description" checked> Description</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="amount" checked> Amount</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="auto-category" checked> Auto Category</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="category" checked> Category</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="type" checked> Type</label>
      <label><input class="js-column-toggle" type="checkbox" data-column="direction" checked> Direction</label>
      <button class="btn" type="button" id="js-row-color-toggle">Row colours: On</button>
    </div>

    <h2>Income</h2>
    <table class="table txn-table">
      <thead>
        <tr>
          <th data-col="date" style="min-width: 110px; white-space: nowrap;">Date</th>
          <th data-col="description">Description</th>
          <th data-col="amount">Amount</th>
          <th data-col="auto-category">Auto Category</th>
          <th data-col="category">Category</th>
          <th data-col="type">Type</th>
          <th data-col="direction">Direction</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($incomeTxns)): ?>
          <tr><td colspan="7" class="small">No income transactions found for this month.</td></tr>
        <?php endif; ?>

        <?php foreach ($incomeTxns as $t):
          $amt = (float)$t['amount_signed'];
          $amtCls = ($amt >= 0) ? 'money-pos' : 'money-neg';
          $friendlyName = trim((string)($t['friendly_name'] ?? ''));
          $hasFriendlyName = $friendlyName !== '';
          $rowBaseColor = $t['category_color'] ?? $t['auto_category_color'] ?? null;
          if ($rowBaseColor === null && $t['category_id'] === null && $t['category_auto_id'] === null) {
              $rowBaseColor = $uncategorizedColor;
          }
          $rowColor = rgba_from_hex($rowBaseColor, 0.12);
          $rowStyle = $rowColor ? ' style="--row-color: ' . h($rowColor) . ';" data-row-color="1"' : '';
        ?>
          <tr<?= $rowStyle ?>>
            <td data-col="date" style="min-width: 110px; white-space: nowrap;"><?= h($t['txn_date']) ?></td>
            <td data-col="description">
              <div class="txn-friendly">
                <div class="txn-friendly-display">
                  <?php if ($hasFriendlyName): ?>
                    <button
                      class="link-button txn-friendly-name js-friendly-toggle"
                      type="button"
                      data-friendly="<?= h($friendlyName) ?>"
                      data-original="<?= h((string)$t['description']) ?>"
                      data-showing="friendly"
                      title="Click to show original description"
                    >
                      <?= h($friendlyName) ?>
                    </button>
                  <?php else: ?>
                    <span class="txn-friendly-name"><?= h($t['description']) ?></span>
                  <?php endif; ?>
                  <?php if (($t['flow_type'] ?? '') === 'transfer'): ?>
                    <span class="badge">Transfer</span>
                  <?php endif; ?>
                  <button class="link-button small js-friendly-edit" type="button">edit</button>
                </div>
                <div class="txn-friendly-editor js-friendly-editor" hidden>
                  <input
                    class="input txn-friendly-input"
                    type="text"
                    name="friendly_names[<?= (int)$t['id'] ?>]"
                    value="<?= h($friendlyName) ?>"
                    placeholder="Add a friendly name"
                  >
                  <button class="btn btn-small js-friendly-cancel" type="button">Cancel</button>
                </div>
              </div>
              <?php if (!empty($t['notes'])): ?>
                <div class="small"><?= h(mb_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td data-col="amount" class="money <?= $amtCls ?>"><?= number_format($amt, 2, ',', '.') ?></td>
            <td data-col="auto-category">
              <?= h((string)($t['auto_category_name'] ?? '—')) ?>
            </td>
            <td data-col="category">
              <select name="category_ids[<?= (int)$t['id'] ?>]" style="min-width: 200px;">
                <option value="" <?= empty($t['category_id']) ? 'selected' : '' ?>>Niet ingedeeld</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ((int)$t['category_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td data-col="type">
              <span class="badge"><?= h((string)($t['mutation_type'] ?? '')) ?></span>
            </td>
            <td data-col="direction" class="small">
              <?= h((string)($t['direction'] ?? '')) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h2>Expenses</h2>
    <table class="table txn-table">
      <thead>
        <tr>
          <th data-col="date" style="min-width: 110px; white-space: nowrap;">Date</th>
          <th data-col="description">Description</th>
          <th data-col="amount">Amount</th>
          <th data-col="auto-category">Auto Category</th>
          <th data-col="category">Category</th>
          <th data-col="type">Type</th>
          <th data-col="direction">Direction</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($expenseTxns)): ?>
          <tr><td colspan="7" class="small">No expense transactions found for this month.</td></tr>
        <?php endif; ?>

        <?php foreach ($expenseTxns as $t):
          $amt = (float)$t['amount_signed'];
          $amtCls = ($amt >= 0) ? 'money-pos' : 'money-neg';
          $friendlyName = trim((string)($t['friendly_name'] ?? ''));
          $hasFriendlyName = $friendlyName !== '';
          $rowBaseColor = $t['category_color'] ?? $t['auto_category_color'] ?? null;
          if ($rowBaseColor === null && $t['category_id'] === null && $t['category_auto_id'] === null) {
              $rowBaseColor = $uncategorizedColor;
          }
          $rowColor = rgba_from_hex($rowBaseColor, 0.12);
          $rowStyle = $rowColor ? ' style="--row-color: ' . h($rowColor) . ';" data-row-color="1"' : '';
        ?>
          <tr<?= $rowStyle ?>>
            <td data-col="date" style="min-width: 110px; white-space: nowrap;"><?= h($t['txn_date']) ?></td>
            <td data-col="description">
              <div class="txn-friendly">
                <div class="txn-friendly-display">
                  <?php if ($hasFriendlyName): ?>
                    <button
                      class="link-button txn-friendly-name js-friendly-toggle"
                      type="button"
                      data-friendly="<?= h($friendlyName) ?>"
                      data-original="<?= h((string)$t['description']) ?>"
                      data-showing="friendly"
                      title="Click to show original description"
                    >
                      <?= h($friendlyName) ?>
                    </button>
                  <?php else: ?>
                    <span class="txn-friendly-name"><?= h($t['description']) ?></span>
                  <?php endif; ?>
                  <?php if (($t['flow_type'] ?? '') === 'transfer'): ?>
                    <span class="badge">Transfer</span>
                  <?php endif; ?>
                  <button class="link-button small js-friendly-edit" type="button">edit</button>
                </div>
                <div class="txn-friendly-editor js-friendly-editor" hidden>
                  <input
                    class="input txn-friendly-input"
                    type="text"
                    name="friendly_names[<?= (int)$t['id'] ?>]"
                    value="<?= h($friendlyName) ?>"
                    placeholder="Add a friendly name"
                  >
                  <button class="btn btn-small js-friendly-cancel" type="button">Cancel</button>
                </div>
              </div>
              <?php if (!empty($t['notes'])): ?>
                <div class="small"><?= h(mb_strimwidth((string)$t['notes'], 0, 140, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td data-col="amount" class="money <?= $amtCls ?>"><?= number_format($amt, 2, ',', '.') ?></td>
            <td data-col="auto-category">
              <?= h((string)($t['auto_category_name'] ?? '—')) ?>
            </td>
            <td data-col="category">
              <select name="category_ids[<?= (int)$t['id'] ?>]" style="min-width: 200px;">
                <option value="" <?= empty($t['category_id']) ? 'selected' : '' ?>>Niet ingedeeld</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= ((int)$t['category_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td data-col="type">
              <span class="badge"><?= h((string)($t['mutation_type'] ?? '')) ?></span>
            </td>
            <td data-col="direction" class="small">
              <?= h((string)($t['direction'] ?? '')) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <button class="btn floating-save" type="submit">Save all categories</button>
  </form>
</div>

<script>
  (function () {
    const storageKey = 'transactions.visibleColumns';
    const rowColorStorageKey = 'transactions.showRowColors';
    const toggles = Array.from(document.querySelectorAll('.js-column-toggle'));
    const tables = Array.from(document.querySelectorAll('.txn-table'));
    const rowColorToggle = document.getElementById('js-row-color-toggle');

    if (!toggles.length || !tables.length) {
      return;
    }

    const applyRowColors = (enabled) => {
      document.body.classList.toggle('show-row-colors', enabled);
      if (rowColorToggle) {
        rowColorToggle.textContent = enabled ? 'Row colours: On' : 'Row colours: Off';
      }
    };

    let rowColorsEnabled = true;
    const savedRowColors = window.localStorage.getItem(rowColorStorageKey);
    if (savedRowColors !== null) {
      rowColorsEnabled = savedRowColors === '1';
    }
    applyRowColors(rowColorsEnabled);

    const applyVisibility = (column, isVisible) => {
      tables.forEach((table) => {
        table.querySelectorAll(`[data-col="${column}"]`).forEach((cell) => {
          cell.style.display = isVisible ? '' : 'none';
        });
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

    if (rowColorToggle) {
      rowColorToggle.addEventListener('click', () => {
        rowColorsEnabled = !rowColorsEnabled;
        applyRowColors(rowColorsEnabled);
        window.localStorage.setItem(rowColorStorageKey, rowColorsEnabled ? '1' : '0');
      });
    }

    toggles.forEach((toggle) => {
      applyVisibility(toggle.dataset.column, toggle.checked);
      toggle.addEventListener('change', () => {
        applyVisibility(toggle.dataset.column, toggle.checked);
        persist();
      });
    });

    const friendlyToggles = Array.from(document.querySelectorAll('.js-friendly-toggle'));
    friendlyToggles.forEach((button) => {
      button.addEventListener('click', () => {
        const friendly = button.dataset.friendly || '';
        const original = button.dataset.original || '';
        if (!friendly || !original) {
          return;
        }
        const showing = button.dataset.showing || 'friendly';
        const next = showing === 'friendly' ? 'original' : 'friendly';
        button.textContent = next === 'friendly' ? friendly : original;
        button.dataset.showing = next;
      });
    });

    const friendlyEdits = Array.from(document.querySelectorAll('.js-friendly-edit'));
    friendlyEdits.forEach((button) => {
      button.addEventListener('click', () => {
        const container = button.closest('.txn-friendly');
        if (!container) {
          return;
        }
        const editor = container.querySelector('.js-friendly-editor');
        if (!editor) {
          return;
        }
        const isHidden = editor.hasAttribute('hidden');
        if (isHidden) {
          editor.removeAttribute('hidden');
          const input = editor.querySelector('input');
          if (input) {
            input.focus();
            input.select();
          }
        } else {
          editor.setAttribute('hidden', 'hidden');
        }
      });
    });

    const friendlyCancels = Array.from(document.querySelectorAll('.js-friendly-cancel'));
    friendlyCancels.forEach((button) => {
      button.addEventListener('click', () => {
        const editor = button.closest('.js-friendly-editor');
        if (!editor) {
          return;
        }
        editor.setAttribute('hidden', 'hidden');
      });
    });
  })();
</script>

<?php render_footer(); ?>
