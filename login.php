<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect(home_url());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    $st = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $st->execute([$email]);
    $u = $st->fetch();

    if ($u && password_verify($pass, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role']];
        redirect(home_url());
    }
    $error = 'Invalid email or password.';
    usleep(400000); // slow down brute-force attempts slightly
}

page_header('Sign in');
?>
<div class="card narrow">
  <h1>Sign in</h1>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <button class="btn primary" type="submit">Sign in</button>
  </form>
</div>
<?php page_footer();
