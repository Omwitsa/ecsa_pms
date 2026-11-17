<?php
/**
 * Scoring, following the scorecard sheet:
 *   Weighted score (J) = Weight (H) x Actual performance (I, the rating)
 * Final score % = sum(J) / sum(Weight x highest rating on the KPI's scale) x 100
 */

function weighted_score(float $weight, $rating): ?float
{
    return ($rating === null || $rating === '') ? null : $weight * (int) $rating;
}

/** @param array $rows each with weight, scale_max, manager_rating */
function compute_final_score(array $rows): ?float
{
    $got = 0.0;
    $max = 0.0;
    foreach ($rows as $r) {
        if ($r['manager_rating'] === null || $r['manager_rating'] === '') {
            return null;
        }
        $w = (float) $r['weight'];
        $got += $w * (int) $r['manager_rating'];
        $max += $w * (int) $r['scale_max'];
    }
    return $max > 0 ? round($got / $max * 100, 2) : null;
}

function band_for(float $score): string
{
    $bands = cfg('score_bands');
    foreach ($bands as [$min, $label]) {
        if ($score >= $min) {
            return $label;
        }
    }
    return end($bands)[1];
}
