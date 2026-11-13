<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        if ($action === 'toggle') {
            $pdo->prepare('UPDATE kpis SET is_active = 1 - is_active, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
            flash('KPI status updated.');
        } elseif ($action === 'delete') {
            try {
                $pdo->prepare('DELETE FROM kpis WHERE id = ?')->execute([$id]);
                flash('KPI deleted.');
            } catch (PDOException $ex) {
                flash('This KPI is used in an appraisal cycle and cannot be deleted. Deactivate it instead.', 'error');
            }
        } elseif ($action === 'duplicate') {
            $pdo->beginTransaction();
            try {
                $k = $pdo->prepare('SELECT * FROM kpis WHERE id = ?');
                $k->execute([$id]);
                $kpi = $k->fetch();
                if ($kpi) {
                    $pdo->prepare('INSERT INTO kpis (title, description, category, measurement, target, weight, scale_min, scale_max, is_active)
                                   VALUES (?,?,?,?,?,?,?,?,0)')
                        ->execute([$kpi['title'] . ' (copy)', $kpi['description'], $kpi['category'], $kpi['measurement'],
                                   $kpi['target'], $kpi['weight'], $kpi['scale_min'], $kpi['scale_max']]);
                    $newId = (int) $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO kpi_rating_levels (kpi_id, rating, label, description)
                                   SELECT ?, rating, label, description FROM kpi_rating_levels WHERE kpi_id = ?')
                        ->execute([$newId, $id]);
                    flash('KPI duplicated (inactive until you activate it).');
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                flash('Could not duplicate KPI.', 'error');
            }
        }
    }
    redirect('kpis.php');
}

$kpis = $pdo->query('SELECT * FROM kpis ORDER BY category, title')->fetchAll();

page_header('KPIs', 'kpis');
?>
<div class="row-between">
  <h1>Key Performance Indicators</h1>
  <a class="btn primary" href="kpi_form.php">+ Add KPI</a>
</div>

<div class="card">
<?php if (!$kpis): ?>
  <p class="muted">No KPIs defined yet.</p>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead>
    <tr><th>KPI / Objective</th><th>Category</th><th>Target</th><th>Weight</th><th>Scale</th><th>Status</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($kpis as $k): ?>
    <tr class="<?= $k['is_active'] ? '' : 'inactive' ?>">
      <td><strong><?= e($k['title']) ?></strong>
          <?php if ($k['description'] !== ''): ?><div class="muted small"><?= e(mb_strimwidth($k['description'], 0, 110, '…')) ?></div><?php endif; ?></td>
      <td><?= e($k['category']) ?></td>
      <td><?= e($k['target'] ?: '—') ?></td>
      <td><?= e(rtrim(rtrim(number_format((float) $k['weight'], 2), '0'), '.')) ?>%</td>
      <td><?= (int) $k['scale_min'] ?>–<?= (int) $k['scale_max'] ?></td>
      <td><span class="badge <?= $k['is_active'] ? 'on' : 'off' ?>"><?= $k['is_active'] ? 'Active' : 'Inactive' ?></span></td>
      <td class="actions">
        <a class="btn small" href="kpi_form.php?id=<?= (int) $k['id'] ?>">Edit</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <button class="btn small" name="action" value="toggle"><?= $k['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <button class="btn small" name="action" value="duplicate">Duplicate</button></form>
        <form method="post" onsubmit="return confirm('Delete this KPI and its rating scale? This cannot be undone.');">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <button class="btn small danger" name="action" value="delete">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php page_footer();
