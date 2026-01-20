<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();
$info = '';
$error = '';

function month_label(int $month): string {
    $labels = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'May',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Aug',
        9 => 'Sep',
        10 => 'Oct',
        11 => 'Nov',
        12 => 'Dec',
    ];
    return $labels[$month] ?? (string)$month;
}

function parse_pot_rule_payload(array $post, string &$error): ?array {
    $amountRaw = trim((string)($post['amount'] ?? ''));
    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        $error = 'Amount is required.';
        return null;
    }
    $amount = (float)$amountRaw;

    $startYear = (int)($post['start_year'] ?? 0);
    $startMonth = (int)($post['start_month'] ?? 0);
    if ($startYear <= 0 || $startMonth < 1 || $startMonth > 12) {
        $error = 'Start year and month are required.';
        return null;
    }

    $endYearRaw = trim((string)($post['end_year'] ?? ''));
    $endMonthRaw = trim((string)($post['end_month'] ?? ''));
    $endYear = null;
    $endMonth = null;
    if ($endYearRaw !== '' || $endMonthRaw !== '') {
        if ($endYearRaw === '' || $endMonthRaw === '') {
            $error = 'End year and month must both be filled.';
            return null;
        }
        $endYear = (int)$endYearRaw;
        $endMonth = (int)$endMonthRaw;
        if ($endYear <= 0 || $endMonth < 1 || $endMonth > 12) {
            $error = 'End year and month are invalid.';
            return null;
        }
        if ($endYear < $startYear || ($endYear === $startYear && $endMonth < $startMonth)) {
            $error = 'End date must be after the start date.';
            return null;
        }
    }

    return [
        'amount' => $amount,
        'start_year' => $startYear,
        'start_month' => $startMonth,
        'end_year' => $endYear,
        'end_month' => $endMonth,
        'active' => isset($post['active']),
    ];
}

if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    if ($saved === 'created') {
        $info = 'Rule created.';
    } elseif ($saved === 'updated') {
        $info = 'Rule updated.';
    } elseif ($saved === 'deleted') {
        $info = 'Rule deleted.';
    }
}

$pots = repo_list_pots($db, $userId);
$potId = (int)($_GET['pot_id'] ?? 0);
$validPotIds = array_map(static fn(array $pot): int => (int)$pot['id'], $pots);
if ($potId <= 0 || !in_array($potId, $validPotIds, true)) {
    $potId = $validPotIds[0] ?? 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? '');
    $postPotId = (int)($_POST['pot_id'] ?? $potId);

    if ($action === 'delete') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            repo_delete_pot_rule($db, $userId, $ruleId);
            redirect('/pot_rules.php?pot_id=' . $postPotId . '&saved=deleted');
        }
    }

    $payload = parse_pot_rule_payload($_POST, $error);
    if ($payload !== null) {
        if ($action === 'update') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            if ($ruleId > 0) {
                repo_update_pot_rule(
                    $db,
                    $userId,
                    $ruleId,
                    $payload['amount'],
                    $payload['start_year'],
                    $payload['start_month'],
                    $payload['end_year'],
                    $payload['end_month'],
                    $payload['active']
                );
                redirect('/pot_rules.php?pot_id=' . $postPotId . '&saved=updated');
            } else {
                $error = 'Invalid rule.';
            }
        } elseif ($action === 'create') {
            if ($postPotId <= 0) {
                $error = 'Select a pot first.';
            } else {
                repo_create_pot_rule(
                    $db,
                    $userId,
                    $postPotId,
                    $payload['amount'],
                    $payload['start_year'],
                    $payload['start_month'],
                    $payload['end_year'],
                    $payload['end_month'],
                    $payload['active']
                );
                redirect('/pot_rules.php?pot_id=' . $postPotId . '&saved=created');
            }
        }
    }
}

$rules = $potId > 0 ? repo_list_pot_rules($db, $userId, $potId) : [];
$editId = (int)($_GET['edit'] ?? 0);
$editRule = null;
if ($editId > 0) {
    foreach ($rules as $rule) {
        if ((int)$rule['id'] === $editId) {
            $editRule = $rule;
            break;
        }
    }
}

render_header('Pot Rules', 'pots');
?>

