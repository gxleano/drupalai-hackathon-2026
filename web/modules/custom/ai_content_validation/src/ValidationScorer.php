<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

/**
 * Derives the 0-100 quality score from the validator's ten verdicts.
 *
 * The model classifies each of the 10 EU guidelines as
 * pass/minor/major/fail — classification is far more reproducible than a
 * 0-10 number, which wobbled ±1-2 per guideline (±6 total) on unchanged
 * content. The number is computed here, in PHP, never by the model.
 *
 * This lives outside the normalize node processor because two callers must
 * produce the same number on the same scale: the normalize processor, which
 * stamps the score the editor sees in the page header, and the AI Improve
 * non-regression gate, which scores a candidate rewrite before it is ever
 * offered. A gate scoring on a slightly different scale than the header
 * would be worse than no gate at all, so there is exactly one
 * implementation.
 */
final class ValidationScorer {

  /**
   * Points awarded per verdict.
   *
   * @var array<string, int>
   */
  private const POINTS = ['pass' => 10, 'minor' => 8, 'major' => 4, 'fail' => 0];

  /**
   * Derives the numeric score from a validation result payload.
   *
   * The payload is returned with the pseudo-contradictions discarded, the
   * Accuracy verdict floored when nothing contradicts, and `score` set to
   * the summed total. A payload that carries no ten-verdict `scores` map
   * (the improver's output, for instance) is returned untouched.
   *
   * @param array<string, mixed> $result
   *   The validation result payload: `scores` (a map of guideline number to
   *   verdict string), `contradictions` (a list of quote strings), plus the
   *   other result keys, which are passed through unchanged.
   *
   * @return array<string, mixed>
   *   The payload with `contradictions`, `scores` and `score` adjusted.
   */
  public static function applyDerivedScore(array $result): array {
    $scores = $result['scores'] ?? NULL;
    if (is_array($scores) && count($scores) === 10) {
      // The model persistently invents a "current-year fact is
      // future-dated" contradiction from the publication date or the
      // revision timestamp, against explicit rubric rules. Those are
      // never legitimate — a contradiction must be between two article
      // statements — so they are discarded mechanically.
      if (is_array($result['contradictions'] ?? NULL)) {
        $result['contradictions'] = array_values(array_filter(
          $result['contradictions'],
          static fn ($entry): bool => is_string($entry)
            && !preg_match('/publication date|timestamp|future[ -]dated|current year/i', $entry),
        ));
      }
      $points = self::POINTS;
      $numeric = [];
      foreach ($scores as $key => $verdict) {
        if (is_string($verdict) && isset($points[strtolower(trim($verdict))])) {
          $numeric[$key] = $points[strtolower(trim($verdict))];
        }
        elseif (is_numeric($verdict)) {
          // Legacy numeric breakdowns (older stored prompts) still sum.
          $numeric[$key] = (int) $verdict;
        }
      }
      // The factual-error verdict on guideline 1 is only legitimate
      // when the model actually listed contradictions; it habitually
      // condemns Accuracy while its own reasoning says the article is
      // consistent, so the rule is enforced mechanically: no
      // contradictions → at least "minor".
      if (($result['contradictions'] ?? NULL) === [] && ($numeric['1'] ?? 10) < $points['minor']) {
        $numeric['1'] = $points['minor'];
        $result['scores']['1'] = 'minor';
      }
      if (count($numeric) === 10) {
        $score = (int) min(100, max(0, array_sum($numeric)));
        // A field flagged "review" means at least one concrete issue an
        // editor should act on, so a perfect 100 next to yellow field
        // dots would contradict itself. Capped mechanically because the
        // model keeps producing all-pass guideline verdicts alongside
        // per-field review verdicts.
        $verdicts = is_array($result['field_verdicts'] ?? NULL) ? $result['field_verdicts'] : [];
        $flagged = array_filter(
          $verdicts,
          static fn ($verdict): bool => is_string($verdict) && strtolower(trim($verdict)) === 'review',
        );
        if ($flagged !== []) {
          $score = min($score, 95);
        }
        $result['score'] = $score;
      }
    }
    return $result;
  }

}
