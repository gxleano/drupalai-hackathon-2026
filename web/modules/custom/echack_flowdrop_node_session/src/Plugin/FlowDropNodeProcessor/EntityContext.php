<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_node_session\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ExecutionContextDTO;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationError;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ExecutionContextAwareInterface;
use Drupal\flowdrop\Service\EntitySerializer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity Context node processor for FlowDrop workflows.
 *
 * This plugin provides entity context to workflows, allowing workflows to
 * access a Drupal entity (node, taxonomy term, user, etc.) as context data.
 * The entity can be loaded from:
 * - Pre-injected entity data (from session context)
 * - Direct parameters (entity_id, entity_type)
 * - Session metadata (workflow_executor_input) for child workflows
 *
 * When used in child workflows triggered by Workflow Executor, the entity
 * info is automatically read from session metadata (workflow_executor_input).
 *
 * Supports optional revision loading - if revision_id is provided, that
 * specific revision is loaded instead of the current/default revision.
 */
#[FlowDropNodeProcessor(
  id: "entity_context",
  label: new TranslatableMarkup("Entity Context"),
  description: "Load a Drupal entity (node, term, etc.) as context for the workflow. Supports specific revision loading.",
  version: "1.0.0"
)]
class EntityContext extends AbstractFlowDropNodeProcessor implements ExecutionContextAwareInterface {

  /**
   * The execution context injected by the runtime.
   *
   * @var \Drupal\flowdrop\DTO\ExecutionContextDTO|null
   */
  protected ?ExecutionContextDTO $executionContext = NULL;

  /**
   * Constructs an EntityContext object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\flowdrop\Service\EntitySerializer $entitySerializer
   *   The entity serializer service.
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
  public function setExecutionContext(ExecutionContextDTO $context): void {
    $this->executionContext = $context;
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    $errors = [];

    // Check if entity data was pre-injected (from session context).
    // If 'entity' key exists with data, we don't need other params.
    if (isset($params["entity"]) && is_array($params["entity"]) && !empty($params["entity"])) {
      return ValidationResult::success();
    }

    // For child workflows triggered by WorkflowExecutor, entity_id and
    // entity_type might come from execution context (initialData) at runtime.
    // We allow missing params during validation and handle it in process().
    // Only validate format if values are provided.

    if (isset($params["entity_type"]) && !empty($params["entity_type"])) {
      if (!is_string($params["entity_type"])) {
        $errors[] = new ValidationError("entity_type", "Entity type must be a string");
      }
      else {
        // Check if the entity type exists.
        try {
          $this->entityTypeManager->getDefinition($params["entity_type"]);
        }
        catch (\Exception $e) {
          $errors[] = new ValidationError("entity_type", "Invalid entity type: {$params["entity_type"]}");
        }
      }
    }

    if (isset($params["entity_id"]) && !empty($params["entity_id"])) {
      if (!is_string($params["entity_id"]) && !is_int($params["entity_id"])) {
        $errors[] = new ValidationError("entity_id", "Entity ID must be a string or integer");
      }
    }

    // Validate revision_id if provided.
    if (isset($params["revision_id"]) && $params["revision_id"] !== "") {
      if (!is_string($params["revision_id"]) && !is_int($params["revision_id"])) {
        $errors[] = new ValidationError("revision_id", "Revision ID must be a string or integer");
      }
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
    // Check if entity data was pre-injected via initialData (from session context).
    $preInjectedEntity = $params->getArray("entity", []);

    if (!empty($preInjectedEntity)) {
      // Entity was pre-loaded and injected - return it directly with metadata.
      return $this->buildOutputFromInjectedData($params, $preInjectedEntity);
    }

    // Check if we can get entity info from execution context (initialData).
    // This supports child workflows triggered by WorkflowExecutor.
    $entityInfoFromContext = $this->getEntityInfoFromExecutionContext($params);
    if ($entityInfoFromContext !== NULL) {
      return $entityInfoFromContext;
    }

    // Entity not pre-injected - load it from parameters.
    return $this->loadAndSerializeEntity($params);
  }

  /**
   * Attempts to get entity info from execution context's initial data.
   *
   * This supports child workflows triggered by Workflow Executor, where
   * the entity info is passed via message metadata -> initialData.
   *
   * @param \Drupal\flowdrop\DTO\ParameterBagInterface $params
   *   The parameter bag.
   *
   * @return array<string, mixed>|null
   *   The entity output array, or NULL if not available.
   */
  private function getEntityInfoFromExecutionContext(ParameterBagInterface $params): ?array {
    if ($this->executionContext === NULL) {
      return NULL;
    }

    $initialData = $this->executionContext->getInitialData();

    // Check for entity_id and entity_type in initial data.
    // These come from the WorkflowExecutor's input_data.
    $entityId = $initialData["id"] ?? $initialData["entity_id"] ?? "";
    $entityType = $initialData["entity_type"] ?? "node";

    // If no entity_id from context, check if params has it (might be connected).
    if (empty($entityId)) {
      $entityId = $params->getString("entity_id", "");
    }

    if (empty($entityId)) {
      return NULL;
    }

    // If no entity_type from context, check params.
    if (empty($entityType)) {
      $entityType = $params->getString("entity_type", "node");
    }

    // Get optional bundle and revision_id.
    $bundle = $initialData["bundle"] ?? $params->getString("bundle", "");
    $revisionId = $params->getString("revision_id", "");

    // Load the entity.
    $entity = $this->loadEntity($entityType, (string) $entityId, $revisionId);

    if ($entity === NULL) {
      return NULL;
    }

    // Validate bundle if provided.
    if (!empty($bundle) && $entity->bundle() !== $bundle) {
      return NULL;
    }

    // Determine if this is the default revision.
    $isDefaultRevision = TRUE;
    if (method_exists($entity, "isDefaultRevision")) {
      $isDefaultRevision = $entity->isDefaultRevision();
    }

    // Serialize the entity.
    $serializedEntity = $this->entitySerializer->serialize($entity);

    return [
      "entity" => $serializedEntity,
      "entity_type" => $entityType,
      "entity_id" => (string) $entityId,
      "bundle" => $entity->bundle(),
      "revision_id" => !empty($revisionId) ? $revisionId : "",
      "is_default_revision" => $isDefaultRevision,
      "loaded_from" => "execution_context",
    ];
  }

