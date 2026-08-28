<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_validation\ContentHasher;
use Drupal\ai_content_validation\JsonRepair;
use Drupal\ai_content_validation\ValidationScorer;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Normalizes AI validation output for entity_save.
 *
 * The AI emits field_validation_result as a nested object (reliable for
 * LLMs); the storage field is string_long. This node JSON-encodes the
 * object so entity_save can write it, avoiding the fragile
 * escaped-JSON-inside-JSON prompt contract.
 *
 * It also stamps the content hash of the revision the result is attached
 * to, so a later run on unchanged content can reuse this result instead of
 * calling the model again.
 */
#[FlowDropNodeProcessor(
  id: 'ai_content_validation_normalize',
  label: new TranslatableMarkup('Normalize Validation Values'),
  description: 'JSON-encodes the field_validation_result object for storage',
  version: '1.0.0',
)]
final class ValidationValuesNormalize extends AbstractFlowDropNodeProcessor {

  /**
   * Constructs the processor.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load the validated node revision.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

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
        'json' => [
          'type' => 'string',
          'description' => 'The raw model response, used to repair-parse when the upstream JSON parse delivered nothing (e.g. a dropped closing brace).',
          'default' => '',
        ],
      ],
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
    // The upstream json_to_data node delivers NULL when the model's JSON
    // is mechanically broken (typically a dropped final brace). The raw
    // response is wired in as a fallback and repaired deterministically —
    // failing the whole 30-second run over one missing brace is worse.
    if ($data === []) {
      $data = JsonRepair::parse($params->getString('json')) ?? [];
    }
    if ($data === []) {
      throw new \RuntimeException('The validator response could not be parsed as JSON, even after repair.');
    }
    if (isset($data['field_validation_result']) && is_array($data['field_validation_result'])) {
      $result = $data['field_validation_result'];
      // The numeric score is derived mechanically from the model's ten
      // verdicts, never produced by the model. The computation lives in
      // ValidationScorer so the AI Improve non-regression gate scores its
      // candidate on exactly the same scale as this header score.
      $result = ValidationScorer::applyDerivedScore($result);
      // The hash of the exact field values this verdict was formed on.
      // A later run whose content hashes the same reuses this result
      // instead of asking the model again, so the score of unchanged
      // content is deterministic.
      $hash = $this->contentHash($data['field_content_revision'] ?? NULL);
      if ($hash !== '') {
        $result['content_hash'] = $hash;
      }
      $data['field_validation_result'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    // Every AI result awaits a human decision: a score report is accepted
    // or ignored, a proposal is applied or ignored. Done is always a
    // UI-driven transition, never set by the model. The one exception is
    // a proposal the improve gate itself discarded (rejected, or empty):
    // there is nothing left for a human to decide, so it is filed as
    // ignored right away instead of sitting in Pending forever.
    $stored_result = $data['field_validation_result'] ?? NULL;
    if (is_string($stored_result)) {
      $stored_result = json_decode($stored_result, TRUE);
    }
    $outcome = is_array($stored_result) ? ($stored_result['outcome'] ?? NULL) : NULL;
    $data['field_validation_status'] = in_array($outcome, ['improve_rejected', 'improve_no_suggestions'], TRUE)
      ? 'ignored'
      : 'pending';
    return ['values' => $data];
  }

  /**
   * Hashes the node revision this result is about.
   *
   * The hash is computed here, next to the reference the result is stored
   * with, so it always describes the revision the item is attached to.
   *
   * @param mixed $reference
   *   The field_content_revision value from the incoming data: a map with
   *   target_id and target_revision_id, or a list of such maps.
   *
   * @return string
   *   The content hash, or an empty string when the referenced revision
   *   cannot be resolved.
   */
  private function contentHash(mixed $reference): string {
    if (is_array($reference) && isset($reference[0]) && is_array($reference[0])) {
      $reference = $reference[0];
    }
    if (!is_array($reference)) {
      return '';
    }
    $nid = (int) ($reference['target_id'] ?? 0);
    $vid = (int) ($reference['target_revision_id'] ?? 0);
    if ($nid === 0) {
      return '';
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $revision = $vid === 0 ? NULL : $storage->loadRevision($vid);
    // A revision id the model misread could belong to another node; only
    // the revision that really belongs to the referenced node may be
    // hashed, otherwise the hash would describe foreign content.
    if (!$revision instanceof FieldableEntityInterface || (int) $revision->id() !== $nid) {
      $revision = $storage->load($nid);
    }
    return $revision instanceof FieldableEntityInterface ? ContentHasher::hash($revision) : '';
  }

}
