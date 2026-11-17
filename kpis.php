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
            $pdo->prepare('UPDATE kpis SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
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
                    $pdo->prepare('INSERT INTO kpis (priority_id, title, key_actions, description, target, weight, scale_min, scale_max, sort_order, is_active)
                                   VALUES (?,?,?,?,?,?,?,?,?,0)')
                        ->execute([$kpi['priority_id'], mb_substr($kpi['title'], 0, 490) . ' (copy)', $kpi['key_actions'], $kpi['description'],
                                   $kpi['target'], $kpi['weight'], $kpi['scale_min'], $kpi['scale_max'], $kpi['sort_order']]);
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

$rows = $pdo->query(kpi_tree_select() . kpi_tree_order())->fetchAll();
$byP = [];
foreach ($rows as $r) {
    $byP[$r['perspective']][] = $r;
}

page_header('KPIs', 'kpis');
$grand = 0.0;
?>
<div class="row-between">
  <h1>Key Performance Indicators</h1>
  <a class="btn primary" href="kpi_form.php">+ Add KPI</a>
</div>
<p class="muted">Each KPI (measure) belongs to a strategic priority under an objective and a perspective, and has its own target, weight and rating scale. Sub-totals count active KPIs only.</p>

<?php foreach (cfg('perspectives') as $pname):
    $list = $byP[$pname] ?? [];
    $sub = 0.0;
    foreach ($list as $r) { if ($r['is_active']) { $sub += (float) $r['weight']; } }
    $grand += $sub; ?>
  <h2 class="cat"><?= e($pname) ?></h2>
  <div class="card">
  <?php if (!$list): ?>
    <p class="muted">No KPIs yet in this perspective.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Measure / KPI</th><th>Key actions / initiatives</th><th>Target</th><th>Weight</th><th>Scale</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php $last = null; foreach ($list as $k):
        $key = $k['objective_code'] . '|' . $k['priority_code'];
        if ($key !== $last): $last = $key; ?>
      <tr class="grp"><td colspan="7"><strong>Objective <?= e($k['objective_code']) ?></strong> – <?= e($k['objective_title']) ?>
        &nbsp;›&nbsp; <strong><?= e($k['priority_code']) ?></strong> <?= e($k['priority_title']) ?></td></tr>
    <?php endif; ?>
      <tr class="<?= $k['is_active'] ? '' : 'inactive' ?>">
        <td><?= e($k['title']) ?></td>
        <td class="small"><?= nl2br(e(mb_strimwidth($k['key_actions'], 0, 140, '…'))) ?: '—' ?></td>
        <td><?= e($k['target'] ?: '—') ?></td>
        <td><?= e(fmt_num($k['weight'])) ?></td>
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
    <tfoot><tr><td colspan="3" style="text-align:right"><strong>Sub-total</strong></td><td colspan="4"><strong><?= e(fmt_num($sub)) ?></strong></td></tr></tfoot>
  </table>
  </div>
  <?php endif; ?>
  </div>
<?php endforeach; ?>
<p><strong>Total active weight: <?= e(fmt_num($grand)) ?></strong> <span class="muted">(a cycle's weights must total 100)</span></p>
<?php page_footer();
