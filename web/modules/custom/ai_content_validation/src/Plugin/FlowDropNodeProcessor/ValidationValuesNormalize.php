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
        // Declared as strings: the Content Context node emits entity ids
        // as strings, and FlowDrop type-checks the wire, not the intent.
        'entity_id' => [
          'type' => 'string',
          'description' => 'The validated node id, from the run\'s own entity context. Overrides whatever the model reported.',
          'default' => '',
        ],
        'revision_id' => [
          'type' => 'string',
          'description' => 'The validated node revision id, from the run\'s own entity context.',
          'default' => '',
        ],
        'workflow_id' => [
          'type' => 'string',
          'description' => 'The workflow this report belongs to. Overrides the model.',
          'default' => '',
        ],
      ],
    ];
  }

  /**
   * The only entity values a validation report may carry.
   *
   * The model's JSON is untrusted input — an article body can instruct it
   * to emit anything — and entity_save writes every key it is handed when
   * its own allowed_fields is empty. Whitelisting here means the report
   * shape is defined by this module, not by the model output, whatever a
   * downstream node's configuration happens to be.
   */
  private const ALLOWED_FIELDS = [
    'label',
    'description',
    'status',
    'field_flowdrop_workflow',
    'field_content_revision',
    'field_validation_status',
    'field_validation_result',
  ];

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
    // The entity this report is about is decided by the run, never by the
    // model: a misread or injected id would attach the report to another
    // node, and the supersede rule keys off exactly this reference.
    $entity_id = (int) $params->getString('entity_id');
    $revision_id = (int) $params->getString('revision_id');
    if ($entity_id > 0) {
      $data['field_content_revision'] = [
        'target_id' => $entity_id,
        'target_revision_id' => $revision_id > 0 ? $revision_id : NULL,
      ];
    }
    $workflow_id = $params->getString('workflow_id');
    if ($workflow_id !== '') {
      $data['field_flowdrop_workflow'] = $workflow_id;
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
      $revision = $this->validatedRevision($data['field_content_revision'] ?? NULL);
      if ($revision !== NULL) {
        $result['content_hash'] = ContentHasher::hash($revision);
        // Per-field digests: what the next run compares against to decide
        // which fields it may keep instead of re-judging.
        $result['field_hashes'] = ContentHasher::fieldHashes($revision);
        $result = $this->carryForward($result, $revision, $entity_id, $workflow_id);
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
    return ['values' => array_intersect_key($data, array_flip(self::ALLOWED_FIELDS))];
  }

  /**
   * Loads the node revision this result is about.
   *
   * Resolved here, next to the reference the result is stored with, so
   * every digest and every carried-forward verdict describes exactly the
   * revision the item is attached to.
   *
   * @param mixed $reference
   *   The field_content_revision value from the incoming data: a map with
   *   target_id and target_revision_id, or a list of such maps.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The revision, or NULL when it cannot be resolved.
   */
  private function validatedRevision(mixed $reference): ?FieldableEntityInterface {
    if (is_array($reference) && isset($reference[0]) && is_array($reference[0])) {
      $reference = $reference[0];
    }
    if (!is_array($reference)) {
      return NULL;
    }
    $nid = (int) ($reference['target_id'] ?? 0);
    $vid = (int) ($reference['target_revision_id'] ?? 0);
    if ($nid === 0) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $revision = $vid === 0 ? NULL : $storage->loadRevision($vid);
    // A revision id the model misread could belong to another node; only
    // the revision that really belongs to the referenced node may be
    // hashed, otherwise the hash would describe foreign content.
    if (!$revision instanceof FieldableEntityInterface || (int) $revision->id() !== $nid) {
      $revision = $storage->load($nid);
    }
    return $revision instanceof FieldableEntityInterface ? $revision : NULL;
  }

  /**
   * Keeps the previous verdict for every field that did not change.
   *
   * One prompt judges all fields at once, so editing one field re-rolls
   * the model's answer for all the others: a body nobody touched flips
   * between "pass" and "review" from run to run, and the fix/re-fix loop
   * never settles. The fields are compared by their own digests and the
   * untouched ones keep exactly the finding and verdict they already had,
   * which is what the validator prompt asks for and cannot guarantee.
   *
   * Only whole-content verdict reports carry field verdicts; an improve
   * proposal has none and passes through untouched.
   *
   * @param array<string, mixed> $result
   *   The result this run produced.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $revision
   *   The revision that was validated.
   * @param int $nid
   *   The node id whose earlier reports are compared against.
   * @param string $workflow_id
   *   The workflow whose earlier reports count; '' compares none.
   *
   * @return array<string, mixed>
   *   The result with unchanged fields restored to their prior verdict.
   */
  private function carryForward(array $result, FieldableEntityInterface $revision, int $nid, string $workflow_id): array {
    if ($nid === 0 || $workflow_id === '' || !is_array($result['field_verdicts'] ?? NULL)) {
      return $result;
    }
    $previous = $this->previousResult($nid, $workflow_id);
    $old_hashes = is_array($previous['field_hashes'] ?? NULL) ? $previous['field_hashes'] : [];
    if ($old_hashes === []) {
      return $result;
    }
    $findings = is_array($result['field_findings'] ?? NULL) ? $result['field_findings'] : [];
    $verdicts = $result['field_verdicts'];
    foreach ($result['field_hashes'] as $field => $hash) {
      $unchanged = isset($old_hashes[$field]) && $old_hashes[$field] === $hash;
      $had_verdict = isset($previous['field_verdicts'][$field]);
      if (!$unchanged || !$had_verdict) {
        continue;
      }
      $verdicts[$field] = $previous['field_verdicts'][$field];
      if (isset($previous['field_findings'][$field])) {
        $findings[$field] = $previous['field_findings'][$field];
      }
    }
    $result['field_verdicts'] = $verdicts;
    $result['field_findings'] = $findings;

    return $result;
  }

  /**
   * Reads the newest stored result for a node and workflow.
   *
   * @param int $nid
   *   The node id.
   * @param string $workflow_id
   *   The workflow the report belongs to.
   *
   * @return array<string, mixed>
   *   The decoded result, or an empty array when there is none.
   */
  private function previousResult(int $nid, string $workflow_id): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    // accessCheck(FALSE): an internal consistency lookup inside a
    // system-triggered run, disclosing nothing to anyone.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_content_revision.target_id', $nid)
      ->condition('field_flowdrop_workflow', $workflow_id)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $item = $ids === [] ? NULL : $storage->load(reset($ids));
    if ($item === NULL) {
      return [];
    }
    $decoded = json_decode((string) ($item->get('field_validation_result')->value ?? ''), TRUE);
    if (is_string($decoded)) {
      $decoded = json_decode($decoded, TRUE);
    }

    return is_array($decoded) ? $decoded : [];
  }

}
