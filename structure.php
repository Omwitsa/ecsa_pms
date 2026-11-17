<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

function run_or_flash(callable $fn, string $ok, string $fail): void
{
    try {
        $fn();
        flash($ok);
    } catch (PDOException $ex) {
        flash($fail, 'error');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $a     = (string) ($_POST['action'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);
    $code  = trim((string) ($_POST['code'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $sort  = (int) ($_POST['sort_order'] ?? 0);
    $persp = (string) ($_POST['perspective'] ?? '');
    $objId = (int) ($_POST['objective_id'] ?? 0);

    $validText = $code !== '' && strlen($code) <= 20 && $title !== '' && mb_strlen($title) <= 255;

    if (in_array($a, ['add_objective', 'edit_objective'], true)) {
        if (!$validText || !in_array($persp, cfg('perspectives'), true)) {
            flash('Objective needs a perspective, a number/code (max 20 characters) and a title.', 'error');
        } elseif ($a === 'add_objective') {
            run_or_flash(fn() => $pdo->prepare('INSERT INTO objectives (perspective, code, title, sort_order) VALUES (?,?,?,?)')
                ->execute([$persp, $code, $title, $sort]), 'Objective added.', 'That objective code already exists.');
        } else {
            run_or_flash(fn() => $pdo->prepare('UPDATE objectives SET perspective=?, code=?, title=?, sort_order=? WHERE id=?')
                ->execute([$persp, $code, $title, $sort, $id]), 'Objective updated.', 'That objective code already exists.');
        }
    } elseif ($a === 'delete_objective') {
        run_or_flash(fn() => $pdo->prepare('DELETE FROM objectives WHERE id = ?')->execute([$id]),
            'Objective deleted.', 'This objective still has KPIs under its priorities. Delete or move those KPIs first.');
    } elseif (in_array($a, ['add_priority', 'edit_priority'], true)) {
        if (!$validText || $objId <= 0) {
            flash('Strategic priority needs an objective, a code (e.g. 7.1, max 20 characters) and a title.', 'error');
        } elseif ($a === 'add_priority') {
            run_or_flash(fn() => $pdo->prepare('INSERT INTO priorities (objective_id, code, title, sort_order) VALUES (?,?,?,?)')
                ->execute([$objId, $code, $title, $sort]), 'Strategic priority added.', 'That priority code already exists under this objective.');
        } else {
            run_or_flash(fn() => $pdo->prepare('UPDATE priorities SET objective_id=?, code=?, title=?, sort_order=? WHERE id=?')
                ->execute([$objId, $code, $title, $sort, $id]), 'Strategic priority updated.', 'That priority code already exists under this objective.');
        }
    } elseif ($a === 'delete_priority') {
        run_or_flash(fn() => $pdo->prepare('DELETE FROM priorities WHERE id = ?')->execute([$id]),
            'Strategic priority deleted.', 'This priority still has KPIs. Delete or move those KPIs first.');
    }
    redirect('structure.php');
}

$objectives = $pdo->query('SELECT * FROM objectives ORDER BY perspective, sort_order, id')->fetchAll();
$priRows = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM kpis k WHERE k.priority_id = p.id) AS kpi_count
                        FROM priorities p ORDER BY p.objective_id, p.sort_order, p.id')->fetchAll();
$pris = [];
foreach ($priRows as $p) {
    $pris[(int) $p['objective_id']][] = $p;
}
$byPersp = [];
foreach ($objectives as $o) {
    $byPersp[$o['perspective']][] = $o;
}

function persp_options(string $selected): string
{
    $h = '';
    foreach (cfg('perspectives') as $p) {
        $h .= '<option value="' . e($p) . '"' . ($p === $selected ? ' selected' : '') . '>' . e($p) . '</option>';
    }
    return $h;
}

page_header('Objectives & priorities', 'structure');
?>
<h1>Objectives &amp; strategic priorities</h1>
<p class="muted">The scorecard hierarchy: <strong>Perspective → Objective → Strategic priority → KPI (measure)</strong>. Create the objectives and priorities here, then attach KPIs to a priority on the <a href="kpis.php">KPIs</a> page.</p>

<details class="card" <?= $objectives ? '' : 'open' ?>>
  <summary><strong>+ Add objective</strong></summary>
  <form method="post" class="grid" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_objective">
    <label>Perspective *<select name="perspective" required><?= persp_options('') ?></select></label>
    <label>Objective number / code * <input type="text" name="code" maxlength="20" placeholder="e.g. 7" required></label>
    <label class="span2">Objective title *
      <input type="text" name="title" maxlength="255" placeholder="e.g. Strategic Partnerships and Collaboration, Strategic Priorities" required></label>
    <label>Display order <input type="number" name="sort_order" value="0"></label>
    <div><button class="btn primary" type="submit" style="margin-top:22px">Add objective</button></div>
  </form>
</details>

<?php foreach (cfg('perspectives') as $pname): ?>
  <h2 class="cat"><?= e($pname) ?></h2>
  <?php if (empty($byPersp[$pname])): ?>
    <div class="card"><p class="muted">No objectives under this perspective yet.</p></div>
  <?php endif; ?>

  <?php foreach ($byPersp[$pname] ?? [] as $o): $oid = (int) $o['id']; ?>
  <div class="card">
    <div class="row-between">
      <h3>Objective <?= e($o['code']) ?> – <?= e($o['title']) ?></h3>
      <div class="actions">
        <details class="inline"><summary class="btn small">Edit</summary>
          <form method="post" class="grid editbox">
            <?= csrf_field() ?><input type="hidden" name="action" value="edit_objective"><input type="hidden" name="id" value="<?= $oid ?>">
            <label>Perspective<select name="perspective"><?= persp_options($o['perspective']) ?></select></label>
            <label>Code <input type="text" name="code" value="<?= e($o['code']) ?>" maxlength="20" required></label>
            <label class="span2">Title <input type="text" name="title" value="<?= e($o['title']) ?>" maxlength="255" required></label>
            <label>Display order <input type="number" name="sort_order" value="<?= (int) $o['sort_order'] ?>"></label>
            <div><button class="btn primary" type="submit" style="margin-top:22px">Save</button></div>
          </form>
        </details>
        <form method="post" onsubmit="return confirm('Delete this objective and its strategic priorities?');">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete_objective"><input type="hidden" name="id" value="<?= $oid ?>">
          <button class="btn small danger">Delete</button></form>
      </div>
    </div>

    <table>
      <thead><tr><th style="width:80px">Code</th><th>Strategic priority</th><th style="width:70px">KPIs</th><th style="width:70px">Order</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pris[$oid] ?? [] as $p): $pid = (int) $p['id']; ?>
        <tr>
          <td><strong><?= e($p['code']) ?></strong></td>
          <td><?= e($p['title']) ?></td>
          <td><?= (int) $p['kpi_count'] ?></td>
          <td><?= (int) $p['sort_order'] ?></td>
          <td class="actions">
            <details class="inline"><summary class="btn small">Edit</summary>
              <form method="post" class="grid editbox">
                <?= csrf_field() ?><input type="hidden" name="action" value="edit_priority"><input type="hidden" name="id" value="<?= $pid ?>">
                <label>Objective
                  <select name="objective_id">
                    <?php foreach ($objectives as $oo): ?>
                      <option value="<?= (int) $oo['id'] ?>" <?= (int) $oo['id'] === $oid ? 'selected' : '' ?>>Objective <?= e($oo['code']) ?> – <?= e(mb_strimwidth($oo['title'], 0, 50, '…')) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                <label>Code <input type="text" name="code" value="<?= e($p['code']) ?>" maxlength="20" required></label>
                <label class="span2">Title <input type="text" name="title" value="<?= e($p['title']) ?>" maxlength="255" required></label>
                <label>Display order <input type="number" name="sort_order" value="<?= (int) $p['sort_order'] ?>"></label>
                <div><button class="btn primary" type="submit" style="margin-top:22px">Save</button></div>
              </form>
            </details>
            <form method="post" onsubmit="return confirm('Delete this strategic priority?');">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_priority"><input type="hidden" name="id" value="<?= $pid ?>">
              <button class="btn small danger">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($pris[$oid])): ?><tr><td colspan="5" class="muted">No strategic priorities yet.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <details style="margin-top:10px"><summary><strong>+ Add strategic priority</strong></summary>
      <form method="post" class="grid" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_priority"><input type="hidden" name="objective_id" value="<?= $oid ?>">
        <label>Code * <input type="text" name="code" maxlength="20" placeholder="e.g. <?= e($o['code']) ?>.1" required></label>
        <label>Display order <input type="number" name="sort_order" value="<?= count($pris[$oid] ?? []) + 1 ?>"></label>
        <label class="span2">Title * <input type="text" name="title" maxlength="255" placeholder="e.g. Partnerships and collaboration with organizations" required></label>
        <div><button class="btn primary" type="submit">Add priority</button></div>
      </form>
    </details>
  </div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?php page_footer();