  /**
   * Builds output from pre-injected entity data.
   *
   * @param \Drupal\flowdrop\DTO\ParameterBagInterface $params
   *   The parameter bag.
   * @param array<string, mixed> $entityData
   *   The pre-injected entity data.
   *
   * @return array<string, mixed>
   *   The output array.
   */
  private function buildOutputFromInjectedData(ParameterBagInterface $params, array $entityData): array {
    return [
      "entity" => $entityData,
      "entity_type" => $params->getString("entity_type", $entityData["entity_type"] ?? ""),
      "entity_id" => $params->getString("entity_id", (string) ($entityData["id"] ?? "")),
      "bundle" => $params->getString("bundle", $entityData["bundle"] ?? ""),
      "revision_id" => $params->getString("revision_id", ""),
      "is_default_revision" => $params->get("is_default_revision", TRUE),
      "loaded_from" => "session_context",
    ];
  }

  /**
   * Loads and serializes an entity from parameters.
   *
   * @param \Drupal\flowdrop\DTO\ParameterBagInterface $params
   *   The parameter bag.
   *
   * @return array<string, mixed>
   *   The output array with serialized entity.
   *
   * @throws \Exception
   *   If entity cannot be loaded.
   */
  private function loadAndSerializeEntity(ParameterBagInterface $params): array {
    $entityType = $params->getString("entity_type", "node");
    $entityId = $params->getString("entity_id", "");
    $bundle = $params->getString("bundle", "");
    $revisionId = $params->getString("revision_id", "");

    if (empty($entityType)) {
      throw new \Exception("Entity type is required. Provide entity_type parameter or use Workflow Executor to pass entity info.");
    }

    if (empty($entityId)) {
      throw new \Exception("Entity ID is required. Either connect the entity_id parameter, or when triggered by Workflow Executor, ensure input_data contains 'id' or 'entity_id' field.");
    }

    // Load the entity (with optional revision).
    $entity = $this->loadEntity($entityType, $entityId, $revisionId);

    if ($entity === NULL) {
      $message = !empty($revisionId)
        ? "Entity not found: {$entityType} with ID {$entityId} at revision {$revisionId}"
        : "Entity not found: {$entityType} with ID {$entityId}";
      throw new \Exception($message);
    }

    // Validate bundle if provided.
    if (!empty($bundle) && $entity->bundle() !== $bundle) {
      throw new \Exception("Entity bundle mismatch: expected {$bundle}, got {$entity->bundle()}");
    }

    // Determine if this is the default revision.
    $isDefaultRevision = TRUE;
    if (method_exists($entity, "isDefaultRevision")) {
      $isDefaultRevision = $entity->isDefaultRevision();
    }

    // Serialize the entity.
    $serializedEntity = $this->entitySerializer->serialize($entity);

    return [
      "entity" => $serializedEntity,
      "entity_type" => $entityType,
      "entity_id" => $entityId,
      "bundle" => $entity->bundle(),
      "revision_id" => !empty($revisionId) ? $revisionId : "",
      "is_default_revision" => $isDefaultRevision,
      "loaded_from" => "parameters",
    ];
  }

