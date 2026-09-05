<?php

declare(strict_types=1);

namespace App\Services\Progress;

/**
 * The mastery formula, and nothing else (docs/architecture.md §10).
 *
 *     accuracy      = (correct + 1) / (attempts + 2)          Laplace-smoothed
 *     recency       = exponential decay, half-life 14 days, on last activity
 *     hint_penalty  = 1 − 0.15 × (hinted / attempts)
 *     coverage      = distinct structures attempted / structures in topic
 *
 *     score         = 100 × accuracy × recency × hint_penalty
 *                         × (0.5 + 0.5 × coverage)
 *
 * **Pure.** No database, no cache, no clock, no `auth()` — `MasteryInput`
 * carries even the current time. That is not stylistic: it is what makes the
 * formula table-driven testable, which docs/handovers/10-progress-mastery.md
 * calls the highest-value test in the feature, and it is why the calculator
 * can be exercised without a single fixture row.
 *
 * **The formula is fixed.** Changing any constant here is an architecture
 * change and needs a plan change first, not a patch
 * (docs/handovers/10-progress-mastery.md, constraints).
 *
 * Two boundary conditions the formula does not itself define, decided here and
 * documented rather than improvised silently:
 *
 * 1. **Zero attempts scores zero.** `hint_penalty` divides by `attempts`, so
 *    the formula is undefined at zero and something has to be chosen. Zero is
 *    the honest answer — there is no evidence of mastery — and it is also what
 *    makes acceptance criterion 1 true: answering the very first question
 *    visibly moves the number, which it would not if an untouched topic
 *    already sat at the smoothed prior of 25.
 *
 * 2. **A topic with no structures is fully covered.** `coverage` divides by
 *    the structure count. An organ quizzed only by multiple choice has none,
 *    and scoring it as uncovered would cap it at half marks forever for
 *    having nothing to cover.
 */
final class MasteryCalculator
{
    /** Days for recency to halve. Fixed by docs/architecture.md §10. */
    public const HALF_LIFE_DAYS = 14.0;

    /** Full hint reliance costs 15% of the score. Fixed by §10. */
    public const HINT_PENALTY_WEIGHT = 0.15;

    /**
     * The share of the score coverage cannot take away.
     *
     * `0.5 + 0.5 × coverage`: a student who has met one structure of nine is
     * halved, not zeroed. Fixed by §10.
     */
    public const COVERAGE_FLOOR = 0.5;

    /**
     * Score one topic from its own attempts.
     */
    public function calculate(MasteryInput $input): MasteryBreakdown
    {
        $accuracy = $this->accuracy($input);
        $recency = $this->recency($input);
        $hintPenalty = $this->hintPenalty($input);
        $coverage = $this->coverage($input);

        // Boundary condition 1. The factors are still reported at their
        // neutral values so a caller can see *why* the score is zero: it is
        // "never practised", not "practised and failed".
        $score = $input->attempts <= 0
            ? 0.0
            : 100.0 * $accuracy * $recency * $hintPenalty * $this->coverageTerm($coverage);

        return new MasteryBreakdown(
            accuracy: $accuracy,
            recency: $recency,
            hintPenalty: $hintPenalty,
            coverage: $coverage,
            score: $this->clampScore($score),
        );
    }

