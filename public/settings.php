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
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            if ($targetUserId !== current_user_id()) {
                throw new RuntimeException('You can only change your own password.');
            }

            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['password_new'] ?? '');
            $newPasswordConfirm = (string)($_POST['password_new_confirm'] ?? '');

            if ($newPassword !== $newPasswordConfirm) {
                throw new RuntimeException('New password confirmation does not match.');
            }

            users_change_password($db, $targetUserId, $currentPassword, $newPassword);
            $success = 'Password changed successfully.';
        } elseif ($action === 'change_username') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $newUsername = trim((string)($_POST['target_username'] ?? ''));

            users_change_username($db, $targetUserId, $newUsername);

            if ($targetUserId === current_user_id()) {
                $_SESSION['username'] = $newUsername;
            }

            $success = 'Username updated successfully.';
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

<div class="card" style="max-width: 980px; margin: 20px auto;">
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
    <h2>Users</h2>
    <p class="small">Edit username, change your own password, delete users, and (for admin only) manage roles later.</p>

    <div style="overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid var(--border);">Username</th>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid var(--border);">Created</th>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid var(--border);">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <?php
              $userId = (int)$user['id'];
              $isCurrentUser = ($userId === current_user_id());
            ?>
            <tr>
              <td style="padding: 8px; border-bottom: 1px solid var(--border);">
                <?= h($user['username']) ?><?= $isCurrentUser ? ' <span class="small">(you)</span>' : '' ?>
              </td>
              <td style="padding: 8px; border-bottom: 1px solid var(--border);">
                <?= h((string)$user['created_at']) ?>
              </td>
              <td style="padding: 8px; border-bottom: 1px solid var(--border);">
                <div class="row" style="gap: 8px; flex-wrap: wrap;">
                  <details>
                    <summary class="btn" style="cursor: pointer;">Edit login</summary>
                    <form method="post" action="/settings.php" style="margin-top: 8px; min-width: 260px;">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                      <input type="hidden" name="action" value="change_username">
                      <input type="hidden" name="target_user_id" value="<?= $userId ?>">
                      <input class="input" name="target_username" maxlength="50" value="<?= h($user['username']) ?>" required>
                      <button class="btn" type="submit" style="margin-top: 8px;">Save login</button>
                    </form>
                  </details>

                  <?php if ($isCurrentUser): ?>
                    <details>
                      <summary class="btn" style="cursor: pointer;">Change password</summary>
                      <form method="post" action="/settings.php" style="margin-top: 8px; min-width: 280px;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="target_user_id" value="<?= $userId ?>">
                        <label>Current password</label>
                        <input class="input" type="password" name="current_password" autocomplete="current-password" required>
                        <label>New password</label>
                        <input class="input" type="password" name="password_new" autocomplete="new-password" minlength="8" required>
                        <label>Confirm new password</label>
                        <input class="input" type="password" name="password_new_confirm" autocomplete="new-password" minlength="8" required>
                        <button class="btn" type="submit" style="margin-top: 8px;">Save password</button>
                      </form>
                    </details>
                  <?php endif; ?>

                  <form method="post" action="/settings.php" onsubmit="return confirm('Delete this user and all related data?');" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token($config)) ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="delete_user_id" value="<?= $userId ?>">
                    <button class="btn btn-danger" type="submit" <?= $isCurrentUser ? 'disabled' : '' ?>>Delete</button>
                  </form>

                  <?php if (is_admin_user()): ?>
                    <button class="btn" type="button" disabled title="Role management will be added later.">Change role (soon)</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top: 12px;">
    <h2>Create user</h2>
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
