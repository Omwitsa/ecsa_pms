<?php
function page_header(string $title, string $active = ''): void
{
    $user = current_user();
    $nav  = [];
    if ($user) {
        if ($user['role'] === 'admin') {
            $nav = [
                'dashboard' => ['dashboard.php', 'Dashboard'],
                'kpis'      => ['kpis.php', 'KPIs'],
                'cycles'    => ['cycles.php', 'Appraisal cycles'],
                'users'     => ['users.php', 'Users'],
                'preview'   => ['preview.php', 'Scorecard preview'],
            ];
        } else {
            $nav['mine'] = ['my_appraisals.php', 'My appraisals'];
            if ($user['role'] === 'manager') {
                $nav['team'] = ['team.php', 'Team reviews'];
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(cfg('app_name')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand"><?= e(cfg('app_name')) ?></div>
  <?php if ($user): ?>
  <nav>
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="user"><?= e($user['name']) ?> (<?= e($user['role']) ?>) · <a href="logout.php">Log out</a></div>
  <?php endif; ?>
</header>
<main class="container">
<?php
    if ($f = flash()) {
        echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['message']) . '</div>';
    }
}

function page_footer(): void
{
    echo "</main>\n</body>\n</html>";
}
