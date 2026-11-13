<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$id  = (int) ($_GET['id'] ?? 0);
$kpi = ['title' => '', 'description' => '', 'category' => 'General', 'measurement' => '', 'target' => '',
        'weight' => '', 'scale_min' => 1, 'scale_max' => 5, 'is_active' => 1];
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

$errors = [];

// Once a KPI is part of an open or closed cycle its scale range is fixed,
// otherwise existing ratings would no longer match the scale.
$locked = false;
if ($id > 0) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM cycle_kpis ck JOIN appraisal_cycles c ON c.id = ck.cycle_id
                        WHERE ck.kpi_id = ? AND c.status <> 'draft'");
    $q->execute([$id]);
    $locked  = (int) $q->fetchColumn() > 0;
}
$origMin = (int) $kpi['scale_min'];
$origMax = (int) $kpi['scale_max'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $kpi['title']       = trim((string) ($_POST['title'] ?? ''));
    $kpi['description'] = trim((string) ($_POST['description'] ?? ''));
    $kpi['category']    = trim((string) ($_POST['category'] ?? '')) ?: 'General';
    $kpi['measurement'] = trim((string) ($_POST['measurement'] ?? ''));
    $kpi['target']      = trim((string) ($_POST['target'] ?? ''));
    $kpi['weight']      = trim((string) ($_POST['weight'] ?? ''));
    $kpi['scale_min']   = (int) ($_POST['scale_min'] ?? 1);
    $kpi['scale_max']   = (int) ($_POST['scale_max'] ?? 5);
    $kpi['is_active']   = isset($_POST['is_active']) ? 1 : 0;
    if ($locked) {
        $kpi['scale_min'] = $origMin;
        $kpi['scale_max'] = $origMax;
    }

    if ($kpi['title'] === '') {
        $errors[] = 'KPI title is required.';
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
            if ($id > 0) {
                $pdo->prepare('UPDATE kpis SET title=?, description=?, category=?, measurement=?, target=?, weight=?,
                               scale_min=?, scale_max=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$kpi['title'], $kpi['description'], $kpi['category'], $kpi['measurement'], $kpi['target'],
                               (float) $kpi['weight'], $kpi['scale_min'], $kpi['scale_max'], $kpi['is_active'], $id]);
                $pdo->prepare('DELETE FROM kpi_rating_levels WHERE kpi_id = ?')->execute([$id]);
                $kpiId = $id;
            } else {
                $pdo->prepare('INSERT INTO kpis (title, description, category, measurement, target, weight, scale_min, scale_max, is_active)
                               VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$kpi['title'], $kpi['description'], $kpi['category'], $kpi['measurement'], $kpi['target'],
                               (float) $kpi['weight'], $kpi['scale_min'], $kpi['scale_max'], $kpi['is_active']]);
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
            $errors[] = 'Database error: could not save the KPI.';
        }
    }
}

$categories = $pdo->query('SELECT DISTINCT category FROM kpis ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

page_header($id ? 'Edit KPI' : 'Add KPI', $id ? 'kpis' : 'kpi_new');
?>
<h1><?= $id ? 'Edit KPI' : 'Add KPI' ?></h1>

<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <h2>1. Define the KPI / objective</h2>
  <div class="grid">
    <label class="span2">Title *
      <input type="text" name="title" value="<?= e($kpi['title']) ?>" maxlength="150" required placeholder="e.g. Customer satisfaction score">
    </label>
    <label>Category
      <input type="text" name="category" value="<?= e($kpi['category']) ?>" list="cats" maxlength="60" placeholder="e.g. Customer, Quality, Delivery">
      <datalist id="cats"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    </label>
    <label>Weight (%) *
      <input type="number" name="weight" value="<?= e($kpi['weight']) ?>" min="0" max="100" step="0.01" required>
    </label>
    <label class="span2">Description
      <textarea name="description" rows="2" placeholder="What does this objective aim to achieve?"><?= e($kpi['description']) ?></textarea>
    </label>
    <label class="span2">How it is measured
      <textarea name="measurement" rows="2" placeholder="Data source, formula or evidence used to judge performance"><?= e($kpi['measurement']) ?></textarea>
    </label>
    <label>Target
      <input type="text" name="target" value="<?= e($kpi['target']) ?>" maxlength="120" placeholder="e.g. 90% CSAT, 12 projects/quarter">
    </label>
    <label class="check"><input type="checkbox" name="is_active" value="1" <?= $kpi['is_active'] ? 'checked' : '' ?>> Active</label>
  </div>

  <h2>2. Rating scale</h2>
  <?php if ($locked): ?><div class="alert alert-warn">This KPI is used in an open or closed appraisal cycle, so its scale range is locked. You can still refine labels and descriptions.</div><?php endif; ?>
  <p class="muted">Choose the range, then describe exactly what each rating means for <em>this</em> objective so every rater applies the same standard.</p>
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
  const state    = {};  // rating -> {label, description}; survives scale changes
  Object.keys(saved).forEach(k => state[k] = saved[k]);

  const body = document.getElementById('levels-body');
  const minEl = document.getElementById('scale_min');
  const maxEl = document.getElementById('scale_max');

  function capture() {
    body.querySelectorAll('tr').forEach(tr => {
      const r = tr.dataset.rating;
      state[r] = {
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