    /**
     * Roll child scores up one level — structure → organ, organ → system.
     *
     * **Unweighted by attempt count**, which is the whole point: a student who
     * has drilled the left ventricle two hundred times and glanced at four
     * other structures does not "know the heart", and a count-weighted mean
     * would say they do (docs/architecture.md §10).
     *
     * The three per-attempt factors are averaged across the children and the
     * formula is then applied at *this* level's coverage. Coverage is the one
     * factor that genuinely belongs to the parent — "how much of this organ
     * have you met" is not a question any single structure can answer — and
     * children scored by `calculate()` at structure level carry coverage 1, so
     * averaging it in would be averaging in a constant.
     *
     * An empty child list is a topic with no attempts anywhere beneath it, and
     * scores zero for the same reason boundary condition 1 does.
     *
     * @param  list<MasteryBreakdown>  $children
     * @param  float  $coverage  distinct structures attempted / structures in this topic
     */
    public function rollUp(array $children, float $coverage): MasteryBreakdown
    {
        $coverage = $this->clampUnit($coverage);

        if ($children === []) {
            return new MasteryBreakdown(
                accuracy: self::UNINFORMED_ACCURACY,
                recency: 1.0,
                hintPenalty: 1.0,
                coverage: $coverage,
                score: 0.0,
            );
        }

        $count = count($children);

        $accuracy = array_sum(array_map(
            static fn (MasteryBreakdown $child): float => $child->accuracy,
            $children,
        )) / $count;

        $recency = array_sum(array_map(
            static fn (MasteryBreakdown $child): float => $child->recency,
            $children,
        )) / $count;

        $hintPenalty = array_sum(array_map(
            static fn (MasteryBreakdown $child): float => $child->hintPenalty,
            $children,
        )) / $count;

        return new MasteryBreakdown(
            accuracy: $accuracy,
            recency: $recency,
            hintPenalty: $hintPenalty,
            coverage: $coverage,
            score: $this->clampScore(
                100.0 * $accuracy * $recency * $hintPenalty * $this->coverageTerm($coverage)
            ),
        );
    }

    /**
     * Laplace-smoothed accuracy: `(correct + 1) / (attempts + 2)`.
     *
     * Smoothed rather than raw so that one lucky first answer is not 100%
     * mastery and one unlucky one is not zero — with a single correct attempt
     * this reads 0.67, not 1.0, and converges on the true rate as evidence
     * accumulates.
     */
    private function accuracy(MasteryInput $input): float
    {
        if ($input->attempts <= 0) {
            return self::UNINFORMED_ACCURACY;
        }

        $correct = max(0, min($input->correctAttempts, $input->attempts));

        return ($correct + 1.0) / ($input->attempts + 2.0);
    }

    /**
     * Exponential decay with a 14-day half-life: `2 ^ (−days / 14)`.
     *
     * Never reaches zero, deliberately — knowledge fades, it does not expire,
     * and a topic that decayed to a hard zero would be indistinguishable from
     * one never attempted when the recommendation goes looking for the weakest.
     *
     * A `last_activity_at` in the future (clock skew between the web node and
     * the queue worker) clamps to no decay rather than amplifying the score.
     */
    private function recency(MasteryInput $input): float
    {
        if ($input->lastActivityAt === null) {
            return 1.0;
        }

        $days = max(0.0, $input->lastActivityAt->diffInDays($input->asOf, absolute: false));

        return 2.0 ** (-$days / self::HALF_LIFE_DAYS);
    }

    /**
     * `1 − 0.15 × (hinted / attempts)`, so between 0.85 and 1.
     *
     * Deliberately gentle. A hint is a supported answer, not a wrong one; the
     * penalty exists so that "right, every time, with the hint open" reads
     * below "right, every time, unaided", not so that asking for help is
     * punished.
     */
    private function hintPenalty(MasteryInput $input): float
    {
        if ($input->attempts <= 0) {
            return 1.0;
        }

        $rate = $this->clampUnit($input->hintedAttempts / $input->attempts);

        return 1.0 - self::HINT_PENALTY_WEIGHT * $rate;
    }

    private function coverage(MasteryInput $input): float
    {
        // Boundary condition 2.
        if ($input->totalStructures <= 0) {
            return 1.0;
        }

        return $this->clampUnit($input->coveredStructures / $input->totalStructures);
    }

    private function coverageTerm(float $coverage): float
    {
        return self::COVERAGE_FLOOR + (1.0 - self::COVERAGE_FLOOR) * $coverage;
    }

    private function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * Rounded to the two decimals `learning_mastery.mastery_score` stores, so
     * the number compared, ordered and displayed is one number rather than
     * three that disagree in the last place.
     */
    private function clampScore(float $score): float
    {
        return round(max(0.0, min(100.0, $score)), 2);
    }

    /**
     * The Laplace prior with no evidence at all: `(0 + 1) / (0 + 2)`.
     *
     * Reported as the accuracy factor of an untouched topic. It never reaches
     * a score, because boundary condition 1 zeroes those.
     */
    private const UNINFORMED_ACCURACY = 0.5;
}