<div class="card">
  <div class="row" style="justify-content: space-between; align-items: center; gap: 12px;">
    <div>
      <h1>Pot rules</h1>
      <p class="small">Allocate monthly amounts to pots.</p>
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

  <?php if (empty($pots)): ?>
    <div class="small muted">Create a pot before adding rules.</div>
  <?php else: ?>
    <form method="get" action="/pot_rules.php" class="row" style="align-items: flex-end; gap: 12px; margin-top: 12px;">
      <div style="min-width: 220px;">
        <label>Pot</label>
        <select class="input" name="pot_id" onchange="this.form.submit()">
          <?php foreach ($pots as $pot): ?>
            <option value="<?= h((string)$pot['id']) ?>" <?= ((int)$pot['id'] === $potId) ? 'selected' : '' ?>>
              <?= h($pot['name']) ?><?= !empty($pot['archived']) ? ' (archived)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <noscript>
        <button class="btn" type="submit">Select</button>
      </noscript>
    </form>

    <h2 style="margin-top: 20px;"><?= $editRule ? 'Edit rule' : 'Add rule' ?></h2>
    <form method="post" action="/pot_rules.php?pot_id=<?= h((string)$potId) ?>" class="row" style="gap: 12px; flex-wrap: wrap; align-items: flex-end;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
      <input type="hidden" name="action" value="<?= $editRule ? 'update' : 'create' ?>">
      <input type="hidden" name="pot_id" value="<?= h((string)$potId) ?>">
      <?php if ($editRule): ?>
        <input type="hidden" name="rule_id" value="<?= h((string)$editRule['id']) ?>">
      <?php endif; ?>

      <div>
        <label>Amount</label>
        <input class="input" type="number" step="0.01" name="amount" value="<?= h((string)($editRule['amount_monthly'] ?? '')) ?>" required>
      </div>
      <div>
        <label>Start year</label>
        <input class="input" type="number" name="start_year" min="2000" max="2100" value="<?= h((string)($editRule['start_year'] ?? '')) ?>" required>
      </div>
      <div>
        <label>Start month</label>
        <select class="input" name="start_month" required>
          <?php for ($month = 1; $month <= 12; $month++): ?>
            <option value="<?= $month ?>" <?= ((int)($editRule['start_month'] ?? 0) === $month) ? 'selected' : '' ?>><?= h(month_label($month)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label>End year</label>
        <input class="input" type="number" name="end_year" min="2000" max="2100" value="<?= h((string)($editRule['end_year'] ?? '')) ?>">
      </div>
      <div>
        <label>End month</label>
        <select class="input" name="end_month">
          <option value="">—</option>
          <?php for ($month = 1; $month <= 12; $month++): ?>
            <option value="<?= $month ?>" <?= ((int)($editRule['end_month'] ?? 0) === $month) ? 'selected' : '' ?>><?= h(month_label($month)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label>
          <input type="checkbox" name="active" value="1" <?= $editRule ? (!empty($editRule['active']) ? 'checked' : '') : 'checked' ?>>
          Active
        </label>
      </div>
      <div>
        <button class="btn primary" type="submit"><?= $editRule ? 'Save rule' : 'Add rule' ?></button>
        <?php if ($editRule): ?>
          <a class="btn" href="/pot_rules.php?pot_id=<?= h((string)$potId) ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </form>

    <h2 style="margin-top: 20px;">Existing rules</h2>
    <?php if (empty($rules)): ?>
      <div class="small muted">No rules for this pot yet.</div>
    <?php else: ?>
      <table class="table" style="margin-top: 12px;">
        <thead>
          <tr>
            <th style="width: 140px;">Amount</th>
            <th style="width: 140px;">Start</th>
            <th style="width: 140px;">End</th>
            <th style="width: 100px;">Active</th>
            <th style="width: 160px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rules as $rule): ?>
            <?php $ruleId = (int)$rule['id']; ?>
            <tr>
                <td class="money"><?= number_format((float)$rule['amount_monthly'], 2, ',', '.') ?></td>
                <td><?= h((string)$rule['start_year']) ?> <?= h(month_label((int)$rule['start_month'])) ?></td>
                <td>
                  <?php if (!empty($rule['end_year']) && !empty($rule['end_month'])): ?>
                    <?= h((string)$rule['end_year']) ?> <?= h(month_label((int)$rule['end_month'])) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td><?= !empty($rule['active']) ? 'Yes' : 'No' ?></td>
                <td>
                  <div class="inline-actions">
                    <a class="btn" href="/pot_rules.php?pot_id=<?= h((string)$potId) ?>&edit=<?= h((string)$ruleId) ?>">Edit</a>
                    <form method="post" action="/pot_rules.php?pot_id=<?= h((string)$potId) ?>" onsubmit="return confirm('Delete this rule?');">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="pot_id" value="<?= h((string)$potId) ?>">
                      <input type="hidden" name="rule_id" value="<?= h((string)$ruleId) ?>">
                      <button class="btn" type="submit">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
