<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$savings = repo_list_savings($db);

render_header('Savings', 'savings');
?>

<div class="card">
  <div>
    <h1>Savings</h1>
    <p class="small">Track your savings buckets and monthly contributions.</p>
  </div>

  <?php if (empty($savings)): ?>
    <div class="small muted">No savings goals yet.</div>
  <?php endif; ?>

  <?php if (!empty($savings)): ?>
    <table class="table" style="margin-top: 12px;">
      <thead>
        <tr>
          <th>Name</th>
          <th style="width: 90px;">Active</th>
          <th style="width: 110px;">Sort order</th>
          <th style="width: 160px;">Start amount</th>
          <th style="width: 200px;">Default monthly amount</th>
          <th style="width: 160px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savings as $saving): ?>
          <tr>
            <td><?= h((string)$saving['name']) ?></td>
            <td><?= !empty($saving['active']) ? 'Yes' : 'No' ?></td>
            <td><?= h((string)$saving['sort_order']) ?></td>
            <td class="money"><?= number_format((float)$saving['start_amount'], 2, ',', '.') ?></td>
            <td class="money"><?= number_format((float)$saving['monthly_amount'], 2, ',', '.') ?></td>
            <td>
              <div class="row" style="gap: 6px; flex-wrap: wrap;">
                <a class="btn" href="/savings.php?edit=<?= h((string)$saving['id']) ?>">Edit</a>
                <button class="btn" type="button" disabled>
                  <?= !empty($saving['active']) ? 'Deactivate' : 'Activate' ?>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
