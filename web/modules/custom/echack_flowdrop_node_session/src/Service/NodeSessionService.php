<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_node_session\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flowdrop\Service\EntitySerializer;
use Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface;
use Drupal\flowdrop_playground\Service\PlaygroundService;
use Drupal\flowdrop_workflow\FlowDropWorkflowInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing entity-context playground sessions.
 *
 * This service provides functionality to create FlowDrop playground sessions
 * with entity context, allowing workflows to be initialized with a Drupal
 * entity (node, taxonomy term, etc.) as context data.
 *
 * The entity context is stored in the session's metadata field and is used
 * to inject entity data into EntityContext nodes when the workflow executes.
 */
class NodeSessionService {

  /**
   * The metadata key used to store entity context in session metadata.
   */
  public const ENTITY_CONTEXT_KEY = "entity_context";

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a NodeSessionService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\flowdrop_playground\Service\PlaygroundService $playgroundService
   *   The playground service.
   * @param \Drupal\flowdrop\Service\EntitySerializer $entitySerializer
   *   The entity serializer service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PlaygroundService $playgroundService,
    protected readonly EntitySerializer $entitySerializer,
    protected readonly AccountInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get("echack_flowdrop_node_session");
  }

  /**
   * Creates a playground session with entity context.
   *
   * @param \Drupal\flowdrop_workflow\FlowDropWorkflowInterface $workflow
   *   The workflow entity.
   * @param string $entityType
   *   The entity type ID (e.g., "node", "taxonomy_term").
   * @param string $entityId
   *   The entity ID.
   * @param string $bundle
   *   Optional bundle/content type for validation.
   * @param string $revisionId
   *   Optional revision ID to load a specific version.
   * @param string|null $sessionName
   *   Optional session name.
   * @param array<string, mixed> $additionalMetadata
   *   Optional additional metadata to include in the session.
   *
   * @return \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface
   *   The created session entity.
   *
   * @throws \InvalidArgumentException
   *   If the entity type is invalid or entity is not found.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the session cannot be saved.
   */
  public function createSessionWithEntityContext(
    FlowDropWorkflowInterface $workflow,
    string $entityType,
    string $entityId,
    string $bundle = "",
    string $revisionId = "",
    ?string $sessionName = NULL,
    array $additionalMetadata = [],
  ): FlowDropPlaygroundSessionInterface {
    // Validate entity type exists.
    $this->validateEntityType($entityType);

    // Load and validate the entity.
    $entity = $this->loadEntityWithRevision($entityType, $entityId, $revisionId);

    if ($entity === NULL) {
      $message = !empty($revisionId)
        ? "Entity not found: {$entityType} with ID {$entityId} at revision {$revisionId}"
        : "Entity not found: {$entityType} with ID {$entityId}";
      throw new \InvalidArgumentException($message);
    }

    // Validate bundle if provided.
    if (!empty($bundle) && $entity->bundle() !== $bundle) {
      throw new \InvalidArgumentException(
        "Entity bundle mismatch: expected {$bundle}, got {$entity->bundle()}"
      );
    }

    // Determine if this is the default revision.
    $isDefaultRevision = TRUE;
    if (method_exists($entity, "isDefaultRevision")) {
      $isDefaultRevision = $entity->isDefaultRevision();
    }

    // Build entity context metadata.
    $entityContext = [
      "entity_type" => $entityType,
      "entity_id" => $entityId,
      "bundle" => $entity->bundle(),
      "revision_id" => $revisionId,
      "is_default_revision" => $isDefaultRevision,
      "label" => $entity->label() ?? "",
      "uuid" => $entity->uuid(),
    ];

    // Merge entity context with additional metadata.
    $metadata = array_merge($additionalMetadata, [
      self::ENTITY_CONTEXT_KEY => $entityContext,
    ]);

    // Create the session using the playground service.
    $session = $this->playgroundService->createSession($workflow, $sessionName, $metadata);

    $this->logger->info("Created entity-context session @session_id for workflow @workflow with @entity_type:@entity_id", [
      "@session_id" => $session->id(),
      "@workflow" => $workflow->id(),
      "@entity_type" => $entityType,
      "@entity_id" => $entityId,
    ]);

    return $session;
  }

  /**
   * Loads an entity with optional specific revision.
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
  public function loadEntityWithRevision(
    string $entityType,
    string $entityId,
    string $revisionId = "",
  ): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage($entityType);

    // If revision ID is provided and storage supports revisions, load that revision.
    if (!empty($revisionId) && $storage instanceof RevisionableStorageInterface) {
      $entity = $storage->loadRevision((int) $revisionId);

      // Verify the revision belongs to the correct entity.
      if ($entity !== NULL && (string) $entity->id() !== $entityId) {
        $this->logger->warning("Revision @revision_id does not belong to entity @entity_type:@entity_id", [
          "@revision_id" => $revisionId,
          "@entity_type" => $entityType,
          "@entity_id" => $entityId,
        ]);
        return NULL;
      }

      return $entity;
    }

    // Load the default/current revision.
    return $storage->load($entityId);
  }

  /**
   * Checks if a user can access an entity for session context.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $entityId
   *   The entity ID.
   * @param string $revisionId
   *   Optional revision ID.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user account to check. Defaults to current user.
   *
   * @return bool
   *   TRUE if the user can view the entity, FALSE otherwise.
   */
  public function canAccessEntity(
    string $entityType,
    string $entityId,
    string $revisionId = "",
    ?AccountInterface $account = NULL,
  ): bool {
    $account = $account ?? $this->currentUser;

    try {
      $entity = $this->loadEntityWithRevision($entityType, $entityId, $revisionId);

      if ($entity === NULL) {
        return FALSE;
      }

      // Check view access on the entity.
      $accessResult = $entity->access("view", $account, TRUE);

      return $accessResult->isAllowed();
    }
    catch (\Exception $e) {
      $this->logger->warning("Error checking entity access: @message", [
        "@message" => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Builds initial data for EntityContext nodes from session metadata.
   *
   * This method is called during workflow execution to inject entity context
   * into EntityContext nodes via initialData.
   *
   * @param \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface $session
   *   The session entity.
   * @param array<string, mixed> $workflowData
   *   The workflow data containing nodes.
   *
   * @return array<string, mixed>
   *   The initial data array keyed by node ID.
   */
  public function buildEntityContextInitialData(
    FlowDropPlaygroundSessionInterface $session,
    array $workflowData,
  ): array {
    $initialData = [];

    // Get entity context from session metadata.
    $metadata = $session->getMetadata();
    $entityContext = $metadata[self::ENTITY_CONTEXT_KEY] ?? NULL;

    if ($entityContext === NULL) {
      // No entity context in this session.
      return $initialData;
    }

    // Find EntityContext nodes in the workflow.
    $entityContextNodes = $this->findEntityContextNodes($workflowData);

    if (empty($entityContextNodes)) {
      // No EntityContext nodes in the workflow.
      return $initialData;
    }

    // Load and serialize the entity.
    $entity = $this->loadEntityWithRevision(
      $entityContext["entity_type"],
      $entityContext["entity_id"],
      $entityContext["revision_id"] ?? ""
    );

    if ($entity === NULL) {
      $this->logger->warning("Entity context entity not found for session @session_id", [
        "@session_id" => $session->id(),
      ]);
      return $initialData;
    }

    // Serialize the entity.
    $serializedEntity = $this->entitySerializer->serialize($entity);

    // Inject the entity data into each EntityContext node.
    foreach ($entityContextNodes as $node) {
      $nodeId = $node["id"] ?? NULL;
      if ($nodeId === NULL) {
        continue;
      }

      $initialData[$nodeId] = [
        "entity" => $serializedEntity,
        "entity_type" => $entityContext["entity_type"],
        "entity_id" => $entityContext["entity_id"],
        "bundle" => $entityContext["bundle"] ?? $entity->bundle(),
        "revision_id" => $entityContext["revision_id"] ?? "",
        "is_default_revision" => $entityContext["is_default_revision"] ?? TRUE,
      ];
    }

    return $initialData;
  }

  /**
   * Finds EntityContext nodes in workflow data.
   *
   * Similar to PlaygroundService::findChatInputNodeWithParameter(), this method
   * searches for nodes that match the EntityContext plugin type.
   *
   * @param array<string, mixed> $workflowData
   *   The workflow data containing nodes.
   *
   * @return array<int, array<string, mixed>>
   *   Array of EntityContext node definitions.
   */
  public function findEntityContextNodes(array $workflowData): array {
    $nodes = $workflowData["nodes"] ?? [];
    $entityContextNodes = [];

    // Patterns to match EntityContext nodes.
    // The plugin ID may be namespaced with the provider module.
    $patterns = [
      "entity_context",
      "entitycontext",
      "echack_flowdrop_node_session:entity_context",
    ];

    foreach ($nodes as $node) {
      $nodeType = $node["type"] ?? "";
      $nodeTypeLower = strtolower($nodeType);

      // Check if node type matches any of our patterns.
      foreach ($patterns as $pattern) {
        if ($nodeTypeLower === $pattern || str_ends_with($nodeTypeLower, ":" . $pattern)) {
          $entityContextNodes[] = $node;
          break;
        }
      }
    }

    return $entityContextNodes;
  }

  /**
   * Gets the entity context from a session.
   *
   * @param \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface $session
   *   The session entity.
   *
   * @return array<string, mixed>|null
   *   The entity context array or NULL if not set.
   */
  public function getEntityContext(FlowDropPlaygroundSessionInterface $session): ?array {
    $metadata = $session->getMetadata();
    $context = $metadata[self::ENTITY_CONTEXT_KEY] ?? NULL;

    return is_array($context) ? $context : NULL;
  }

  /**
   * Checks if a session has entity context.
   *
   * @param \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface $session
   *   The session entity.
   *
   * @return bool
   *   TRUE if the session has entity context, FALSE otherwise.
   */
  public function hasEntityContext(FlowDropPlaygroundSessionInterface $session): bool {
    return $this->getEntityContext($session) !== NULL;
  }

  /**
   * Validates that an entity type exists.
   *
   * @param string $entityType
   *   The entity type ID.
   *
   * @throws \InvalidArgumentException
   *   If the entity type does not exist.
   */
  protected function validateEntityType(string $entityType): void {
    try {
      $this->entityTypeManager->getDefinition($entityType);
    }
    catch (\Exception $e) {
      throw new \InvalidArgumentException("Invalid entity type: {$entityType}");
    }
  }

}
