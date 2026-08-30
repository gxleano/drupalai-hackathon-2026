<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor;

use Drupal\Component\Serialization\Json;
use Drupal\ai_content_validation\MediaReferenceDetails;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\Exception\EntityProcessingException;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Service\EntitySerializer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serializes candidate field values as validator-ready JSON.
 *
 * The validator never consumes an entity: in `content_validation_fixer`
 * the chain is Content Context -> `data_to_json` -> chat, and the chat
 * node receives a plain JSON string on its `message` input. Scoring
 * improver output therefore needs only a string in exactly that shape,
 * not a "validate an unsaved entity" mechanism.
 *
 * This node takes a node revision as the base, substitutes the candidate
 * values on a clone and emits the same JSON the Content Context ->
 * `data_to_json` pair emits for a saved node — the same
 * `flowdrop.entity_serializer` output, encoded with the same
 * `Json::encode()` call. Deriving the payload from the real entity (never
 * hand-building a dictionary of keys) is what keeps the shape correct as
 * the content model evolves, and it is what lets the non-regression gate
 * compare a candidate score against a baseline score fairly.
 *
 * The node never saves and never mutates the stored entity: it operates
 * on a clone, so nothing it does can leak into the entity storage cache.
 */
#[FlowDropNodeProcessor(
  id: 'ai_content_validation_candidate_json',
  label: new TranslatableMarkup('Candidate Values to JSON'),
  description: 'Serializes candidate field values as validator-ready JSON without saving the node',
  version: '1.0.0',
)]
final class CandidateValuesToJson extends AbstractFlowDropNodeProcessor {

  /**
   * Base fields that may never be supplied as a candidate value.
   *
   * The validator's DATES rule treats the entity timestamps in its input
   * as its definition of "now". Letting a candidate move `created`,
   * `changed` or the revision timestamps would judge the candidate
   * against a different "now" than the baseline was judged against, so
   * the score comparison would no longer be meaningful. Identity fields
   * are blocked for the same reason: the prompt reads `id` and
   * `fields.vid[0].value` to attribute its verdict to a revision.
   *
   * @var list<string>
   */
  private const PROTECTED_FIELDS = [
    'changed',
    'created',
    'default_langcode',
    'langcode',
    'nid',
    'revision_default',
    'revision_log',
    'revision_timestamp',
    'revision_translation_affected',
    'revision_uid',
    'type',
    'uuid',
    'vid',
  ];

