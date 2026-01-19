<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$info = '';
if (isset($_GET['saved'])) {
    $saved = (string)$_GET['saved'];
    if ($saved === 'created') {
        $info = 'Rule created.';
    } elseif ($saved === 'updated') {
        $info = 'Rule updated.';
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
            ? sprintf('From text %s "%s"', $label, $fromText)
            : sprintf('From text "%s"', $fromText);
    }

    $fromIban = trim((string)($rule['from_iban'] ?? ''));
    if ($fromIban !== '') {
        $parts[] = sprintf('From IBAN %s', $fromIban);
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
    <div>
      <a class="btn primary" href="/rule_edit.php?new=1">+ New rule</a>
    </div>
  </div>

  <?php if ($info !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08);">
      ✅ <?= h($info) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($rules)): ?>
    <div class="small muted">No rules yet.</div>
  <?php endif; ?>

  <?php if (!empty($rules)): ?>
    <table class="table" style="margin-top: 12px;">
      <thead>
        <tr>
          <th style="width: 90px;">Priority</th>
          <th>Name</th>
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
            <td><?= h($rule['name']) ?></td>
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

<?php render_footer(); ?>
