<?php
/**
 * Scoring helpers.
 * Each rating is normalised to 0..1 using the KPI's own scale, so KPIs with different
 * scales can be combined. The final score is the weighted average, as a percentage.
 */

function normalised_rating(int $rating, int $min, int $max): float
{
    return ($rating - $min) / max(1, $max - $min);
}

/** @param array $rows each with weight, scale_min, scale_max, manager_rating */
function compute_final_score(array $rows): ?float
{
    $weightSum = 0.0;
    $acc = 0.0;
    foreach ($rows as $r) {
        if ($r['manager_rating'] === null || $r['manager_rating'] === '') {
            return null;
        }
        $w = (float) $r['weight'];
        $weightSum += $w;
        $acc += $w * normalised_rating((int) $r['manager_rating'], (int) $r['scale_min'], (int) $r['scale_max']);
    }
    return $weightSum > 0 ? round($acc / $weightSum * 100, 2) : null;
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
