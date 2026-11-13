<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$cycles = $pdo->query("SELECT c.*,
    (SELECT COUNT(*) FROM cycle_kpis k WHERE k.cycle_id = c.id) AS kpi_count,
    (SELECT COUNT(*) FROM appraisals a WHERE a.cycle_id = c.id) AS total,
    (SELECT COUNT(*) FROM appraisals a WHERE a.cycle_id = c.id AND a.status = 'completed') AS done
  FROM appraisal_cycles c ORDER BY c.start_date DESC, c.id DESC")->fetchAll();

page_header('Appraisal cycles', 'cycles');
?>
<div class="row-between">
  <h1>Appraisal cycles</h1>
  <a class="btn primary" href="cycle_form.php">+ New cycle</a>
</div>

<div class="card">
<?php if (!$cycles): ?>
  <p class="muted">No cycles yet. A cycle picks the KPIs (and their weights) used to appraise everyone over a period.</p>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead><tr><th>Cycle</th><th>Period</th><th>KPIs</th><th>Status</th><th>Completed</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($cycles as $c): ?>
    <tr>
      <td><strong><?= e($c['name']) ?></strong></td>
      <td><?= e(fmt_date($c['start_date'])) ?> – <?= e(fmt_date($c['end_date'])) ?></td>
      <td><?= (int) $c['kpi_count'] ?></td>
      <td><?= status_badge($c['status']) ?></td>
      <td><?= (int) $c['done'] ?> / <?= (int) $c['total'] ?></td>
      <td class="actions"><a class="btn small" href="cycle_view.php?id=<?= (int) $c['id'] ?>">Manage</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php page_footer();
