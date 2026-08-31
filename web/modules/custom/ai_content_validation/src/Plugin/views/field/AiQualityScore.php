<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\views\field;

use Drupal\ai_content_validation\ValidationFreshness;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders the node's latest AI quality score as a donut ring.
 *
 * The ring track is light gray; the filled arc is red (< 50), yellow
 * (50-79) or green (>= 80) with the percentage in the white center. Nodes
 * without a scored validation get a full gray ring with a dash. The ring
 * is not a link.
 */
#[ViewsField('ai_quality_score')]
final class AiQualityScore extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // No database column: the value is computed at render time from the
    // batch lookup done in ai_content_validation_views_pre_render().
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity ?? NULL;
    if ($entity === NULL || $entity->getEntityTypeId() !== 'node') {
      return '';
    }
    $nid = (int) $entity->id();
    $scores = drupal_static('ai_content_validation_latest_scores', []);
    $record = $scores[$nid] ?? NULL;
    $stale = FALSE;

    if ($record === NULL) {
      $fill = 'var(--gin-border-color, #d4d4d8)';
      $percent = 100;
      $text = '–';
      $info = $this->t('No AI quality score yet.');
    }
    else {
      $score = max(0, min(100, (int) $record['score']));
      // The score describes the content it was computed from: only a
      // change to a validated value makes it stale.
      $stale = !ValidationFreshness::isCurrent($entity, $record);
      $fill = $score >= 80
        ? 'var(--gin-color-green, #26a769)'
        : ($score >= 50 ? 'var(--gin-color-warning, #e29700)' : 'var(--gin-color-danger, #dc2323)');
      $percent = $score;
      $text = $score . '%';
      $info = $stale
        ? $this->t('Quality score @score/100 — the content has changed since this validation.', ['@score' => $score])
        : $this->t('Quality score @score/100 from the latest AI validation.', ['@score' => $score]);
    }

    $ring = 'conic-gradient(' . $fill . ' 0 ' . $percent . '%, var(--gin-border-color, #e5e5e5) ' . $percent . '% 100%)';
    // Stale scores carry a small amber "!" badge on the donut so the list
    // shows at a glance which content needs re-validation.
    $badge = $stale
      ? '<span aria-hidden="true" style="position: absolute; top: -2px; right: -4px;'
        . ' display: inline-flex; align-items: center; justify-content: center;'
        . ' width: 16px; height: 16px; border-radius: 50%;'
        . ' background: var(--gin-color-warning, #e29700); color: #fff;'
        . ' font-size: 11px; font-weight: 700; line-height: 1;'
        . ' border: 2px solid var(--gin-bg-layer, #fff);">!</span>'
      : '';
    return Markup::create(
      '<span title="' . Html::escape((string) $info) . '">'
      . '<span style="position: relative; display: inline-flex; align-items: center; justify-content: center;'
      . ' width: 44px; height: 44px; border-radius: 50%; background: ' . $ring . ';">'
      . '<span style="display: inline-flex; align-items: center; justify-content: center;'
      . ' width: 34px; height: 34px; border-radius: 50%; background: #fff; color: #222;'
      . ' font-size: 11px; font-weight: 600; line-height: 1;">'
      . Html::escape($text) . '</span>' . $badge . '</span></span>'
    );
  }

}
