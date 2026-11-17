<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$id    = (int) ($_GET['id'] ?? 0);
$cycle = ['name' => '', 'description' => '', 'start_date' => '', 'end_date' => ''];
$selected = []; // kpi_id => weight

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM appraisal_cycles WHERE id = ?');
    $st->execute([$id]);
    $found = $st->fetch();
    if (!$found) {
        flash('Cycle not found.', 'error');
        redirect('cycles.php');
    }
    if ($found['status'] !== 'draft') {
        flash('Only draft cycles can be edited.', 'error');
        redirect('cycle_view.php?id=' . $id);
    }
    $cycle = $found;
    $sk = $pdo->prepare('SELECT kpi_id, weight FROM cycle_kpis WHERE cycle_id = ?');
    $sk->execute([$id]);
    foreach ($sk->fetchAll() as $r) {
        $selected[(int) $r['kpi_id']] = (float) $r['weight'];
    }
}

// KPIs that can be chosen: all active ones plus any already in this cycle
$allKpis = $pdo->query(kpi_tree_select() . kpi_tree_order())->fetchAll();
$kpis = array_values(array_filter($allKpis, fn($k) => $k['is_active'] || isset($selected[(int) $k['id']])));
$kpiById = [];
foreach ($kpis as $k) {
    $kpiById[(int) $k['id']] = $k;
}

$validDate = function (string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
};

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $cycle['name']        = trim((string) ($_POST['name'] ?? ''));
    $cycle['description'] = trim((string) ($_POST['description'] ?? ''));
    $cycle['start_date']  = trim((string) ($_POST['start_date'] ?? ''));
    $cycle['end_date']    = trim((string) ($_POST['end_date'] ?? ''));

    if ($cycle['name'] === '') {
        $errors[] = 'Cycle name is required.';
    }
    if (!$validDate($cycle['start_date']) || !$validDate($cycle['end_date'])) {
        $errors[] = 'Enter valid start and end dates.';
    } elseif ($cycle['end_date'] < $cycle['start_date']) {
        $errors[] = 'The end date cannot be before the start date.';
    }

    $pickedKpis = (array) ($_POST['kpi'] ?? []);
    $weights    = (array) ($_POST['weight'] ?? []);
    $selected   = [];
    foreach ($kpiById as $kid => $k) {
        if (!isset($pickedKpis[$kid])) {
            continue;
        }
        $w = trim((string) ($weights[$kid] ?? ''));
        if (!is_numeric($w) || (float) $w < 0 || (float) $w > 100) {
            $errors[] = 'Weight for "' . mb_strimwidth($k['title'], 0, 60, '…') . '" must be between 0 and 100.';
            $w = 0;
        }
        $selected[$kid] = (float) $w;
    }
    if (!$selected) {
        $errors[] = 'Select at least one KPI for this cycle.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE appraisal_cycles SET name=?, description=?, start_date=?, end_date=? WHERE id=?')
                    ->execute([$cycle['name'], $cycle['description'], $cycle['start_date'], $cycle['end_date'], $id]);
                $pdo->prepare('DELETE FROM cycle_kpis WHERE cycle_id = ?')->execute([$id]);
                $cid = $id;
            } else {
                $pdo->prepare('INSERT INTO appraisal_cycles (name, description, start_date, end_date) VALUES (?,?,?,?)')
                    ->execute([$cycle['name'], $cycle['description'], $cycle['start_date'], $cycle['end_date']]);
                $cid = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO cycle_kpis (cycle_id, kpi_id, perspective, objective_code, objective_title, priority_code, priority_title,
                                  key_actions, title, target, weight, scale_min, scale_max, obj_sort, pri_sort, kpi_sort)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($selected as $kid => $w) {
                $k = $kpiById[$kid];
                $ins->execute([$cid, $kid, $k['perspective'], $k['objective_code'], $k['objective_title'], $k['priority_code'], $k['priority_title'],
                               $k['key_actions'], $k['title'], $k['target'], $w, $k['scale_min'], $k['scale_max'],
                               $k['obj_sort'], $k['pri_sort'], $k['sort_order']]);
            }
            $pdo->commit();
            flash('Cycle saved. Review the weights, then open it to start appraisals.');
            redirect('cycle_view.php?id=' . $cid);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Database error: could not save the cycle.';
        }
    }
}

