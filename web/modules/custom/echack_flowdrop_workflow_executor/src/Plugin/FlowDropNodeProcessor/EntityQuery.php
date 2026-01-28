<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_workflow_executor\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationError;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Service\EntitySerializer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity Query node processor for FlowDrop workflows.
 *
 * This plugin enables querying Drupal entities within a workflow.
 * Useful for batch processing, content validation, and data pipelines.
 * Results can be passed to ForEach loops for iteration.
 */
#[FlowDropNodeProcessor(
  id: "entity_query",
  label: new TranslatableMarkup("Entity Query"),
  description: "Query Drupal entities (nodes, terms, users, etc.) with configurable conditions",
  version: "1.0.0"
)]
class EntityQuery extends AbstractFlowDropNodeProcessor {

  /**
   * Constructs an EntityQuery object.
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
   *   The entity serializer.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntitySerializer $entitySerializer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
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
      $container->get("entity_type.manager"),
      $container->get("flowdrop.entity_serializer")
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    $errors = [];

    // Validate entity_type is provided.
    $entityType = $params["entity_type"] ?? "";
    if (empty($entityType)) {
      $errors[] = new ValidationError("entity_type", "Entity type is required");
    }
    else {
      // Check if entity type exists.
      try {
        $this->entityTypeManager->getDefinition($entityType);
      }
      catch (\Exception $e) {
        $errors[] = new ValidationError("entity_type", "Invalid entity type: {$entityType}");
      }
    }

    // Validate limit.
    $limit = $params["limit"] ?? 100;
    if (!is_int($limit) && !is_numeric($limit)) {
      $errors[] = new ValidationError("limit", "Limit must be a number");
    }
    elseif ((int) $limit < 1 || (int) $limit > 1000) {
      $errors[] = new ValidationError("limit", "Limit must be between 1 and 1000");
    }

    if (!empty($errors)) {
      return ValidationResult::failure($errors);
    }

    return ValidationResult::success();
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $entityType = $params->getString("entity_type", "node");
    $bundle = $params->getString("bundle", "");
    $limit = $params->getInt("limit", 100);
    $offset = $params->getInt("offset", 0);
    $publishedOnly = $params->getBool("published_only", TRUE);
    $changedAfter = $params->getInt("changed_after", 0);
    $sortField = $params->getString("sort_field", "changed");
    $sortDirection = $params->getString("sort_direction", "DESC");
    $loadEntities = $params->getBool("load_entities", FALSE);
    $conditions = $params->getArray("conditions", []);

    try {
      $storage = $this->entityTypeManager->getStorage($entityType);
      $query = $storage->getQuery()->accessCheck(FALSE);

      // Apply bundle condition if specified.
      if (!empty($bundle)) {
        $bundleKey = $this->entityTypeManager->getDefinition($entityType)->getKey("bundle");
        if ($bundleKey) {
          $query->condition($bundleKey, $bundle);
        }
      }

      // Apply published condition for entities that support it.
      if ($publishedOnly) {
        $statusKey = $this->entityTypeManager->getDefinition($entityType)->getKey("status");
        if ($statusKey) {
          $query->condition($statusKey, 1);
        }
      }

      // Apply changed_after condition.
      if ($changedAfter > 0) {
        $query->condition("changed", $changedAfter, ">");
      }

      // Apply custom conditions.
      foreach ($conditions as $condition) {
        if (isset($condition["field"]) && isset($condition["value"])) {
          $operator = $condition["operator"] ?? "=";
          $query->condition($condition["field"], $condition["value"], $operator);
        }
      }

      // Apply sorting.
      if (!empty($sortField)) {
        $query->sort($sortField, $sortDirection);
      }

      // Apply range.
      $query->range($offset, $limit);

      // Execute query.
      $ids = $query->execute();

      // Prepare results.
      $results = [];
      $entities = [];

      if (!empty($ids)) {
        if ($loadEntities) {
          // Load and serialize full entities.
          $loadedEntities = $storage->loadMultiple($ids);
          foreach ($loadedEntities as $entity) {
            $serialized = $this->entitySerializer->serialize($entity);
            $entities[] = $serialized;
            $results[] = [
              "id" => $entity->id(),
              "uuid" => $entity->uuid(),
              "label" => $entity->label() ?? "",
              "bundle" => $entity->bundle(),
              "entity" => $serialized,
            ];
          }
        }
        else {
          // Return just IDs and basic info.
          $loadedEntities = $storage->loadMultiple($ids);
          foreach ($loadedEntities as $entity) {
            $results[] = [
              "id" => $entity->id(),
              "uuid" => $entity->uuid(),
              "label" => $entity->label() ?? "",
              "bundle" => $entity->bundle(),
              "entity_type" => $entityType,
            ];
          }
        }
      }

      return [
        "success" => TRUE,
        "count" => count($results),
        "entity_type" => $entityType,
        "bundle" => $bundle,
        "results" => $results,
        "ids" => array_values(array_map("strval", $ids)),
        "entities" => $entities,
        "has_more" => count($ids) === $limit,
      ];
    }
    catch (\Exception $e) {
      return [
        "success" => FALSE,
        "count" => 0,
        "entity_type" => $entityType,
        "bundle" => $bundle,
        "results" => [],
        "ids" => [],
        "entities" => [],
        "has_more" => FALSE,
        "error_message" => $e->getMessage(),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    // Get available entity types.
    $entityTypeOptions = $this->getEntityTypeOptions();

    return [
      "type" => "object",
      "properties" => [
        "entity_type" => [
          "type" => "string",
          "title" => "Entity Type",
          "description" => "The entity type to query (node, taxonomy_term, user, etc.)",
          "enum" => array_keys($entityTypeOptions),
          "options" => array_map(
            fn($id, $label) => ["value" => $id, "label" => $label],
            array_keys($entityTypeOptions),
            array_values($entityTypeOptions)
          ),
          "default" => "node",
        ],
        "bundle" => [
          "type" => "string",
          "title" => "Bundle/Content Type",
          "description" => "Filter by bundle (e.g., article, page). Leave empty for all bundles.",
          "default" => "",
        ],
        "limit" => [
          "type" => "integer",
          "title" => "Limit",
          "description" => "Maximum number of entities to return",
          "default" => 100,
          "minimum" => 1,
          "maximum" => 1000,
        ],
        "offset" => [
          "type" => "integer",
          "title" => "Offset",
          "description" => "Number of entities to skip (for pagination)",
          "default" => 0,
          "minimum" => 0,
        ],
        "published_only" => [
          "type" => "boolean",
          "title" => "Published Only",
          "description" => "Only return published entities",
          "default" => TRUE,
        ],
        "changed_after" => [
          "type" => "integer",
          "title" => "Changed After (timestamp)",
          "description" => "Only return entities changed after this Unix timestamp. Use 0 to disable.",
          "default" => 0,
        ],
        "sort_field" => [
          "type" => "string",
          "title" => "Sort Field",
          "description" => "Field to sort by",
          "default" => "changed",
        ],
        "sort_direction" => [
          "type" => "string",
          "title" => "Sort Direction",
          "description" => "Sort direction",
          "enum" => ["ASC", "DESC"],
          "default" => "DESC",
        ],
        "load_entities" => [
          "type" => "boolean",
          "title" => "Load Full Entities",
          "description" => "Load and serialize full entity data (slower but more data)",
          "default" => FALSE,
        ],
        "conditions" => [
          "type" => "array",
          "title" => "Additional Conditions",
          "description" => "Custom query conditions (field, value, operator)",
          "items" => [
            "type" => "object",
            "properties" => [
              "field" => ["type" => "string"],
              "value" => ["type" => "string"],
              "operator" => ["type" => "string", "default" => "="],
            ],
          ],
          "default" => [],
        ],
      ],
      "required" => ["entity_type"],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      "type" => "object",
      "properties" => [
        "success" => [
          "type" => "boolean",
          "description" => "Whether the query was successful",
        ],
        "count" => [
          "type" => "integer",
          "description" => "Number of entities returned",
        ],
        "entity_type" => [
          "type" => "string",
          "description" => "The queried entity type",
        ],
        "bundle" => [
          "type" => "string",
          "description" => "The bundle filter applied",
        ],
        "results" => [
          "type" => "array",
          "description" => "Array of entity results (use with ForEach loop)",
          "items" => [
            "type" => "object",
            "properties" => [
              "id" => ["type" => "string"],
              "uuid" => ["type" => "string"],
              "label" => ["type" => "string"],
              "bundle" => ["type" => "string"],
              "entity_type" => ["type" => "string"],
              "entity" => ["type" => "object"],
            ],
          ],
        ],
        "ids" => [
          "type" => "array",
          "description" => "Array of entity IDs",
          "items" => ["type" => "string"],
        ],
        "entities" => [
          "type" => "array",
          "description" => "Array of serialized entities (when load_entities is true)",
        ],
        "has_more" => [
          "type" => "boolean",
          "description" => "Whether there are more results beyond the limit",
        ],
        "error_message" => [
          "type" => "string",
          "description" => "Error message if query failed",
        ],
      ],
    ];
  }

  /**
   * Gets available entity type options.
   *
   * @return array<string, string>
   *   Array of entity type IDs to labels.
   */
  protected function getEntityTypeOptions(): array {
    $options = [];

    try {
      $definitions = $this->entityTypeManager->getDefinitions();

      foreach ($definitions as $entityType => $definition) {
        // Only include content entities that can be queried.
        if ($definition->getGroup() === "content") {
          $options[$entityType] = (string) $definition->getLabel();
        }
      }
    }
    catch (\Exception $e) {
      // Return minimal options on error.
      $options = [
        "node" => "Content",
        "taxonomy_term" => "Taxonomy term",
        "user" => "User",
      ];
    }

    return $options;
  }

}
