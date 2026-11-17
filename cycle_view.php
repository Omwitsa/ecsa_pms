<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM appraisal_cycles WHERE id = ?');
$st->execute([$id]);
$cycle = $st->fetch();
if (!$cycle) {
    flash('Cycle not found.', 'error');
    redirect('cycles.php');
}

$ks = $pdo->prepare('SELECT ck.* FROM cycle_kpis ck WHERE ck.cycle_id = ?' . ck_order());
$ks->execute([$id]);
$ckpis = $ks->fetchAll();
$totalWeight = array_sum(array_map(fn($k) => (float) $k['weight'], $ckpis));

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'open' && $cycle['status'] === 'draft') {
        $people = $pdo->query("SELECT id, manager_id FROM users WHERE is_active = 1 AND role IN ('employee','manager')")->fetchAll();
        if (!$ckpis) {
            flash('Add at least one KPI before opening the cycle.', 'error');
        } elseif (abs($totalWeight - 100) > 0.01) {
            flash('KPI weights must total 100 (currently ' . fmt_num($totalWeight) . '). Edit the cycle to fix them.', 'error');
        } elseif (!$people) {
            flash('There are no active employees or managers to appraise. Add users first.', 'error');
        } else {
            $pdo->beginTransaction();
            try {
                // refresh KPI snapshot (title, category, scale) from the KPI library
                $pdo->prepare('UPDATE cycle_kpis ck
                               JOIN kpis k ON k.id = ck.kpi_id
                               JOIN priorities p ON p.id = k.priority_id
                               JOIN objectives o ON o.id = p.objective_id
                               SET ck.perspective = o.perspective, ck.objective_code = o.code, ck.objective_title = o.title,
                                   ck.priority_code = p.code, ck.priority_title = p.title, ck.key_actions = k.key_actions,
                                   ck.title = k.title, ck.target = k.target, ck.scale_min = k.scale_min, ck.scale_max = k.scale_max,
                                   ck.obj_sort = o.sort_order, ck.pri_sort = p.sort_order, ck.kpi_sort = k.sort_order
                               WHERE ck.cycle_id = ?')->execute([$id]);
                $pdo->prepare("UPDATE appraisal_cycles SET status = 'open' WHERE id = ?")->execute([$id]);

                $ins = $pdo->prepare('INSERT IGNORE INTO appraisals (cycle_id, employee_id, reviewer_id) VALUES (?,?,?)');
                foreach ($people as $p) {
                    $ins->execute([$id, $p['id'], $p['manager_id']]);
                }
                $pdo->prepare('INSERT IGNORE INTO appraisal_scores (appraisal_id, cycle_kpi_id)
                               SELECT a.id, ck.id FROM appraisals a JOIN cycle_kpis ck ON ck.cycle_id = a.cycle_id
                               WHERE a.cycle_id = ?')->execute([$id]);
                $pdo->commit();
                flash('Cycle opened. ' . count($people) . ' appraisals were created.');
            } catch (Throwable $ex) {
                $pdo->rollBack();
                error_log($ex->getMessage());
                flash('Could not open the cycle.', 'error');
            }
        }
    } elseif ($action === 'close' && $cycle['status'] === 'open') {
        $pdo->prepare("UPDATE appraisal_cycles SET status = 'closed' WHERE id = ?")->execute([$id]);
        flash('Cycle closed. Unfinished appraisals are now locked.');
    } elseif ($action === 'reopen' && $cycle['status'] === 'closed') {
        $pdo->prepare("UPDATE appraisal_cycles SET status = 'open' WHERE id = ?")->execute([$id]);
        flash('Cycle reopened.');
    } elseif ($action === 'delete' && $cycle['status'] === 'draft') {
        $pdo->prepare('DELETE FROM appraisal_cycles WHERE id = ?')->execute([$id]);
        flash('Draft cycle deleted.');
        redirect('cycles.php');
    }
    redirect('cycle_view.php?id=' . $id);
}

/* ---------- Appraisals ---------- */
$as = $pdo->prepare('SELECT a.*, e.name AS employee_name, e.email, e.job_title, r.name AS reviewer_name
                     FROM appraisals a JOIN users e ON e.id = a.employee_id LEFT JOIN users r ON r.id = a.reviewer_id
                     WHERE a.cycle_id = ? ORDER BY e.name');
$as->execute([$id]);
$appraisals = $as->fetchAll();

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    $sc = $pdo->prepare('SELECT s.appraisal_id, s.cycle_kpi_id, s.actual, s.self_rating, s.manager_rating
                         FROM appraisal_scores s JOIN appraisals a ON a.id = s.appraisal_id WHERE a.cycle_id = ?');
    $sc->execute([$id]);
    $map = [];
    foreach ($sc->fetchAll() as $s) {
        $map[(int) $s['appraisal_id']][(int) $s['cycle_kpi_id']] = $s;
    }
    $safe = function ($v) {
        $v = (string) $v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="appraisal-cycle-' . $id . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['Employee', 'Email', 'Job title', 'Reviewer', 'Status', 'Final score %', 'Band', 'Completed at'];
    foreach ($ckpis as $k) {
        $label = $k['perspective'] . ' | ' . $k['objective_code'] . ' | ' . $k['title'];
        $head[] = $label . ' - Actual';
        $head[] = $label . ' - Self rating';
        $head[] = $label . ' - Manager rating (weighted x ' . fmt_num($k['weight']) . ')';
    }
    fputcsv($out, array_map($safe, $head));
    foreach ($appraisals as $a) {
        $row = [$a['employee_name'], $a['email'], $a['job_title'], $a['reviewer_name'] ?? '', $a['status'],
                $a['final_score'] ?? '', $a['rating_band'] ?? '', $a['completed_at'] ?? ''];
        foreach ($ckpis as $k) {
            $s = $map[(int) $a['id']][(int) $k['id']] ?? null;
            $row[] = $s['actual'] ?? '';
            $row[] = $s['self_rating'] ?? '';
            $row[] = $s['manager_rating'] ?? '';
        }
        fputcsv($out, array_map($safe, $row));
    }
    fclose($out);
    exit;
}

$counts = ['pending_self' => 0, 'pending_manager' => 0, 'completed' => 0];
foreach ($appraisals as $a) {
    $counts[$a['status']]++;
}

page_header($cycle['name'], 'cycles');
?>
<div class="row-between">
  <div>
    <h1><?= e($cycle['name']) ?> <?= status_badge($cycle['status']) ?></h1>
    <div class="muted"><?= e(fmt_date($cycle['start_date'])) ?> – <?= e(fmt_date($cycle['end_date'])) ?></div>
  </div>
  <div class="actions">
    <?php if ($cycle['status'] === 'draft'): ?>
      <a class="btn" href="cycle_form.php?id=<?= $id ?>">Edit</a>
      <form method="post"><?= csrf_field() ?><button class="btn primary" name="action" value="open"
        onclick="return confirm('Open this cycle? Appraisals will be created for every active employee and manager.');">Open cycle</button></form>
      <form method="post" onsubmit="return confirm('Delete this draft cycle?');"><?= csrf_field() ?><button class="btn danger" name="action" value="delete">Delete</button></form>
    <?php elseif ($cycle['status'] === 'open'): ?>
      <form method="post" onsubmit="return confirm('Close this cycle? Unfinished appraisals will be locked.');"><?= csrf_field() ?><button class="btn" name="action" value="close">Close cycle</button></form>
    <?php else: ?>
      <form method="post"><?= csrf_field() ?><button class="btn" name="action" value="reopen">Reopen</button></form>
    <?php endif; ?>
    <?php if ($appraisals): ?><a class="btn" href="cycle_view.php?id=<?= $id ?>&export=csv">Export CSV</a><?php endif; ?>
  </div>
</div>
<?php if ($cycle['description'] !== ''): ?><p><?= nl2br(e($cycle['description'])) ?></p><?php endif; ?>

<?php if ($cycle['status'] === 'draft' && abs($totalWeight - 100) > 0.01): ?>
  <div class="alert alert-warn">KPI weights total <?= e(fmt_num($totalWeight)) ?>. They must total 100 before this cycle can be opened.</div>
<?php endif; ?>

<div class="card">
  <h2>KPIs in this cycle</h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Perspective</th><th>Objective › Priority</th><th>Measure / KPI</th><th>Target</th><th>Scale</th><th>Weight</th></tr></thead>
    <tbody>
    <?php foreach ($ckpis as $k): ?>
      <tr>
        <td class="small"><?= e($k['perspective']) ?></td>
        <td class="small">Obj. <?= e($k['objective_code']) ?> › <?= e($k['priority_code']) ?></td>
        <td><?= e($k['title']) ?></td>
        <td class="small"><?= e($k['target'] ?: '—') ?></td>
        <td><?= (int) $k['scale_min'] ?>–<?= (int) $k['scale_max'] ?></td>
        <td><?= e(fmt_num($k['weight'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="5" style="text-align:right"><strong>Total</strong></td><td><strong><?= e(fmt_num($totalWeight)) ?></strong></td></tr></tfoot>
  </table>
  </div>
  <div class="chips">
    <?php foreach (cfg('perspectives') as $pn):
        $pw = 0.0; foreach ($ckpis as $k) { if ($k['perspective'] === $pn) { $pw += (float) $k['weight']; } } ?>
      <span><?= e($pn) ?>: <strong><?= e(fmt_num($pw)) ?></strong></span>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($cycle['status'] !== 'draft'): ?>
<div class="stats">
  <div class="stat"><span><?= $counts['pending_self'] ?></span>Awaiting self-review</div>
  <div class="stat"><span><?= $counts['pending_manager'] ?></span>Awaiting manager review</div>
  <div class="stat ok"><span><?= $counts['completed'] ?></span>Completed</div>
</div>

<div class="card">
  <h2>Appraisals</h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Employee</th><th>Reviewer</th><th>Status</th><th>Score</th><th>Band</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($appraisals as $a): ?>
      <tr>
        <td><strong><?= e($a['employee_name']) ?></strong><div class="muted small"><?= e($a['job_title']) ?></div></td>
        <td><?= e($a['reviewer_name'] ?: 'Admin') ?></td>
        <td><?= status_badge($a['status']) ?></td>
        <td><?= $a['final_score'] !== null ? e(fmt_num($a['final_score'])) . '%' : '—' ?></td>
        <td><?= e($a['rating_band'] ?: '—') ?></td>
        <td class="actions"><a class="btn small" href="appraisal.php?id=<?= (int) $a['id'] ?>">
          <?= $a['status'] === 'pending_manager' && $cycle['status'] === 'open' ? 'Review' : 'View' ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php page_footer();