page_header($id ? 'Edit cycle' : 'New cycle', 'cycles');
?>
<h1><?= $id ? 'Edit appraisal cycle' : 'New appraisal cycle' ?></h1>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <h2>1. Cycle details</h2>
  <div class="grid">
    <label class="span2">Name *
      <input type="text" name="name" value="<?= e($cycle['name']) ?>" maxlength="150" required placeholder="e.g. 2026 Annual Review">
    </label>
    <label>Start date *
      <input type="date" name="start_date" value="<?= e($cycle['start_date']) ?>" required>
    </label>
    <label>End date *
      <input type="date" name="end_date" value="<?= e($cycle['end_date']) ?>" required>
    </label>
    <label class="span2">Description
      <textarea name="description" rows="2" placeholder="Purpose or instructions for participants"><?= e($cycle['description']) ?></textarea>
    </label>
  </div>

  <h2>2. KPIs and weights</h2>
  <p class="muted">Pick the KPIs used in this cycle. Weights are set per cycle and must total <strong>100</strong> before the cycle can be opened.</p>
  <?php if (!$kpis): ?>
    <div class="alert alert-warn">There are no active KPIs. <a href="kpi_form.php">Create KPIs first</a>.</div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th style="width:50px">Use</th><th>Perspective</th><th>Objective › Priority</th><th>Measure / KPI</th><th>Target</th><th style="width:120px">Weight</th></tr></thead>
    <tbody>
    <?php foreach ($kpis as $k): $kid = (int) $k['id']; $on = isset($selected[$kid]); ?>
      <tr class="<?= $k['is_active'] ? '' : 'inactive' ?>">
        <td><input type="checkbox" class="use" name="kpi[<?= $kid ?>]" value="1" <?= $on ? 'checked' : '' ?>></td>
        <td class="small"><?= e($k['perspective']) ?></td>
        <td class="small">Obj. <?= e($k['objective_code']) ?> › <?= e($k['priority_code']) ?></td>
        <td><?= e($k['title']) ?><?= $k['is_active'] ? '' : ' <span class="badge off">Inactive</span>' ?></td>
        <td class="small"><?= e($k['target'] ?: '—') ?></td>
        <td><input type="number" class="w" name="weight[<?= $kid ?>]" min="0" max="100" step="0.01"
                   value="<?= e($on ? fmt_num($selected[$kid]) : fmt_num($k['weight'])) ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="5" style="text-align:right"><strong>Total selected weight</strong></td><td><strong id="total">0</strong></td></tr></tfoot>
  </table>
  </div>
  <?php endif; ?>

  <div class="form-actions">
    <button class="btn primary" type="submit">Save cycle</button>
    <a class="btn" href="<?= $id ? 'cycle_view.php?id=' . $id : 'cycles.php' ?>">Cancel</a>
  </div>
</form>

<script>
(function () {
  const rows = document.querySelectorAll('tbody tr');
  const totalEl = document.getElementById('total');
  function update() {
    let t = 0;
    rows.forEach(r => {
      const use = r.querySelector('.use'), w = r.querySelector('.w');
      if (use && w && use.checked) t += parseFloat(w.value) || 0;
    });
    t = Math.round(t * 100) / 100;
    totalEl.textContent = t;
    totalEl.style.color = Math.abs(t - 100) < 0.01 ? 'var(--ok)' : 'var(--warn)';
  }
  document.querySelectorAll('.use, .w').forEach(el => el.addEventListener('input', update));
  update();
})();
</script>
<?php page_footer();
