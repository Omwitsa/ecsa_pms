<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/scoring.php';
$user = require_login();
$pdo  = db();

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT a.*, c.name AS cycle_name, c.status AS cycle_status, c.start_date, c.end_date,
                            e.name AS employee_name, e.job_title, r.name AS reviewer_name
                     FROM appraisals a
                     JOIN appraisal_cycles c ON c.id = a.cycle_id
                     JOIN users e ON e.id = a.employee_id
                     LEFT JOIN users r ON r.id = a.reviewer_id
                     WHERE a.id = ?');
$st->execute([$id]);
$ap = $st->fetch();
if (!$ap) {
    flash('Appraisal not found.', 'error');
    redirect(home_url());
}

$isAdmin    = $user['role'] === 'admin';
$isEmployee = (int) $ap['employee_id'] === $user['id'];
$isReviewer = (int) ($ap['reviewer_id'] ?? 0) === $user['id'];
if (!$isAdmin && !$isEmployee && !$isReviewer) {
    http_response_code(403);
    exit('You do not have access to this appraisal.');
}

$cycleOpen = $ap['cycle_status'] === 'open';
$selfMode  = $isEmployee && $cycleOpen && $ap['status'] === 'pending_self';
$mgrMode   = ($isReviewer || $isAdmin) && !$isEmployee && $cycleOpen && $ap['status'] === 'pending_manager';
$field     = $selfMode ? 'self' : 'manager';   // fixed column prefix, never user input

