<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('employee', 'manager');
$pdo  = db();

$st = $pdo->prepare('SELECT a.*, c.name AS cycle_name, c.start_date, c.end_date, c.status AS cycle_status, r.name AS reviewer_name
                     FROM appraisals a JOIN appraisal_cycles c ON c.id = a.cycle_id LEFT JOIN users r ON r.id = a.reviewer_id
                     WHERE a.employee_id = ? ORDER BY c.start_date DESC, a.id DESC');
$st->execute([$user['id']]);
$list = $st->fetchAll();

page_header('My appraisals', 'mine');
?>
<h1>My appraisals</h1>
<div class="card">
<?php if (!$list): ?>
  <p class="muted">You have no appraisals yet. They appear here when an appraisal cycle is opened.</p>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead><tr><th>Cycle</th><th>Period</th><th>Reviewer</th><th>Status</th><th>Result</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $a):
      $canSelf = $a['status'] === 'pending_self' && $a['cycle_status'] === 'open'; ?>
    <tr>
      <td><strong><?= e($a['cycle_name']) ?></strong></td>
      <td><?= e(fmt_date($a['start_date'])) ?> – <?= e(fmt_date($a['end_date'])) ?></td>
      <td><?= e($a['reviewer_name'] ?: 'Admin') ?></td>
      <td><?= status_badge($a['status']) ?><?= $a['cycle_status'] === 'closed' && $a['status'] !== 'completed' ? ' <span class="badge dark">Cycle closed</span>' : '' ?></td>
      <td><?= $a['status'] === 'completed' ? e(fmt_num($a['final_score'])) . '% · ' . e($a['rating_band']) : '—' ?></td>
      <td class="actions"><a class="btn small <?= $canSelf ? 'primary' : '' ?>" href="appraisal.php?id=<?= (int) $a['id'] ?>"><?= $canSelf ? 'Start self-review' : 'View' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php page_footer();
