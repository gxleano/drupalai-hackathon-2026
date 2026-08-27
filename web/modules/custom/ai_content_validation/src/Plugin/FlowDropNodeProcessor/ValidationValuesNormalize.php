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
      // The model judges per guideline (0-10 each) but is unreliable at
      // adding them up: recompute the total mechanically so the score is
      // always the exact sum of the rubric breakdown.
      $scores = $result['scores'] ?? NULL;
      if (is_array($scores) && count($scores) === 10) {
        $numeric = array_filter($scores, 'is_numeric');
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
