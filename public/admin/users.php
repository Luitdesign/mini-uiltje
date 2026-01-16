<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/repo.php';
require_login();
require_admin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $email = trim((string)($_POST['email'] ?? ''));
            $role = (string)($_POST['role'] ?? 'user');
            $pass = (string)($_POST['password'] ?? '');
            if ($email === '' || $pass === '') throw new RuntimeException('Email and password are required');
            if (!in_array($role, ['admin','user'], true)) $role='user';
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = db()->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
            $stmt->execute([$email, $hash, $role]);
            flash_set('User created.', 'info');
        } elseif ($action === 'reset') {
            $id = (int)($_POST['id'] ?? 0);
            $pass = (string)($_POST['password'] ?? '');
            if ($id <= 0 || $pass === '') throw new RuntimeException('User and new password required');
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $id]);
            flash_set('Password reset.', 'info');
        }
        redirect('/admin/users.php');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$users = db()->query('SELECT id, email, role, created_at FROM users ORDER BY id')->fetchAll();
render_header('Users');
?>
<div class="card">
  <h2>Users</h2>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>

  <table class="table">
    <thead><tr><th>ID</th><th>Email</th><th>Role</th><th>Created</th><th>Reset password</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?=h((string)$u['id'])?></td>
        <td><?=h($u['email'])?></td>
        <td><?=h($u['role'])?></td>
        <td class="small muted"><?=h((string)$u['created_at'])?></td>
        <td>
          <form method="post" style="display:flex;gap:8px;align-items:end">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="id" value="<?=h((string)$u['id'])?>">
            <div style="min-width:220px">
              <label>New password</label>
              <input type="password" name="password" required>
            </div>
            <button class="btn" type="submit">Reset</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h3 style="margin-top:18px">Add user</h3>
  <form method="post">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="add">
    <div class="row">
      <div class="col">
        <label>Email</label>
        <input type="email" name="email" required>
      </div>
      <div class="col">
        <label>Role</label>
        <select name="role">
          <option value="user">user</option>
          <option value="admin">admin</option>
        </select>
      </div>
      <div class="col">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
    </div>
    <div style="margin-top:12px"><button class="btn primary" type="submit">Create user</button></div>
  </form>
</div>
<?php render_footer();
