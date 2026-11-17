<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$id  = (int) ($_GET['id'] ?? 0);
$kpi = ['priority_id' => (int) ($_GET['priority_id'] ?? 0), 'title' => '', 'key_actions' => '', 'description' => '', 'target' => '',
        'weight' => '', 'scale_min' => 1, 'scale_max' => 5, 'sort_order' => 0, 'is_active' => 1];
$levels = []; // rating => ['label' => , 'description' => ]

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM kpis WHERE id = ?');
    $st->execute([$id]);
    $found = $st->fetch();
    if (!$found) {
        flash('KPI not found.', 'error');
        redirect('kpis.php');
    }
    $kpi = $found;
    $ls = $pdo->prepare('SELECT rating, label, description FROM kpi_rating_levels WHERE kpi_id = ? ORDER BY rating');
    $ls->execute([$id]);
    foreach ($ls->fetchAll() as $l) {
        $levels[(int) $l['rating']] = ['label' => $l['label'], 'description' => $l['description']];
    }
}

// Strategic priorities to attach the KPI to
$priorities = $pdo->query('SELECT p.id, p.code, p.title, o.code AS ocode, o.title AS otitle, o.perspective
                           FROM priorities p JOIN objectives o ON o.id = p.objective_id
                           ORDER BY o.perspective, o.sort_order, o.id, p.sort_order, p.id')->fetchAll();
$priorityIds = array_map('intval', array_column($priorities, 'id'));

$errors = [];

// Once a KPI is part of an open or closed cycle its scale range is fixed,
// otherwise existing ratings would no longer match the scale.
$locked = false;
if ($id > 0) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM cycle_kpis ck JOIN appraisal_cycles c ON c.id = ck.cycle_id
                        WHERE ck.kpi_id = ? AND c.status <> 'draft'");
    $q->execute([$id]);
    $locked = (int) $q->fetchColumn() > 0;
}
$origMin = (int) $kpi['scale_min'];
$origMax = (int) $kpi['scale_max'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $kpi['priority_id'] = (int) ($_POST['priority_id'] ?? 0);
    $kpi['title']       = trim((string) ($_POST['title'] ?? ''));
    $kpi['key_actions'] = trim((string) ($_POST['key_actions'] ?? ''));
    $kpi['description'] = trim((string) ($_POST['description'] ?? ''));
    $kpi['target']      = trim((string) ($_POST['target'] ?? ''));
    $kpi['weight']      = trim((string) ($_POST['weight'] ?? ''));
    $kpi['sort_order']  = (int) ($_POST['sort_order'] ?? 0);
    $kpi['scale_min']   = (int) ($_POST['scale_min'] ?? 1);
    $kpi['scale_max']   = (int) ($_POST['scale_max'] ?? 5);
    $kpi['is_active']   = isset($_POST['is_active']) ? 1 : 0;
    if ($locked) {
        $kpi['scale_min'] = $origMin;
        $kpi['scale_max'] = $origMax;
    }

    if (!in_array($kpi['priority_id'], $priorityIds, true)) {
        $errors[] = 'Choose the strategic priority this KPI belongs to.';
    }
    if ($kpi['title'] === '' || mb_strlen($kpi['title']) > 500) {
        $errors[] = 'Measure / KPI is required (max 500 characters).';
    }
    if (!is_numeric($kpi['weight']) || $kpi['weight'] < 0 || $kpi['weight'] > 100) {
        $errors[] = 'Weight must be a number between 0 and 100.';
    }
    if (!in_array($kpi['scale_min'], [0, 1], true)) {
        $errors[] = 'Scale must start at 0 or 1.';
    }
    if ($kpi['scale_max'] < 3 || $kpi['scale_max'] > 10 || $kpi['scale_max'] <= $kpi['scale_min']) {
        $errors[] = 'Scale must end between 3 and 10 and be higher than the starting value.';
    }

    $levels = [];
    if (!$errors) {
        for ($r = $kpi['scale_min']; $r <= $kpi['scale_max']; $r++) {
            $label = trim((string) ($_POST['label'][$r] ?? ''));
            $desc  = trim((string) ($_POST['descriptor'][$r] ?? ''));
            $levels[$r] = ['label' => $label, 'description' => $desc];
            if ($label === '' || $desc === '') {
                $errors[] = "Rating $r needs both a label and a clear description of what it means.";
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $params = [$kpi['priority_id'], $kpi['title'], $kpi['key_actions'], $kpi['description'], $kpi['target'],
                       (float) $kpi['weight'], $kpi['scale_min'], $kpi['scale_max'], $kpi['sort_order'], $kpi['is_active']];
            if ($id > 0) {
                $pdo->prepare('UPDATE kpis SET priority_id=?, title=?, key_actions=?, description=?, target=?, weight=?,
                               scale_min=?, scale_max=?, sort_order=?, is_active=? WHERE id=?')
                    ->execute(array_merge($params, [$id]));
                $pdo->prepare('DELETE FROM kpi_rating_levels WHERE kpi_id = ?')->execute([$id]);
                $kpiId = $id;
            } else {
                $pdo->prepare('INSERT INTO kpis (priority_id, title, key_actions, description, target, weight, scale_min, scale_max, sort_order, is_active)
                               VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($params);
                $kpiId = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO kpi_rating_levels (kpi_id, rating, label, description) VALUES (?,?,?,?)');
            foreach ($levels as $r => $l) {
                $ins->execute([$kpiId, $r, $l['label'], $l['description']]);
            }
            $pdo->commit();
            flash($id > 0 ? 'KPI updated.' : 'KPI created.');
            redirect('kpis.php');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Database error: could not save the KPI.';
        }
    }
}

page_header($id ? 'Edit KPI' : 'Add KPI', 'kpis');
?>
<h1><?= $id ? 'Edit KPI' : 'Add KPI' ?></h1>

<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>

<?php if (!$priorities): ?>
  <div class="alert alert-warn">There are no strategic priorities yet. <a href="structure.php">Create objectives and strategic priorities first</a>.</div>
<?php endif; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <h2>1. Where this KPI sits on the scorecard</h2>
  <div class="grid">
    <label class="span2">Strategic priority *
      <select name="priority_id" required>
        <option value="">— Select perspective › objective › priority —</option>
        <?php $lastP = null; foreach ($priorities as $p):
            if ($p['perspective'] !== $lastP) { if ($lastP !== null) { echo '</optgroup>'; } echo '<optgroup label="' . e($p['perspective']) . '">'; $lastP = $p['perspective']; } ?>
          <option value="<?= (int) $p['id'] ?>" <?= (int) $kpi['priority_id'] === (int) $p['id'] ? 'selected' : '' ?>>
            Obj. <?= e($p['ocode']) ?> › <?= e($p['code']) ?> <?= e(mb_strimwidth($p['title'], 0, 80, '…')) ?></option>
        <?php endforeach; if ($lastP !== null) { echo '</optgroup>'; } ?>
      </select>
    </label>
  </div>

  <h2>2. Define the KPI</h2>
  <div class="grid">
    <label class="span2">Measure / KPI *
      <textarea name="title" rows="2" maxlength="500" required placeholder="e.g. No. of agreements signed with private sector players"><?= e($kpi['title']) ?></textarea>
    </label>
    <label class="span2">Key actions / initiatives
      <textarea name="key_actions" rows="2" placeholder="Activities that will deliver this KPI"><?= e($kpi['key_actions']) ?></textarea>
    </label>
    <label>Target
      <input type="text" name="target" value="<?= e($kpi['target']) ?>" maxlength="120" placeholder="e.g. 5 agreements, 100% compliance">
    </label>
    <label>Weight *
      <input type="number" name="weight" value="<?= e($kpi['weight']) ?>" min="0" max="100" step="0.01" required>
    </label>
    <label class="span2">How it is measured (optional guidance)
      <textarea name="description" rows="2" placeholder="Data source, formula or evidence used to judge performance"><?= e($kpi['description']) ?></textarea>
    </label>
    <label>Display order within the priority
      <input type="number" name="sort_order" value="<?= (int) $kpi['sort_order'] ?>">
    </label>
    <label class="check"><input type="checkbox" name="is_active" value="1" <?= $kpi['is_active'] ? 'checked' : '' ?>> Active</label>
  </div>

  <h2>3. Rating scale</h2>
  <?php if ($locked): ?><div class="alert alert-warn">This KPI is used in an open or closed appraisal cycle, so its scale range is locked. You can still refine labels and descriptions.</div><?php endif; ?>
  <p class="muted">Choose the range, then describe exactly what each rating means for <em>this</em> KPI (for example, what level of the target achieved earns a 3 or a 5) so every rater applies the same standard.</p>
  <div class="grid">
    <label>Scale starts at
      <select name="scale_min" id="scale_min" <?= $locked ? 'disabled' : '' ?>>
        <option value="1" <?= (int) $kpi['scale_min'] === 1 ? 'selected' : '' ?>>1</option>
        <option value="0" <?= (int) $kpi['scale_min'] === 0 ? 'selected' : '' ?>>0</option>
      </select>
    </label>
    <label>Scale ends at
      <select name="scale_max" id="scale_max" <?= $locked ? 'disabled' : '' ?>>
        <?php for ($i = 3; $i <= 10; $i++): ?>
          <option value="<?= $i ?>" <?= (int) $kpi['scale_max'] === $i ? 'selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </label>
  </div>

  <div class="table-wrap">
  <table class="levels">
    <thead><tr><th style="width:70px">Rating</th><th style="width:230px">Label</th><th>What this rating means (observable criteria)</th></tr></thead>
    <tbody id="levels-body"></tbody>
  </table>
  </div>

  <div class="form-actions">
    <button class="btn primary" type="submit">Save KPI</button>
    <a class="btn" href="kpis.php">Cancel</a>
  </div>
</form>

<script>
(function () {
  const defaults = <?= json_encode(cfg('default_scale_labels'), JSON_UNESCAPED_UNICODE) ?>;
  const saved    = <?= json_encode((object) $levels, JSON_UNESCAPED_UNICODE) ?>;
  const state    = {};
  Object.keys(saved).forEach(k => state[k] = saved[k]);

  const body = document.getElementById('levels-body');
  const minEl = document.getElementById('scale_min');
  const maxEl = document.getElementById('scale_max');

  function capture() {
    body.querySelectorAll('tr').forEach(tr => {
      state[tr.dataset.rating] = {
        label: tr.querySelector('input').value,
        description: tr.querySelector('textarea').value
      };
    });
  }

  function render() {
    const min = parseInt(minEl.value, 10), max = parseInt(maxEl.value, 10);
    body.innerHTML = '';
    for (let r = min; r <= max; r++) {
      const s = state[r] || {};
      const useDefault = (min === 1 && max === 5 && defaults[r]);
      const label = s.label !== undefined ? s.label : (useDefault ? defaults[r] : '');
      const tr = document.createElement('tr');
      tr.dataset.rating = r;
      tr.innerHTML =
        '<td><span class="rating-pill">' + r + '</span></td>' +
        '<td><input type="text" name="label[' + r + ']" maxlength="60" required placeholder="Label"></td>' +
        '<td><textarea name="descriptor[' + r + ']" rows="2" required placeholder="Describe what performance at this level looks like"></textarea></td>';
      tr.querySelector('input').value = label;
      tr.querySelector('textarea').value = s.description || '';
      body.appendChild(tr);
    }
  }

  [minEl, maxEl].forEach(el => el.addEventListener('change', () => { capture(); render(); }));
  render();
})();
</script>
<?php page_footer();
