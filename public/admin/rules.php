<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/rules_repo.php';
require_once __DIR__ . '/../../src/ui.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $ruleId = (int)($_POST['rule_id'] ?? 0);

    if ($ruleId > 0) {
        if ($action === 'move_up') {
            move_rule($ruleId, 'up');
            flash_set('Rule moved up.', 'info');
        } elseif ($action === 'move_down') {
            move_rule($ruleId, 'down');
            flash_set('Rule moved down.', 'info');
        } elseif ($action === 'toggle_active') {
            $active = (int)($_POST['is_active'] ?? 0) === 1;
            set_rule_active($ruleId, $active);
            flash_set($active ? 'Rule activated.' : 'Rule deactivated.', 'info');
        }
    }

    redirect('/admin/rules.php');
}

$rules = list_rules();
$fields = rule_match_fields();

render_header('Rules');
?>
<div class="card">
  <div class="row" style="align-items:end">
    <div class="col">
      <h2>Rules</h2>
      <p class="muted">Rules are evaluated in position order. Inactive rules are ignored.</p>
    </div>
    <div class="col" style="max-width:220px">
      <a class="btn primary" href="/admin/rule_edit.php">Add rule</a>
    </div>
  </div>

  <div style="margin-top:14px">
    <?php if (!$rules): ?>
      <p class="muted">No rules yet.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Pos</th>
            <th>Active from</th>
            <th>Field</th>
            <th>Op</th>
            <th>Value</th>
            <th>Category</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rules as $rule):
          $inactive = (int)$rule['is_active'] === 0;
        ?>
          <tr style="<?= $inactive ? 'opacity:0.5' : '' ?>">
            <td><?=h((string)$rule['position'])?></td>
            <td><?=h((string)$rule['active_from'])?></td>
            <td><?=h($fields[$rule['match_field']] ?? (string)$rule['match_field'])?></td>
            <td><?=h((string)$rule['match_op'])?></td>
            <td><?=h((string)$rule['match_value'])?></td>
            <td><?=h((string)($rule['category_name'] ?? ''))?></td>
            <td><?= $inactive ? 'Inactive' : 'Active' ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a class="btn" href="/admin/rule_edit.php?id=<?=h((string)$rule['id'])?>">Edit</a>
                <form method="post">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="rule_id" value="<?=h((string)$rule['id'])?>">
                  <input type="hidden" name="is_active" value="<?= $inactive ? '1' : '0' ?>">
                  <button class="btn" type="submit"><?= $inactive ? 'Activate' : 'Deactivate' ?></button>
                </form>
                <form method="post">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="move_up">
                  <input type="hidden" name="rule_id" value="<?=h((string)$rule['id'])?>">
                  <button class="btn" type="submit">Move up</button>
                </form>
                <form method="post">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="move_down">
                  <input type="hidden" name="rule_id" value="<?=h((string)$rule['id'])?>">
                  <button class="btn" type="submit">Move down</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php render_footer();
