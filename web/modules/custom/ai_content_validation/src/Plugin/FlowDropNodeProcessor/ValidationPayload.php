<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_validation\MediaReferenceDetails;
use Drupal\ai_content_validation\ValidatedFields;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the validator's user message: the assessed field list plus the JSON.
 *
 * Drop-in replacement for a plain data_to_json node in front of the
 * validator chat node. The model is not asked to work out which fields it
 * should report on — the list is computed here from the node's own field
 * definitions and stated literally at the top of the message, so a field
 * added to the content type is assessed without editing the prompt.
 *
 * The header text is shared with the AI Improve non-regression gate, which
 * assembles its candidate message itself; both therefore present the model
 * with the same contract.
 */
#[FlowDropNodeProcessor(
  id: 'ai_content_validation_payload',
  label: new TranslatableMarkup('Validator Payload'),
  description: 'Serializes the node and prefixes the assessed field list',
  version: '1.0.0',
)]
final class ValidationPayload extends AbstractFlowDropNodeProcessor {

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
   *   The entity type manager, used to load the node being validated.
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
          'description' => 'The serialized node coming from the content context node.',
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
        'json' => [
          'type' => 'string',
          'description' => 'The assessed field list followed by the node JSON.',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $data = $params->getArray('data');
    if ($data === []) {
      throw new \RuntimeException('The validator payload node received no entity data.');
    }
    $node = $this->loadNode($data);
    // Media references arrive as filename labels only — append the source
    // image's alt/title so the validator can judge accessibility text.
    if ($node !== NULL && is_array($data['fields'] ?? NULL)) {
      $data['fields'] = MediaReferenceDetails::enrich($node, $data['fields']);
    }
    return ['json' => self::header($node, $data) . "\n" . Json::encode($data)];
  }

  /**
   * Renders the assessed-field contract shown to the validator.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $node
   *   The node being validated, or NULL when it cannot be loaded.
   * @param array<string, mixed> $data
   *   The serialized node, used to derive the field list without the
   *   entity when the node is gone.
   *
   * @return string
   *   The header block, ending without a trailing newline.
   */
  public static function header(?FieldableEntityInterface $node, array $data = []): string {
    $labels = $node instanceof FieldableEntityInterface
      ? ValidatedFields::labels($node)
      : self::labelsFromData($data);
    $fixable = $node instanceof FieldableEntityInterface
      ? array_keys(ValidatedFields::fixableLabels($node))
      : array_keys($labels);

    $lines = [];
    foreach ($labels as $field => $label) {
      // "Assess only" means the editor fixes it by hand, NOT that the
      // field gets a lighter verdict — spelled out because the short form
      // read as permission to wave the field through.
      $lines[] = '"' . $field . '" (' . $label . ')'
        . (in_array($field, $fixable, TRUE) ? '' : ' — assess only: judge it exactly as strictly as every other field, but never propose a replacement value for it');
    }
    return 'ASSESSED FIELDS (' . count($lines) . ', exactly these, on every run): '
      . implode('; ', $lines)
      . '. Report one "field_findings" entry and one "field_verdicts" entry for EACH of them, using these exact key names. '
      . 'A field whose value is empty is still assessed — say so in its finding. '
      . 'REFERENCE FIELDS (tags, categories, media and any other field whose items carry a "target_label"): the labels ARE the value — judge every one of them by name. '
      . 'A term that does not describe THIS article is a problem: name it in that field\'s finding and set that field\'s verdict to "review" (Guideline 6, Audience Relevance). '
      . 'Never answer "the field is present and relevant" without having weighed each label against the article; presence is not relevance. '
      . 'Quote the offending label verbatim in the finding (e.g. "the tag \'Drupal\' is unrelated to this article"), and never prefix a finding with "Assess only" — the verdict carries that. '
      . 'A tags field that is EMPTY is ALWAYS a problem, with no exception: its finding MUST read "the tags field is empty — descriptive tags must be added (Guideline 8, Completeness)" and its verdict MUST be "review", never "pass". '
      . 'IMAGE/MEDIA ITEMS carrying a "target_alt" key: the alt text is part of the value — judge it. Empty or missing alt text, or alt text that does not describe the image for a screen-reader user (a filename, a single word, marketing copy), is an accessibility problem: name it and set that field\'s verdict to "review" (Guideline 9, Inclusivity & Language Ethics). An empty "target_title" is acceptable and never a finding. '
      . 'FINAL CHECK before you answer: for every field whose finding names ANY problem — including an assess-only field, including a merely irrelevant term — that field\'s "field_verdicts" entry MUST be "review". A finding that states a problem next to a "pass" verdict is a self-contradiction and is rejected.';
  }

  /**
   * Derives the assessed fields from the serialized node alone.
   *
   * @param array<string, mixed> $data
   *   The serialized node.
   *
   * @return array<string, string>
   *   Field machine name => label (the machine name, for want of a
   *   definition to read a real label from).
   */
  private static function labelsFromData(array $data): array {
    $labels = isset($data['title']) ? ['title' => 'Title'] : [];
    foreach (array_keys(is_array($data['fields'] ?? NULL) ? $data['fields'] : []) as $name) {
      if (str_starts_with((string) $name, 'field_')) {
        $labels[(string) $name] = (string) $name;
      }
    }
    return $labels;
  }

  /**
   * Loads the node the serialized data describes.
   *
   * @param array<string, mixed> $data
   *   The serialized node.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The node, or NULL when the payload carries no usable id.
   */
  private function loadNode(array $data): ?FieldableEntityInterface {
    $nid = $data['id'] ?? $data['nid'] ?? NULL;
    if (!is_numeric($nid)) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    return $node instanceof FieldableEntityInterface ? $node : NULL;
  }

}
