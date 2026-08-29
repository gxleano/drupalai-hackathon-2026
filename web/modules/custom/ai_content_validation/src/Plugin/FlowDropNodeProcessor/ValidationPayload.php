<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
    return ['json' => self::header($this->loadNode($data), $data) . "\n" . Json::encode($data)];
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
      $lines[] = '"' . $field . '" (' . $label . ')'
        . (in_array($field, $fixable, TRUE) ? '' : ' — assess only, never propose a replacement value');
    }
    return 'ASSESSED FIELDS (' . count($lines) . ', exactly these, on every run): '
      . implode('; ', $lines)
      . '. Report one "field_findings" entry and one "field_verdicts" entry for EACH of them, using these exact key names. '
      . 'A field whose value is empty is still assessed — say so in its finding.';
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
