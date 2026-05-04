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
    <table>
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
            <form method="post" action="/tag.php" class="row" style="gap:8px; align-items:center;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
              <input type="hidden" name="action" value="rename_tag">
              <input type="hidden" name="old_tag" value="<?= h($tag) ?>">
              <input class="input" type="text" name="new_tag" value="<?= h($tag) ?>" style="min-width:180px;">
              <button class="btn" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php render_footer();
