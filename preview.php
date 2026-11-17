<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$pdo = db();

$rows = $pdo->query(kpi_tree_select() . ' WHERE k.is_active = 1' . kpi_tree_order())->fetchAll();
$levelsByKpi = [];
foreach ($pdo->query('SELECT * FROM kpi_rating_levels ORDER BY kpi_id, rating')->fetchAll() as $l) {
    $levelsByKpi[(int) $l['kpi_id']][] = $l;
}

page_header('Scorecard', 'preview');
?>
<h1>Scorecard</h1>
<p class="muted">All active KPIs laid out as the appraisal scorecard. Target and weight come from the KPI library. Actual, performance and weighted score are filled in during an appraisal.</p>
<div class="card"><?php render_scorecard($rows); ?></div>

<h2>Rating guide</h2>
<?php if (!$rows): ?><p class="muted">No active KPIs.</p><?php endif; ?>
<?php foreach ($rows as $k): ?>
  <details class="card">
    <summary><strong><?= e($k['priority_code']) ?></strong> <?= e($k['title']) ?>
      <span class="badge on">Weight <?= e(fmt_num($k['weight'])) ?></span></summary>
    <div class="rating-options">
      <?php foreach ($levelsByKpi[(int) $k['id']] ?? [] as $l): ?>
        <div class="rating-option">
          <span class="rating-pill"><?= (int) $l['rating'] ?></span>
          <span><strong><?= e($l['label']) ?></strong><br><span class="muted"><?= e($l['description']) ?></span></span>
        </div>
      <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>
<?php page_footer();
