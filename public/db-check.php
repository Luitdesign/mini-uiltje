<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$databaseName = '';
$connectionError = '';

try {
    $databaseName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    $connectionError = $e->getMessage();
}

$expectedSchema = [
    'users' => ['id', 'username', 'password_hash', 'created_at'],
    'categories' => ['id', 'name', 'color', 'parent_id', 'savings_id', 'created_at'],
    'app_settings' => ['setting_key', 'setting_value'],
    'imports' => ['id', 'user_id', 'filename', 'imported_at'],
    'rules' => [
        'id',
        'user_id',
        'active',
        'priority',
        'name',
        'from_text',
        'from_text_match',
        'from_iban',
        'mededelingen_text',
        'mededelingen_match',
        'rekening_equals',
        'amount_min',
        'amount_max',
        'target_category_id',
        'created_at',
    ],
    'transactions' => [
        'id',
        'user_id',
        'import_id',
        'import_batch_id',
        'txn_hash',
        'txn_date',
        'description',
        'friendly_name',
        'account_iban',
        'counter_iban',
        'code',
        'direction',
        'amount_signed',
        'currency',
        'mutation_type',
        'notes',
        'balance_after',
        'tag',
        'is_internal_transfer',
        'created_source',
        'category_id',
        'category_auto_id',
        'rule_auto_id',
        'auto_reason',
        'savings_id',
        'is_topup',
        'created_at',
    ],
    'savings' => [
        'id',
        'name',
        'active',
        'sort_order',
        'start_amount',
        'monthly_amount',
        'topup_category_id',
    ],
];

$tableDetails = [];
$missingTables = [];
$hasSchemaIssues = false;

if ($connectionError === '' && $databaseName !== '') {
    $stmt = $db->prepare('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = :schema');
    $stmt->execute([':schema' => $databaseName]);
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $existingTablesMap = array_fill_keys($existingTables ?: [], true);

    foreach ($expectedSchema as $table => $columns) {
        $tableExists = isset($existingTablesMap[$table]);
        $missingColumns = [];
        $extraColumns = [];
        $actualColumns = [];

        if ($tableExists) {
            $colStmt = $db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table ORDER BY ORDINAL_POSITION'
            );
            $colStmt->execute([':schema' => $databaseName, ':table' => $table]);
            $actualColumns = $colStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $actualColumnsMap = array_fill_keys($actualColumns, true);

            foreach ($columns as $column) {
                if (!isset($actualColumnsMap[$column])) {
                    $missingColumns[] = $column;
                }
            }

            foreach ($actualColumns as $column) {
                if (!in_array($column, $columns, true)) {
                    $extraColumns[] = $column;
                }
            }
        } else {
            $missingTables[] = $table;
        }

        $hasTableIssues = (!$tableExists) || !empty($missingColumns);
        if ($hasTableIssues) {
            $hasSchemaIssues = true;
        }

        $tableDetails[] = [
            'name' => $table,
            'exists' => $tableExists,
            'expected_columns' => $columns,
            'actual_columns' => $actualColumns,
            'missing_columns' => $missingColumns,
            'extra_columns' => $extraColumns,
        ];
    }
}

render_header('DB Check', 'db-check');
?>

<div class="card" style="max-width: 920px; margin: 20px auto;">
  <h1>Database check</h1>
  <p class="muted">Verifies that the MySQL schema required for this app exists.</p>

  <?php if ($connectionError !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
      <strong>Connection error:</strong> <?= h($connectionError) ?>
    </div>
  <?php else: ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      Connected to database <strong><?= h($databaseName !== '' ? $databaseName : '(unknown)') ?></strong>.
    </div>
  <?php endif; ?>

  <?php if ($connectionError === '' && $databaseName !== ''): ?>
    <?php if ($hasSchemaIssues): ?>
      <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06);">
        Some required tables or columns are missing. Use <a href="/schema.php">Schema</a> to apply the latest SQL.
      </div>
    <?php else: ?>
      <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
        ✅ All required tables and columns are present.
      </div>
    <?php endif; ?>

    <div class="card" style="margin-top: 16px;">
      <table class="table" style="width: 100%;">
        <thead>
          <tr>
            <th>Table</th>
            <th>Status</th>
            <th>Missing columns</th>
            <th>Extra columns</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tableDetails as $table): ?>
            <tr>
              <td><strong><?= h($table['name']) ?></strong></td>
              <td>
                <?php if ($table['exists']): ?>
                  <?= empty($table['missing_columns']) ? 'OK' : 'Missing columns' ?>
                <?php else: ?>
                  Missing table
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$table['exists']): ?>
                  —
                <?php elseif (empty($table['missing_columns'])): ?>
                  None
                <?php else: ?>
                  <?= h(implode(', ', $table['missing_columns'])) ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$table['exists']): ?>
                  —
                <?php elseif (empty($table['extra_columns'])): ?>
                  None
                <?php else: ?>
                  <?= h(implode(', ', $table['extra_columns'])) ?>
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
