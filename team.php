<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('manager');
$pdo  = db();

$st = $pdo->prepare('SELECT a.*, c.name AS cycle_name, c.end_date, c.status AS cycle_status, e.name AS employee_name, e.job_title
                     FROM appraisals a JOIN appraisal_cycles c ON c.id = a.cycle_id JOIN users e ON e.id = a.employee_id
                     WHERE a.reviewer_id = ? ORDER BY (a.status = \'pending_manager\') DESC, c.start_date DESC, e.name');
$st->execute([$user['id']]);
$list = $st->fetchAll();

page_header('Team reviews', 'team');
?>
<h1>Team reviews</h1>
<div class="card">
<?php if (!$list): ?>
  <p class="muted">No team appraisals yet. Make sure your team members have you set as their manager.</p>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead><tr><th>Employee</th><th>Cycle</th><th>Due</th><th>Status</th><th>Result</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $a):
      $canReview = $a['status'] === 'pending_manager' && $a['cycle_status'] === 'open'; ?>
    <tr>
      <td><strong><?= e($a['employee_name']) ?></strong><div class="muted small"><?= e($a['job_title']) ?></div></td>
      <td><?= e($a['cycle_name']) ?></td>
      <td><?= e(fmt_date($a['end_date'])) ?></td>
      <td><?= status_badge($a['status']) ?></td>
      <td><?= $a['status'] === 'completed' ? e(fmt_num($a['final_score'])) . '% · ' . e($a['rating_band']) : '—' ?></td>
      <td class="actions"><a class="btn small <?= $canReview ? 'primary' : '' ?>" href="appraisal.php?id=<?= (int) $a['id'] ?>"><?= $canReview ? 'Review' : 'View' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php page_footer();
