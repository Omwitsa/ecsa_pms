<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$kpis = $pdo->query('SELECT * FROM kpis WHERE is_active = 1 ORDER BY category, title')->fetchAll();
$levelsByKpi = [];
foreach ($pdo->query('SELECT * FROM kpi_rating_levels ORDER BY kpi_id, rating')->fetchAll() as $l) {
    $levelsByKpi[(int) $l['kpi_id']][] = $l;
}

page_header('Scorecard preview', 'preview');
?>
<h1>Scorecard preview</h1>
<p class="muted">This is how raters will see each active KPI and its rating guide. Selections here are not saved.</p>

<?php if (!$kpis): ?>
  <div class="card"><p class="muted">No active KPIs to preview.</p></div>
<?php endif; ?>

<?php $lastCat = null; foreach ($kpis as $k): ?>
  <?php if ($k['category'] !== $lastCat): $lastCat = $k['category']; ?>
    <h2 class="cat"><?= e($lastCat) ?></h2>
  <?php endif; ?>
  <div class="card kpi-card">
    <div class="row-between">
      <h3><?= e($k['title']) ?></h3>
      <span class="badge on">Weight <?= e(rtrim(rtrim(number_format((float) $k['weight'], 2), '0'), '.')) ?>%</span>
    </div>
    <?php if ($k['description'] !== ''): ?><p><?= e($k['description']) ?></p><?php endif; ?>
    <?php if ($k['measurement'] !== ''): ?><p class="small"><strong>Measured by:</strong> <?= e($k['measurement']) ?></p><?php endif; ?>
    <?php if ($k['target'] !== ''): ?><p class="small"><strong>Target:</strong> <?= e($k['target']) ?></p><?php endif; ?>

    <div class="rating-options">
      <?php foreach ($levelsByKpi[(int) $k['id']] ?? [] as $l): ?>
        <label class="rating-option">
          <input type="radio" name="kpi_<?= (int) $k['id'] ?>" value="<?= (int) $l['rating'] ?>">
          <span class="rating-pill"><?= (int) $l['rating'] ?></span>
          <span><strong><?= e($l['label']) ?></strong><br><span class="muted"><?= e($l['description']) ?></span></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php page_footer();
