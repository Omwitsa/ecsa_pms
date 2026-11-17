<?php
/** KPI library joined with its strategic priority and objective. */
function kpi_tree_select(): string
{
    return "SELECT k.*, p.code AS priority_code, p.title AS priority_title, p.sort_order AS pri_sort,
                   o.code AS objective_code, o.title AS objective_title, o.sort_order AS obj_sort, o.perspective
            FROM kpis k
            JOIN priorities p ON p.id = k.priority_id
            JOIN objectives o ON o.id = p.objective_id";
}

function kpi_tree_order(): string
{
    return ' ORDER BY o.perspective, o.sort_order, o.id, p.sort_order, p.id, k.sort_order, k.id';
}

/** Order for the per-cycle snapshot table (cycle_kpis). */
function ck_order(string $a = 'ck'): string
{
    return " ORDER BY $a.perspective, $a.obj_sort, $a.objective_code, $a.pri_sort, $a.priority_code, $a.kpi_sort, $a.id";
}

/**
 * Prints the scorecard in the layout of the sheet:
 * Perspective (A) | Objective (B) | Strategic priorities (C) | Key actions (D) | Measure/KPIs (E) |
 * Target (F) | Actual (G) | Weight (H) | Actual perf. (I) | Weighted score J = H x I | Comments (K)
 * with a sub-total per perspective and a grand total.
 *
 * Each row needs: perspective, objective_code, objective_title, priority_code, priority_title,
 * key_actions, title, target, weight, scale_max and (when $opts['values']) actual, rating, comment.
 */
function render_scorecard(array $rows, array $opts = []): void
{
    $values    = !empty($opts['values']);
    $perfLabel = $opts['perf_label'] ?? 'Actual perf. (I)';

    if (!$rows) {
        echo '<p class="muted">No KPIs to show.</p>';
        return;
    }

    $tree = [];
    $pCount = [];
    $oCount = [];
    $byP = [];
    foreach ($rows as $r) {
        $p = $r['perspective'];
        $o = (string) $r['objective_code'];
        $q = (string) $r['priority_code'];
        $tree[$p][$o]['title'] = $r['objective_title'];
        $tree[$p][$o]['pri'][$q]['title'] = $r['priority_title'];
        $tree[$p][$o]['pri'][$q]['rows'][] = $r;
        $pCount[$p] = ($pCount[$p] ?? 0) + 1;
        $oCount[$p][$o] = ($oCount[$p][$o] ?? 0) + 1;
        $byP[$p][] = $r;
    }

    $sum = function (array $list) use ($values): array {
        $w = 0.0; $j = 0.0; $max = 0.0; $complete = $values && $list !== [];
        foreach ($list as $r) {
            $wt = (float) $r['weight'];
            $w += $wt;
            if ($values && isset($r['rating']) && $r['rating'] !== '') {
                $j += $wt * (int) $r['rating'];
                $max += $wt * (int) $r['scale_max'];
            } else {
                $complete = false;
            }
        }
        return [$w, $j, $max, $complete];
    };

    echo '<div class="table-wrap"><table class="scorecard"><thead><tr>'
       . '<th>Perspective (A)</th><th>Objective (B)</th><th>Strategic priorities (C)</th><th>Key actions / initiatives (D)</th>'
       . '<th>Measure / KPIs (E)</th><th>Target (F)</th><th>Actual (G)</th><th>Weight (H)</th>'
       . '<th>' . e($perfLabel) . '</th><th>Weighted score<br>J = H × I</th><th>Comments / notes (K)</th>'
       . '</tr></thead><tbody>';

    foreach ($tree as $persp => $objs) {
        $firstP = true;
        foreach ($objs as $ocode => $obj) {
            $firstO = true;
            foreach ($obj['pri'] as $qcode => $pri) {
                $firstQ = true;
                foreach ($pri['rows'] as $r) {
                    $wt = (float) $r['weight'];
                    $rating = ($values && isset($r['rating']) && $r['rating'] !== '') ? (int) $r['rating'] : null;
                    echo '<tr>';
                    if ($firstP) {
                        echo '<td rowspan="' . $pCount[$persp] . '" class="persp">' . e($persp) . '</td>';
                        $firstP = false;
                    }
                    if ($firstO) {
                        echo '<td rowspan="' . $oCount[$persp][$ocode] . '"><strong>Objective ' . e($ocode) . '</strong> – ' . e($obj['title']) . '</td>';
                        $firstO = false;
                    }
                    if ($firstQ) {
                        echo '<td rowspan="' . count($pri['rows']) . '"><strong>' . e($qcode) . '</strong> ' . e($pri['title']) . '</td>';
                        $firstQ = false;
                    }
                    echo '<td>' . nl2br(e($r['key_actions'] ?? '')) . '</td>';
                    echo '<td>' . e($r['title']) . '</td>';
                    echo '<td>' . e($r['target'] ?? '') . '</td>';
                    echo '<td>' . ($values ? e($r['actual'] ?? '') : '') . '</td>';
                    echo '<td class="num">' . e(fmt_num($wt)) . '</td>';
                    echo '<td class="num">' . ($rating !== null ? $rating : '') . '</td>';
                    echo '<td class="num">' . ($rating !== null ? e(fmt_num($wt * $rating)) : '') . '</td>';
                    echo '<td>' . ($values ? nl2br(e($r['comment'] ?? '')) : '') . '</td>';
                    echo '</tr>';
                }
            }
        }
        [$w, $j, $max, $complete] = $sum($byP[$persp]);
        echo '<tr class="subtotal"><td colspan="7">Sub-total</td><td class="num">' . e(fmt_num($w)) . '</td>'
           . '<td class="num">' . ($complete && $max > 0 ? e(fmt_num($j / $max * 100)) . '%' : '') . '</td>'
           . '<td class="num">' . ($complete ? e(fmt_num($j)) : '') . '</td><td></td></tr>';
    }

    [$w, $j, $max, $complete] = $sum($rows);
    $band = ($complete && $max > 0 && function_exists('band_for')) ? band_for($j / $max * 100) : '';
    echo '<tr class="total"><td colspan="7">Total</td><td class="num">' . e(fmt_num($w)) . '</td>'
       . '<td class="num">' . ($complete && $max > 0 ? e(fmt_num($j / $max * 100)) . '%' : '') . '</td>'
       . '<td class="num">' . ($complete ? e(fmt_num($j)) : '') . '</td><td>' . e($band) . '</td></tr>';
    echo '</tbody></table></div>';
}