  /**
   * Constructs a CandidateValuesToJson object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\flowdrop\Service\EntitySerializer $entitySerializer
   *   The FlowDrop entity serializer, i.e. the exact serialisation the
   *   Content Context node feeds into `data_to_json`.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitySerializer $entitySerializer,
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
   *   The plugin ID.
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
      $container->get('flowdrop.entity_serializer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'entity_id' => [
          'type' => 'string',
          'title' => 'Node ID',
          'description' => 'The node to use as the serialisation base.',
          'default' => '',
          'required' => TRUE,
        ],
        'revision_id' => [
          'type' => 'string',
          'title' => 'Revision ID',
          'description' => 'Optional revision to use as the base, so the candidate is compared against the revision that was validated. Empty means the default revision.',
          'default' => '',
        ],
        'candidate_values' => [
          'type' => 'object',
          'title' => 'Candidate values',
          'description' => 'Replacement field values keyed by field name (title, field_body, field_metatags). Values are full replacements, in field-item shape.',
          'additionalProperties' => TRUE,
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
        'json' => [
          'type' => 'string',
          'description' => 'The candidate serialisation as a JSON string, in the same shape data_to_json emits for a saved node.',
        ],
        'data' => [
          'type' => 'object',
          'description' => 'The same candidate serialisation as structured data.',
          'additionalProperties' => TRUE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When no node ID is supplied, or a candidate value names an unknown
   *   or protected field, or arrives in a shape that entity save would
   *   accept while silently discarding part of it (a bare string for a
   *   multi-property field such as `field_body`).
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When the node or revision cannot be loaded, or is not fieldable.
   */
  public function process(ParameterBagInterface $params): array {
    $entity_id = trim($params->getString('entity_id', ''));
    if ($entity_id === '') {
      throw new \InvalidArgumentException('A node ID is required to serialize candidate values.');
    }
    $revision_id = trim($params->getString('revision_id', ''));
    $candidates = $params->getArray('candidate_values', []);

    $base = $this->loadNode($entity_id, $revision_id);
    if (!$base instanceof FieldableEntityInterface) {
      throw new EntityProcessingException(sprintf('Node %s is not fieldable and cannot be serialized.', $entity_id));
    }

    // Cloning is what keeps this node read-only: ContentEntityBase
    // rebinds its field items to the clone, so the substitutions below
    // cannot reach the instance sitting in the entity storage cache.
    // save() is never called, and the timestamps stay whatever the base
    // revision carries.
    $candidate = clone $base;
    $label_only = [];
    $alt_overrides = [];
    foreach ($candidates as $field_name => $value) {
      $field_name = (string) $field_name;
      $this->assertCandidateIsApplicable($candidate, $field_name, $value);
      // A tags candidate arrives as label-only reference items (term
      // names, no target ids — the terms may not exist yet). They cannot
      // go through $candidate->set(); the serialized output is overridden
      // below instead, so the validator scores the labels while this node
      // stays read-only.
      if ($this->isLabelOnlyReference($candidate, $field_name, $value)) {
        $label_only[$field_name] = $this->resolveReferenceItems($candidate, $field_name, $value);
        continue;
      }
      // A media alt-text candidate keeps its targets and only overrides
      // target_alt; the override is applied after serialization (and
      // after the alt/title enrichment, which it must win over).
      if ($this->isAltOverrideReference($candidate, $field_name, $value)) {
        $alt_overrides[$field_name] = $value;
        continue;
      }
      $candidate->set($field_name, $value);
    }

    $data = $this->entitySerializer->serialize($candidate);
    foreach ($label_only as $field_name => $items) {
      if (is_array($data['fields'] ?? NULL)) {
        $data['fields'][$field_name] = $items;
      }
    }
    // Keep the candidate byte-comparable to the baseline: the payload node
    // enriches media references with alt/title, so the gate must too.
    if (is_array($data['fields'] ?? NULL)) {
      $data['fields'] = MediaReferenceDetails::enrich($candidate, $data['fields']);
      foreach ($alt_overrides as $field_name => $items) {
        foreach ($items as $override) {
          if (!isset($override['target_alt'])) {
            continue;
          }
          foreach ($data['fields'][$field_name] ?? [] as $delta => $serialized) {
            if (is_array($serialized) && (int) ($serialized['target_id'] ?? -1) === (int) ($override['target_id'] ?? -2)) {
              $data['fields'][$field_name][$delta]['target_alt'] = $override['target_alt'];
            }
          }
        }
      }
    }

    // Json::encode() is the encoder data_to_json uses. Matching it makes
    // the emitted string byte-identical to the baseline for unchanged
    // values, so the model sees the same text, not merely equivalent
    // data.
    return [
      'json' => Json::encode($data),
      'data' => $data,
    ];
  }

  /**
   * Loads the node to use as the serialisation base.
   *
   * Access intent: none is applied. The node is addressed by an ID the
   * calling workflow already resolved from an access-checked context
   * (the AI Validation form, or a validation item's content revision
   * reference), and the output never leaves the workflow — it is fed to
   * the validator prompt. Adding a check here would instead break
   * workflow runs that legitimately have no interactive user, such as
   * cron or entity-save triggers.
   *
   * @param string $entity_id
   *   The node ID.
   * @param string $revision_id
   *   The revision ID, or an empty string for the default revision.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The loaded node.
   *
   * @throws \Drupal\flowdrop\Exception\EntityProcessingException
   *   When the node or revision does not exist, or the revision belongs
   *   to a different node.
   */
  private function loadNode(string $entity_id, string $revision_id): EntityInterface {
    $storage = $this->entityTypeManager->getStorage('node');

    if ($revision_id !== '' && $storage instanceof RevisionableStorageInterface) {
      $entity = $storage->loadRevision((int) $revision_id);
      if ($entity === NULL) {
        throw new EntityProcessingException(sprintf('Node revision %s not found.', $revision_id));
      }
      if ((string) $entity->id() !== $entity_id) {
        throw new EntityProcessingException(sprintf('Revision %s does not belong to node %s.', $revision_id, $entity_id));
      }
      return $entity;
    }

    $entity = $storage->load($entity_id);
    if ($entity === NULL) {
      throw new EntityProcessingException(sprintf('Node %s not found.', $entity_id));
    }
    return $entity;
  }

