<?php
require __DIR__ . '/includes/bootstrap.php';
$me  = require_admin();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$u  = ['name' => '', 'email' => '', 'role' => 'employee', 'job_title' => '', 'manager_id' => '', 'is_active' => 1];

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $found = $st->fetch();
    if (!$found) {
        flash('User not found.', 'error');
        redirect('users.php');
    }
    $u = $found;
}
$isSelf = $id === $me['id'];

$mq = $pdo->prepare("SELECT id, name, role FROM users WHERE is_active = 1 AND role IN ('manager','admin') AND id <> ? ORDER BY name");
$mq->execute([$id]);
$managers = $mq->fetchAll();
$managerIds = array_map('intval', array_column($managers, 'id'));

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $u['name']       = trim((string) ($_POST['name'] ?? ''));
    $u['email']      = trim((string) ($_POST['email'] ?? ''));
    $u['job_title']  = trim((string) ($_POST['job_title'] ?? ''));
    $role            = (string) ($_POST['role'] ?? 'employee');
    $mgr             = (int) ($_POST['manager_id'] ?? 0);
    $active          = isset($_POST['is_active']) ? 1 : 0;
    $password        = (string) ($_POST['password'] ?? '');

    if ($isSelf) {            // never let an admin lock themselves out
        $role = 'admin';
        $active = 1;
    }
    $u['role'] = $role;
    $u['is_active'] = $active;
    $u['manager_id'] = $mgr ?: '';

    if ($u['name'] === '') {
        $errors[] = 'Name is required.';
    }
    if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    } else {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $dup->execute([$u['email'], $id]);
        if ((int) $dup->fetchColumn() > 0) {
            $errors[] = 'That email is already in use.';
        }
    }
    if (!in_array($role, ['admin', 'manager', 'employee'], true)) {
        $errors[] = 'Invalid role.';
    }
    if ($mgr && !in_array($mgr, $managerIds, true)) {
        $errors[] = 'Choose a valid manager.';
    }
    if ($id === 0 && strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    if (!$errors) {
        if ($id > 0) {
            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, job_title=?, manager_id=?, is_active=? WHERE id=?')
                ->execute([$u['name'], $u['email'], $role, $u['job_title'], $mgr ?: null, $active, $id]);
            if ($password !== '') {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            flash('User updated.');
        } else {
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role, job_title, manager_id, is_active) VALUES (?,?,?,?,?,?,?)')
                ->execute([$u['name'], $u['email'], password_hash($password, PASSWORD_DEFAULT), $role, $u['job_title'], $mgr ?: null, $active]);
            flash('User created.');
        }
        redirect('users.php');
    }
}

page_header($id ? 'Edit user' : 'Add user', 'users');
?>
<h1><?= $id ? 'Edit user' : 'Add user' ?></h1>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="card" autocomplete="off">
  <?= csrf_field() ?>
  <div class="grid">
    <label>Full name *
      <input type="text" name="name" value="<?= e($u['name']) ?>" maxlength="120" required>
    </label>
    <label>Email *
      <input type="email" name="email" value="<?= e($u['email']) ?>" maxlength="190" required>
    </label>
    <label>Role
      <select name="role" <?= $isSelf ? 'disabled' : '' ?>>
        <?php foreach (['employee' => 'Employee', 'manager' => 'Manager (reviews their team)', 'admin' => 'Admin'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $u['role'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Job title
      <input type="text" name="job_title" value="<?= e($u['job_title']) ?>" maxlength="120">
    </label>
    <label>Manager (reviewer)
      <select name="manager_id">
        <option value="">— None (admin reviews) —</option>
        <?php foreach ($managers as $m): ?>
          <option value="<?= (int) $m['id'] ?>" <?= (int) $u['manager_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= e($m['role']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= $id ? 'New password (leave blank to keep)' : 'Password * (min 8 characters)' ?>
      <input type="password" name="password" minlength="8" <?= $id ? '' : 'required' ?> autocomplete="new-password">
    </label>
    <label class="check"><input type="checkbox" name="is_active" value="1" <?= $u['is_active'] ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>> Active</label>
  </div>
  <div class="form-actions">
    <button class="btn primary" type="submit">Save user</button>
    <a class="btn" href="users.php">Cancel</a>
  </div>
</form>
<?php page_footer();
