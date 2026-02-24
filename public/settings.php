<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_user') {
            $username = trim((string)($_POST['new_username'] ?? ''));
            $password = (string)($_POST['new_password'] ?? '');
            $passwordConfirm = (string)($_POST['new_password_confirm'] ?? '');

            if ($password !== $passwordConfirm) {
                throw new RuntimeException('New user password confirmation does not match.');
            }

            users_create($db, $username, $password);
            $success = 'User created successfully.';
        } elseif ($action === 'delete_user') {
            $deleteUserId = (int)($_POST['delete_user_id'] ?? 0);
            users_delete($db, $deleteUserId, current_user_id());
            $success = 'User deleted successfully.';
        } elseif ($action === 'change_password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['password_new'] ?? '');
            $newPasswordConfirm = (string)($_POST['password_new_confirm'] ?? '');

            if ($newPassword !== $newPasswordConfirm) {
                throw new RuntimeException('New password confirmation does not match.');
            }

            users_change_password($db, current_user_id(), $currentPassword, $newPassword);
            $success = 'Password changed successfully.';
        } else {
            throw new RuntimeException('Unknown settings action.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$users = users_list($db);

render_header('Settings', 'settings');
?>

<div class="card" style="max-width: 920px; margin: 20px auto;">
  <h1>Settings</h1>
  <p class="small">Manage users and maintenance tasks.</p>

  <?php if ($success !== ''): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(110,231,183,0.08); margin-bottom: 12px;">
      <?= h($success) ?>
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="card" style="border-color: var(--danger); background: rgba(251,113,133,0.06); margin-bottom: 12px;">
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <div class="card" style="margin-top: 12px;">
    <h2>Change your password</h2>
    <form method="post" action="/settings.php">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
      <input type="hidden" name="action" value="change_password">

      <div class="grid-2" style="margin-bottom: 12px;">
        <div>
          <label>Current password</label>
          <input class="input" type="password" name="current_password" autocomplete="current-password" required>
        </div>
        <div></div>
        <div>
          <label>New password</label>
          <input class="input" type="password" name="password_new" autocomplete="new-password" minlength="8" required>
        </div>
        <div>
          <label>Confirm new password</label>
          <input class="input" type="password" name="password_new_confirm" autocomplete="new-password" minlength="8" required>
        </div>
      </div>

      <button class="btn" type="submit">Change password</button>
    </form>
  </div>

  <div class="card" style="margin-top: 12px;">
    <h2>Create user</h2>
    <p class="small">Later this can be extended with role assignment and page permissions.</p>
    <form method="post" action="/settings.php">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
      <input type="hidden" name="action" value="create_user">

      <div class="grid-2" style="margin-bottom: 12px;">
        <div>
          <label>Username</label>
          <input class="input" name="new_username" maxlength="50" required>
        </div>
        <div>
          <label>Password</label>
          <input class="input" type="password" name="new_password" minlength="8" required>
        </div>
        <div>
          <label>Confirm password</label>
          <input class="input" type="password" name="new_password_confirm" minlength="8" required>
        </div>
      </div>

      <button class="btn" type="submit">Create user</button>
    </form>
  </div>

  <div class="card" style="margin-top: 12px;">
    <h2>Delete user</h2>
    <p class="small">Deleting a user also removes their imported data, rules, and transactions.</p>

    <form method="post" action="/settings.php" onsubmit="return confirm('Delete this user and all related data?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
      <input type="hidden" name="action" value="delete_user">

      <div style="margin-bottom: 12px;">
        <label>User</label>
        <select class="input" name="delete_user_id" required>
          <option value="">Select user</option>
          <?php foreach ($users as $user): ?>
            <?php $disabled = ((int)$user['id'] === current_user_id()) ? 'disabled' : ''; ?>
            <option value="<?= (int)$user['id'] ?>" <?= $disabled ?>>
              <?= h($user['username']) ?><?= ((int)$user['id'] === current_user_id()) ? ' (you)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <button class="btn btn-danger" type="submit">Delete user</button>
    </form>
  </div>

  <div class="card" style="margin-top: 12px;">
    <h2>Maintenance</h2>
    <div class="row" style="gap: 10px; margin-top: 12px;">
      <a class="btn" href="/rules.php">Rules</a>
      <a class="btn" href="/db-check.php">DB Check</a>
      <a class="btn" href="/schema.php">Schema</a>
      <a class="btn btn-danger" href="/reset.php">Reset DB</a>
      <a class="btn" href="/logout.php">Logout</a>
    </div>
  </div>
</div>

<?php render_footer(); ?>
