<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_data_user_id($db);
$error = '';
$renamedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'rename_tag') {
        $oldTag = (string)($_POST['old_tag'] ?? '');
        $newTag = (string)($_POST['new_tag'] ?? '');
        if (clean_tag_name($newTag) === '') {
            $error = 'Please provide a new tag name.';
        } else {
            $renamedCount = repo_rename_tag($db, $userId, $oldTag, $newTag);
        }
    }
}

$tags = repo_list_tags_with_totals($db, $userId);

render_header('Tags', 'tags');
?>
<div class="card">
  <h1>Tags</h1>
  <p class="small muted">Click a tag to open transactions filtered by that tag.</p>
  <?php if ($error !== ''): ?>
    <p class="small" style="color:#f87171;"><?= h($error) ?></p>
  <?php elseif ($renamedCount > 0): ?>
    <p class="small" style="color:#34d399;">Updated <?= (int)$renamedCount ?> transaction(s).</p>
  <?php endif; ?>

  <?php if ($tags === []): ?>
    <p>No tags yet.</p>
  <?php else: ?>
    <table class="table tag-table">
      <thead>
        <tr>
          <th>Tag</th>
          <th>Income</th>
          <th>Expenses</th>
          <th>Net</th>
          <th>Rename</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tags as $row): ?>
        <?php $tag = (string)$row['tag']; ?>
        <tr>
          <td><a href="/transactions.php?all_time=1&tag=<?= urlencode($tag) ?>"><?= h($tag) ?></a></td>
          <td class="money" style="color:#34d399;"><?= number_format((float)$row['income'], 2, ',', '.') ?></td>
          <td class="money" style="color:#f87171;"><?= number_format((float)$row['spending'], 2, ',', '.') ?></td>
          <td class="money <?= ((float)$row['net'] >= 0 ? 'pos' : 'neg') ?>"><?= number_format((float)$row['net'], 2, ',', '.') ?></td>
          <td>
            <form method="post" action="/tag.php" class="tag-rename-form" data-tag-rename-form>
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
              <input type="hidden" name="action" value="rename_tag">
              <input type="hidden" name="old_tag" value="<?= h($tag) ?>">
              <button class="btn tag-edit-btn" type="button" data-tag-edit-toggle>Edit name</button>
              <div class="tag-rename-editor" data-tag-rename-editor hidden>
                <input class="input" type="text" name="new_tag" value="<?= h($tag) ?>" aria-label="New name for <?= h($tag) ?>">
                <div class="tag-rename-actions">
                  <button class="btn" type="submit">Save</button>
                  <button class="btn btn-danger" type="button" data-tag-edit-cancel>Cancel</button>
                </div>
              </div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<script>
  document.querySelectorAll('[data-tag-rename-form]').forEach(function(form) {
    var toggle = form.querySelector('[data-tag-edit-toggle]');
    var editor = form.querySelector('[data-tag-rename-editor]');
    var cancel = form.querySelector('[data-tag-edit-cancel]');
    var input = editor ? editor.querySelector('input[name="new_tag"]') : null;
    if (!toggle || !editor) return;

    toggle.addEventListener('click', function() {
      editor.hidden = false;
      toggle.hidden = true;
      if (input) {
        input.focus();
        input.select();
      }
    });

    if (cancel) {
      cancel.addEventListener('click', function() {
        editor.hidden = true;
        toggle.hidden = false;
      });
    }
  });
</script>
<?php render_footer();
