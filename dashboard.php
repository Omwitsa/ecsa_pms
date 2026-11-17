<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$total  = (int) $pdo->query('SELECT COUNT(*) FROM kpis')->fetchColumn();
$active = (int) $pdo->query('SELECT COUNT(*) FROM kpis WHERE is_active = 1')->fetchColumn();
$weight = (float) $pdo->query('SELECT COALESCE(SUM(weight),0) FROM kpis WHERE is_active = 1')->fetchColumn();
$people = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role IN ('employee','manager')")->fetchColumn();
$rowsP  = $pdo->query('SELECT o.perspective, COUNT(DISTINCT o.id) AS objectives, COUNT(k.id) AS n, COALESCE(SUM(k.weight),0) AS w
                       FROM objectives o
                       LEFT JOIN priorities p ON p.objective_id = o.id
                       LEFT JOIN kpis k ON k.priority_id = p.id AND k.is_active = 1
                       GROUP BY o.perspective')->fetchAll();
$open   = $pdo->query("SELECT c.id, c.name, c.end_date,
                         (SELECT COUNT(*) FROM appraisals a WHERE a.cycle_id = c.id) AS total,
                         (SELECT COUNT(*) FROM appraisals a WHERE a.cycle_id = c.id AND a.status = 'completed') AS done
                       FROM appraisal_cycles c WHERE c.status = 'open' ORDER BY c.end_date")->fetchAll();

$persp = [];
foreach (cfg('perspectives') as $p) {
    $persp[$p] = ['objectives' => 0, 'n' => 0, 'w' => 0.0];
}
foreach ($rowsP as $r) {
    if (isset($persp[$r['perspective']])) {
        $persp[$r['perspective']] = ['objectives' => (int) $r['objectives'], 'n' => (int) $r['n'], 'w' => (float) $r['w']];
    }
}

page_header('Dashboard', 'dashboard');
$weightOk = abs($weight - 100) < 0.01;
?>
<h1>Dashboard</h1>
<div class="stats">
  <div class="stat"><span><?= $active ?>/<?= $total ?></span>Active / total KPIs</div>
  <div class="stat <?= $weightOk ? 'ok' : 'warn' ?>"><span><?= e(fmt_num($weight)) ?></span>Total active KPI weight (target 100)</div>
  <div class="stat"><span><?= $people ?></span>People to appraise</div>
</div>

<div class="card">
  <div class="row-between"><h2>Open appraisal cycles</h2><a class="btn small" href="cycles.php">All cycles</a></div>
  <?php if (!$open): ?>
    <p class="muted">No cycle is open. <a href="cycle_form.php">Create an appraisal cycle</a>.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Cycle</th><th>Ends</th><th>Progress</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($open as $o): $pct = $o['total'] ? round($o['done'] / $o['total'] * 100) : 0; ?>
      <tr>
        <td><?= e($o['name']) ?></td>
        <td><?= e(fmt_date($o['end_date'])) ?></td>
        <td><div class="bar"><i style="width:<?= $pct ?>%"></i></div><span class="small muted"><?= (int) $o['done'] ?> of <?= (int) $o['total'] ?> completed</span></td>
        <td><a class="btn small" href="cycle_view.php?id=<?= (int) $o['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Active KPIs by perspective</h2>
  <table>
    <thead><tr><th>Perspective</th><th>Objectives</th><th>Active KPIs</th><th>Sub-total weight</th></tr></thead>
    <tbody>
    <?php foreach ($persp as $name => $p): ?>
      <tr><td><?= e($name) ?></td><td><?= $p['objectives'] ?></td><td><?= $p['n'] ?></td><td><?= e(fmt_num($p['w'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($total === 0): ?><p class="muted">Start by adding <a href="structure.php">objectives and strategic priorities</a>, then <a href="kpi_form.php">KPIs</a>.</p><?php endif; ?>
  <p class="small muted">Weights here are defaults. Each appraisal cycle has its own weights, which must total 100.</p>
</div>
<?php page_footer();