  /**
   * Rejects candidate values that would not be applied as supplied.
   *
   * A bare string handed to a multi-property field is the failure mode
   * this guards: entity save accepts it, the field ends up without its
   * remaining properties, and the editor sees the field unchanged. The
   * gate must never score content that would not actually be applied,
   * so the mismatch fails loudly here instead.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The candidate entity the value would be set on.
   * @param string $field_name
   *   The field name the candidate value is keyed by.
   * @param mixed $value
   *   The candidate value.
   *
   * @throws \InvalidArgumentException
   *   When the field is unknown, protected, or the value shape would be
   *   applied only in part.
   */
  private function assertCandidateIsApplicable(FieldableEntityInterface $entity, string $field_name, mixed $value): void {
    if (in_array($field_name, self::PROTECTED_FIELDS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Field "%s" may not be supplied as a candidate value: the validator reads the entity timestamps and revision identity as its definition of "now".', $field_name));
    }
    if (!$entity->hasField($field_name)) {
      throw new \InvalidArgumentException(sprintf('Candidate value for unknown field "%s".', $field_name));
    }

    if (is_array($value)) {
      return;
    }
    if (is_object($value)) {
      throw new \InvalidArgumentException(sprintf('Candidate value for field "%s" must be a scalar or a field-item array, %s given.', $field_name, get_debug_type($value)));
    }

    // A scalar only carries the field's main property, so it is lossless
    // exactly when the field stores nothing else.
    $properties = $this->storageProperties($entity, $field_name);
    if (count($properties) > 1) {
      throw new \InvalidArgumentException(sprintf('Candidate value for field "%s" must be a field-item array with the keys %s; a bare %s would leave the other properties unset and the field would not actually be applied.', $field_name, implode(', ', $properties), get_debug_type($value)));
    }
  }

  /**
   * Whether a candidate value is a list of label-only reference items.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The candidate entity.
   * @param string $field_name
   *   The field the value targets.
   * @param mixed $value
   *   The candidate value.
   *
   * @return bool
   *   TRUE for an entity_reference field whose candidate items carry a
   *   target_label but no target_id.
   */
  private function isLabelOnlyReference(FieldableEntityInterface $entity, string $field_name, mixed $value): bool {
    if ($entity->getFieldDefinition($field_name)?->getType() !== 'entity_reference' || !is_array($value) || $value === []) {
      return FALSE;
    }
    foreach ($value as $item) {
      if (!is_array($item) || !isset($item['target_label']) || isset($item['target_id'])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether a candidate value is a target list overriding only alt text.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The candidate entity.
   * @param string $field_name
   *   The field the value targets.
   * @param mixed $value
   *   The candidate value.
   *
   * @return bool
   *   TRUE for an entity_reference field whose candidate items all carry
   *   a target_id and at least one carries a target_alt.
   */
  private function isAltOverrideReference(FieldableEntityInterface $entity, string $field_name, mixed $value): bool {
    if ($entity->getFieldDefinition($field_name)?->getType() !== 'entity_reference' || !is_array($value) || $value === []) {
      return FALSE;
    }
    $has_alt = FALSE;
    foreach ($value as $item) {
      if (!is_array($item) || !isset($item['target_id'])) {
        return FALSE;
      }
      $has_alt = $has_alt || isset($item['target_alt']);
    }
    return $has_alt;
  }

  /**
   * Shapes label-only reference items like the serializer would emit them.
   *
   * Names matching an existing term get its real target_id so the
   * candidate serialisation stays as close as possible to what a saved
   * node would produce; unknown names keep target_id 0 — the label is
   * what the validator reads either way.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The candidate entity.
   * @param string $field_name
   *   The reference field.
   * @param array<int, array{target_label: string}> $items
   *   The label-only items.
   *
   * @return list<array{target_id: int, target_label: string}>
   *   Serializer-shaped reference items.
   */
  private function resolveReferenceItems(FieldableEntityInterface $entity, string $field_name, array $items): array {
    $settings = $entity->getFieldDefinition($field_name)?->getSetting('handler_settings') ?? [];
    $bundles = array_values(array_filter((array) ($settings['target_bundles'] ?? [])));
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $resolved = [];
    foreach ($items as $item) {
      $name = (string) $item['target_label'];
      // accessCheck(FALSE): read-only lookup feeding the validator prompt,
      // same access posture as loadNode() above.
      $query = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name);
      if ($bundles !== []) {
        $query->condition('vid', $bundles, 'IN');
      }
      $ids = $query->range(0, 1)->execute();
      $resolved[] = [
        'target_id' => $ids === [] ? 0 : (int) reset($ids),
        'target_label' => $name,
      ];
    }
    return $resolved;
  }

  /**
   * Lists the stored (non-computed) property names of a field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity owning the field.
   * @param string $field_name
   *   The field name.
   *
   * @return list<string>
   *   The stored property names, in definition order.
   */
  private function storageProperties(FieldableEntityInterface $entity, string $field_name): array {
    $definitions = $entity->get($field_name)
      ->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getPropertyDefinitions();

    $properties = [];
    foreach ($definitions as $name => $definition) {
      if (!$definition->isComputed()) {
        $properties[] = (string) $name;
      }
    }
    return $properties;
  }

}