  /**
   * Loads an entity, optionally at a specific revision.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $entityId
   *   The entity ID.
   * @param string $revisionId
   *   Optional revision ID. If empty, loads the default revision.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The loaded entity or NULL if not found.
   */
  private function loadEntity(string $entityType, string $entityId, string $revisionId): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage($entityType);

    // If revision ID is provided and storage supports revisions, load that revision.
    if (!empty($revisionId) && $storage instanceof RevisionableStorageInterface) {
      $entity = $storage->loadRevision((int) $revisionId);

      // Verify the revision belongs to the correct entity.
      if ($entity !== NULL && (string) $entity->id() !== $entityId) {
        // Revision doesn't belong to the specified entity.
        return NULL;
      }

      return $entity;
    }

    // Load the default/current revision.
    return $storage->load($entityId);
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      "type" => "object",
      "properties" => [
        "entity_type" => [
          "type" => "string",
          "title" => "Entity Type",
          "description" => "The entity type to load (e.g., node, taxonomy_term, user)",
          "default" => "node",
        ],
        "entity_id" => [
          "type" => "string",
          "title" => "Entity ID",
          "description" => "The ID of the entity to load",
          "default" => "",
        ],
        "bundle" => [
          "type" => "string",
          "title" => "Bundle",
          "description" => "The entity bundle/content type (optional, used for validation)",
          "default" => "",
        ],
        "revision_id" => [
          "type" => "string",
          "title" => "Revision ID",
          "description" => "Optional revision ID to load a specific version of the entity. If empty, loads the current/default revision.",
          "default" => "",
        ],
        "entity" => [
          "type" => "object",
          "title" => "Entity Data",
          "description" => "Pre-serialized entity data (injected via session context). If provided, other parameters are used for metadata only.",
          "additionalProperties" => TRUE,
        ],
        "is_default_revision" => [
          "type" => "boolean",
          "title" => "Is Default Revision",
          "description" => "Whether the loaded entity is the default revision (injected via session context)",
          "default" => TRUE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      "type" => "object",
      "properties" => [
        "entity" => [
          "type" => "object",
          "title" => "Entity",
          "description" => "Full serialized entity data including fields",
          "additionalProperties" => TRUE,
          "properties" => [
            "entity_type" => [
              "type" => "string",
              "description" => "The entity type ID",
            ],
            "id" => [
              "type" => "string",
              "description" => "The entity ID",
            ],
            "uuid" => [
              "type" => "string",
              "description" => "The entity UUID",
            ],
            "bundle" => [
              "type" => "string",
              "description" => "The entity bundle",
            ],
            "label" => [
              "type" => "string",
              "description" => "The entity label",
            ],
            "langcode" => [
              "type" => "string",
              "description" => "The entity language code",
            ],
            "fields" => [
              "type" => "object",
              "description" => "All non-computed field values",
              "additionalProperties" => TRUE,
            ],
          ],
        ],
        "entity_type" => [
          "type" => "string",
          "description" => "The entity type ID",
        ],
        "entity_id" => [
          "type" => "string",
          "description" => "The entity ID",
        ],
        "bundle" => [
          "type" => "string",
          "description" => "The entity bundle/content type",
        ],
        "revision_id" => [
          "type" => "string",
          "description" => "The revision ID (if a specific revision was loaded)",
        ],
        "is_default_revision" => [
          "type" => "boolean",
          "description" => "Whether this is the default/current revision",
        ],
        "loaded_from" => [
          "type" => "string",
          "description" => "How the entity was loaded: 'session_context' (pre-injected) or 'parameters' (loaded on demand)",
          "enum" => ["session_context", "parameters"],
        ],
      ],
    ];
  }

}