/* ---------- Load KPI rows and rating levels ---------- */
$rs = $pdo->prepare('SELECT s.*, ck.kpi_id, ck.title, ck.category, ck.weight, ck.scale_min, ck.scale_max,
                            k.description, k.measurement, k.target
                     FROM appraisal_scores s
                     JOIN cycle_kpis ck ON ck.id = s.cycle_kpi_id
                     JOIN kpis k ON k.id = ck.kpi_id
                     WHERE s.appraisal_id = ? ORDER BY ck.category, ck.title');
$rs->execute([$id]);
$rows = $rs->fetchAll();

$levels = [];
if ($rows) {
    $kpiIds = array_values(array_unique(array_map('intval', array_column($rows, 'kpi_id'))));
    $in = implode(',', array_fill(0, count($kpiIds), '?'));
    $ls = $pdo->prepare("SELECT kpi_id, rating, label, description FROM kpi_rating_levels WHERE kpi_id IN ($in) ORDER BY rating");
    $ls->execute($kpiIds);
    foreach ($ls->fetchAll() as $l) {
        $levels[(int) $l['kpi_id']][(int) $l['rating']] = $l;
    }
}

function rating_text(array $levels, int $kpiId, $rating): string
{
    if ($rating === null || $rating === '') {
        return 'Not rated';
    }
    $l = $levels[$kpiId][(int) $rating] ?? null;
    return $rating . ($l ? ' — ' . $l['label'] : '');
}

/* ---------- Save / submit ---------- */
$errors = [];
$overall = $selfMode ? (string) ($ap['self_comment'] ?? '') : (string) ($ap['manager_comment'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($selfMode || $mgrMode)) {
    csrf_verify();
    $submit   = ($_POST['action'] ?? '') === 'submit';
    $ratings  = (array) ($_POST['rating'] ?? []);
    $comments = (array) ($_POST['comment'] ?? []);
    $overall  = trim((string) ($_POST['overall_comment'] ?? ''));

    foreach ($rows as &$row) {
        $sid = (int) $row['id'];
        $raw = $ratings[$sid] ?? '';
        $raw = is_string($raw) ? $raw : '';
        $rating = null;
        if ($raw === '') {
            if ($submit) {
                $errors[] = 'Please rate: ' . $row['title'];
            }
        } elseif (!ctype_digit($raw) || (int) $raw < (int) $row['scale_min'] || (int) $raw > (int) $row['scale_max']) {
            $errors[] = 'Invalid rating for: ' . $row['title'];
        } else {
            $rating = (int) $raw;
        }
        $row[$field . '_rating']  = $rating;
        $row[$field . '_comment'] = trim((string) ($comments[$sid] ?? ''));
    }
    unset($row);

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE appraisal_scores SET {$field}_rating = ?, {$field}_comment = ? WHERE id = ? AND appraisal_id = ?");
            foreach ($rows as $row) {
                $upd->execute([$row[$field . '_rating'], $row[$field . '_comment'], $row['id'], $id]);
            }
            $pdo->prepare("UPDATE appraisals SET {$field}_comment = ? WHERE id = ?")->execute([$overall, $id]);

            if ($submit && $selfMode) {
                $pdo->prepare("UPDATE appraisals SET status = 'pending_manager', self_submitted_at = NOW() WHERE id = ? AND status = 'pending_self'")
                    ->execute([$id]);
                flash('Self-review submitted. Your manager has been asked to review it.');
            } elseif ($submit && $mgrMode) {
                $score = compute_final_score($rows);
                $pdo->prepare("UPDATE appraisals SET status = 'completed', final_score = ?, rating_band = ?, completed_at = NOW(),
                               reviewer_id = COALESCE(reviewer_id, ?) WHERE id = ? AND status = 'pending_manager'")
                    ->execute([$score, $score !== null ? band_for($score) : null, $user['id'], $id]);
                flash('Appraisal completed. Final score: ' . fmt_num($score) . '%.');
            } else {
                flash('Draft saved.');
            }
            $pdo->commit();
            redirect('appraisal.php?id=' . $id);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Database error: could not save.';
        }
    }
}

$editing    = $selfMode || $mgrMode;
$showSelf   = $isEmployee || $ap['status'] !== 'pending_self';
$showMgr    = $ap['status'] === 'completed' || $isReviewer || $isAdmin;

page_header('Appraisal', $isAdmin ? 'cycles' : ($isEmployee ? 'mine' : 'team'));
?>
<div class="row-between">
  <h1>Appraisal: <?= e($ap['employee_name']) ?></h1>
  <?php if ($isAdmin): ?><a class="btn" href="cycle_view.php?id=<?= (int) $ap['cycle_id'] ?>">← Back to cycle</a>
  <?php elseif ($isEmployee): ?><a class="btn" href="my_appraisals.php">← My appraisals</a>
  <?php else: ?><a class="btn" href="team.php">← Team reviews</a><?php endif; ?>
</div>

<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>

<div class="card">
  <div class="grid">
    <div><span class="muted small">Cycle</span><br><strong><?= e($ap['cycle_name']) ?></strong> <?= status_badge($ap['cycle_status']) ?></div>
    <div><span class="muted small">Period</span><br><?= e(fmt_date($ap['start_date'])) ?> – <?= e(fmt_date($ap['end_date'])) ?></div>
    <div><span class="muted small">Employee</span><br><?= e($ap['employee_name']) ?><?= $ap['job_title'] ? ' · ' . e($ap['job_title']) : '' ?></div>
    <div><span class="muted small">Reviewer</span><br><?= e($ap['reviewer_name'] ?: 'Admin') ?></div>
    <div><span class="muted small">Status</span><br><?= status_badge($ap['status']) ?></div>
  </div>
  <?php if ($selfMode): ?>
    <p class="muted">Rate yourself against each objective using the descriptions provided. Add evidence in the comment box. You can save a draft and return later; once submitted, your self-review is locked.</p>
  <?php elseif ($mgrMode): ?>
    <p class="muted">Review the employee's self-ratings and give your own rating for each objective. Submitting calculates the final score and locks the appraisal.</p>
  <?php elseif (!$cycleOpen && $ap['status'] !== 'completed'): ?>
    <p class="muted">This cycle is closed, so this appraisal can no longer be edited.</p>
  <?php elseif ($isEmployee && $ap['status'] === 'pending_manager'): ?>
    <p class="muted">Your self-review is submitted and is waiting for your manager's review.</p>
  <?php endif; ?>
</div>

<?php if ($ap['status'] === 'completed'): ?>
<div class="card result">
  <div class="score-big"><?= e(fmt_num($ap['final_score'])) ?>%</div>
  <div><strong><?= e($ap['rating_band']) ?></strong><br><span class="muted small">Weighted final score · completed <?= e(fmt_date($ap['completed_at'])) ?></span></div>
</div>
<?php endif; ?>

<?php if ($editing): ?><form method="post"><?= csrf_field() ?><?php endif; ?>

<?php $lastCat = null; foreach ($rows as $row):
    $kid = (int) $row['kpi_id']; $sid = (int) $row['id'];
    if ($row['category'] !== $lastCat): $lastCat = $row['category']; ?>
      <h2 class="cat"><?= e($lastCat) ?></h2>
    <?php endif; ?>
  <div class="card kpi-card">
    <div class="row-between">
      <h3><?= e($row['title']) ?></h3>
      <span class="badge on">Weight <?= e(fmt_num($row['weight'])) ?>%</span>
    </div>
    <?php if ($row['description'] !== ''): ?><p><?= e($row['description']) ?></p><?php endif; ?>
    <?php if ($row['measurement'] !== ''): ?><p class="small"><strong>Measured by:</strong> <?= e($row['measurement']) ?></p><?php endif; ?>
    <?php if ($row['target'] !== ''): ?><p class="small"><strong>Target:</strong> <?= e($row['target']) ?></p><?php endif; ?>

    <?php if ($mgrMode || (!$editing && $showSelf)): ?>
      <div class="ratingline"><span class="muted small">Employee self-rating</span>
        <strong><?= e(rating_text($levels, $kid, $row['self_rating'])) ?></strong>
        <?php if (!empty($row['self_comment'])): ?><div class="small"><?= nl2br(e($row['self_comment'])) ?></div><?php endif; ?></div>
    <?php endif; ?>

    <?php if ($editing): ?>
      <div class="rating-options">
        <?php foreach ($levels[$kid] ?? [] as $l): ?>
          <label class="rating-option">
            <input type="radio" name="rating[<?= $sid ?>]" value="<?= (int) $l['rating'] ?>" <?= (string) $row[$field . '_rating'] === (string) $l['rating'] ? 'checked' : '' ?>>
            <span class="rating-pill"><?= (int) $l['rating'] ?></span>
            <span><strong><?= e($l['label']) ?></strong><br><span class="muted"><?= e($l['description']) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label>Comment / evidence
        <textarea name="comment[<?= $sid ?>]" rows="2"><?= e($row[$field . '_comment'] ?? '') ?></textarea>
      </label>
    <?php elseif ($showMgr): ?>
      <div class="ratingline"><span class="muted small">Manager rating</span>
        <strong><?= e(rating_text($levels, $kid, $row['manager_rating'])) ?></strong>
        <?php if (!empty($row['manager_comment'])): ?><div class="small"><?= nl2br(e($row['manager_comment'])) ?></div><?php endif; ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($editing): ?>
  <div class="card">
    <label>Overall comments
      <textarea name="overall_comment" rows="3"><?= e($overall) ?></textarea>
    </label>
    <div class="form-actions">
      <button class="btn" type="submit" name="action" value="save">Save draft</button>
      <button class="btn primary" type="submit" name="action" value="submit"
        onclick="return confirm('Submit now? You will not be able to change your ratings afterwards.');">Submit <?= $selfMode ? 'self-review' : 'final review' ?></button>
    </div>
  </div>
</form>
<?php else: ?>
  <?php if ($showSelf && !empty($ap['self_comment'])): ?>
    <div class="card"><h3>Employee's overall comments</h3><p><?= nl2br(e($ap['self_comment'])) ?></p></div>
  <?php endif; ?>
  <?php if ($showMgr && !empty($ap['manager_comment'])): ?>
    <div class="card"><h3>Manager's overall comments</h3><p><?= nl2br(e($ap['manager_comment'])) ?></p></div>
  <?php endif; ?>
<?php endif; ?>
<?php page_footer();
