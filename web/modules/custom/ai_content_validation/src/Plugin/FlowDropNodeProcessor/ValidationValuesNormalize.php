<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;

/**
 * Normalizes AI validation output for entity_save.
 *
 * The AI emits field_validation_result as a nested object (reliable for
 * LLMs); the storage field is string_long. This node JSON-encodes the
 * object so entity_save can write it, avoiding the fragile
 * escaped-JSON-inside-JSON prompt contract.
 */
#[FlowDropNodeProcessor(
  id: 'ai_content_validation_normalize',
  label: new TranslatableMarkup('Normalize Validation Values'),
  description: 'JSON-encodes the field_validation_result object for storage',
  version: '1.0.0',
)]
final class ValidationValuesNormalize extends AbstractFlowDropNodeProcessor {

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'data' => [
          'type' => 'object',
          'description' => 'The parsed validation entity values from the AI.',
        ],
      ],
      'required' => ['data'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'values' => [
          'type' => 'object',
          'description' => 'Entity values with field_validation_result JSON-encoded.',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $data = $params->getArray('data');
    if (isset($data['field_validation_result']) && is_array($data['field_validation_result'])) {
      $result = $data['field_validation_result'];
      // The model classifies each guideline (pass/minor/major/fail) —
      // classification is far more reproducible than a 0-10 number, which
      // wobbled ±1-2 per guideline (±6 total) on unchanged content. The
      // numeric score is derived mechanically here, never by the model.
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
        $points = ['pass' => 10, 'minor' => 8, 'major' => 4, 'fail' => 0];
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
          $result['score'] = (int) min(100, max(0, array_sum($numeric)));
        }
      }
      $data['field_validation_result'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    // Every AI result awaits a human decision: a score report is accepted
    // or ignored, a proposal is applied or ignored. Done is always a
    // UI-driven transition, never set by the model.
    $data['field_validation_status'] = 'pending';
    return ['values' => $data];
  }

}
