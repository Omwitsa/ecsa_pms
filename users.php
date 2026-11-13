<?php
require __DIR__ . '/includes/bootstrap.php';
$me  = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'toggle' && $id > 0) {
        if ($id === $me['id']) {
            flash('You cannot deactivate your own account.', 'error');
        } else {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            flash('User status updated.');
        }
    }
    redirect('users.php');
}

$users = $pdo->query('SELECT u.*, m.name AS manager_name FROM users u LEFT JOIN users m ON m.id = u.manager_id
                      ORDER BY u.is_active DESC, u.name')->fetchAll();

page_header('Users', 'users');
?>
<div class="row-between">
  <h1>Users</h1>
  <a class="btn primary" href="user_form.php">+ Add user</a>
</div>
<p class="muted">Employees and managers are included in appraisal cycles. Each person's <strong>manager</strong> becomes the reviewer of their appraisal.</p>

<div class="card">
<div class="table-wrap">
<table>
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Job title</th><th>Manager</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr class="<?= $u['is_active'] ? '' : 'inactive' ?>">
      <td><strong><?= e($u['name']) ?></strong></td>
      <td><?= e($u['email']) ?></td>
      <td><?= e(ucfirst($u['role'])) ?></td>
      <td><?= e($u['job_title'] ?: '—') ?></td>
      <td><?= e($u['manager_name'] ?: '—') ?></td>
      <td><span class="badge <?= $u['is_active'] ? 'on' : 'off' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td class="actions">
        <a class="btn small" href="user_form.php?id=<?= (int) $u['id'] ?>">Edit</a>
        <?php if ((int) $u['id'] !== $me['id']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button class="btn small" name="action" value="toggle"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php page_footer();
