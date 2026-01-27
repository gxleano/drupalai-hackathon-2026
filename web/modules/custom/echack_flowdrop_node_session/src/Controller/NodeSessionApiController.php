<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_node_session\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\echack_flowdrop_node_session\Service\NodeSessionService;
use Drupal\flowdrop_playground\Service\PlaygroundService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * API Controller for entity-context FlowDrop playground sessions.
 *
 * Provides RESTful endpoints for creating playground sessions with entity
 * context. This is a separate endpoint from the core FlowDrop playground API.
 */
class NodeSessionApiController extends ControllerBase {

  /**
   * Constructs a NodeSessionApiController object.
   *
   * @param \Drupal\echack_flowdrop_node_session\Service\NodeSessionService $nodeSessionService
   *   The node session service.
   * @param \Drupal\flowdrop_playground\Service\PlaygroundService $playgroundService
   *   The playground service.
   */
  public function __construct(
    protected readonly NodeSessionService $nodeSessionService,
    protected readonly PlaygroundService $playgroundService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get("echack_flowdrop_node_session.service"),
      $container->get("flowdrop_playground.service"),
    );
  }

  /**
   * Creates a new playground session with entity context.
   *
   * @param string $workflow_id
   *   The workflow ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the created session.
   */
  public function createSession(string $workflow_id, Request $request): JsonResponse {
    try {
      // Load and verify workflow exists.
      $workflowStorage = $this->entityTypeManager()->getStorage("flowdrop_workflow");
      $workflow = $workflowStorage->load($workflow_id);

      if ($workflow === NULL) {
        return new JsonResponse([
          "success" => FALSE,
          "error" => "Workflow not found",
          "code" => "NOT_FOUND",
        ], 404);
      }

      // Parse request body.
      $content = $request->getContent();
      $data = [];

      if (!empty($content)) {
        $decoded = json_decode($content, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          $data = $decoded;
        }
        else {
          return new JsonResponse([
            "success" => FALSE,
            "error" => "Invalid JSON in request body",
            "code" => "INVALID_JSON",
          ], 400);
        }
      }

      // Extract and validate required parameters.
      $entityType = isset($data["entity_type"]) && is_string($data["entity_type"])
        ? trim($data["entity_type"])
        : "";
      $entityId = isset($data["entity_id"])
        ? trim((string) $data["entity_id"])
        : "";

      if (empty($entityType)) {
        return new JsonResponse([
          "success" => FALSE,
          "error" => "entity_type is required",
          "code" => "MISSING_ENTITY_TYPE",
        ], 400);
      }

      if (empty($entityId)) {
        return new JsonResponse([
          "success" => FALSE,
          "error" => "entity_id is required",
          "code" => "MISSING_ENTITY_ID",
        ], 400);
      }

      // Extract optional parameters.
      $bundle = isset($data["bundle"]) && is_string($data["bundle"])
        ? trim($data["bundle"])
        : "";
      $revisionId = isset($data["revision_id"])
        ? trim((string) $data["revision_id"])
        : "";
      $sessionName = isset($data["name"]) && is_string($data["name"])
        ? trim($data["name"])
        : NULL;
      $additionalMetadata = isset($data["metadata"]) && is_array($data["metadata"])
        ? $data["metadata"]
        : [];

      // Validate entity type exists.
      try {
        $this->entityTypeManager()->getDefinition($entityType);
      }
      catch (\Exception $e) {
        return new JsonResponse([
          "success" => FALSE,
          "error" => "Invalid entity type: {$entityType}",
          "code" => "INVALID_ENTITY_TYPE",
        ], 400);
      }

      // Check entity access (strict mode - deny if user cannot view entity).
      if (!$this->nodeSessionService->canAccessEntity($entityType, $entityId, $revisionId)) {
        return new JsonResponse([
          "success" => FALSE,
          "error" => "Entity not found or access denied",
          "code" => "ENTITY_ACCESS_DENIED",
        ], 403);
      }

      // Create the session with entity context.
      $session = $this->nodeSessionService->createSessionWithEntityContext(
        $workflow,
        $entityType,
        $entityId,
        $bundle,
        $revisionId,
        $sessionName,
        $additionalMetadata
      );

      // Format session for API response.
      $sessionData = $this->playgroundService->formatSessionForApi($session);

      // Add entity context to response.
      $sessionData["entity_context"] = $this->nodeSessionService->getEntityContext($session);

      return new JsonResponse([
        "success" => TRUE,
        "data" => $sessionData,
        "message" => "Session created successfully with entity context",
      ], 201);
    }
    catch (\InvalidArgumentException $e) {
      $this->getLogger("echack_flowdrop_node_session")->warning("Invalid argument creating session: @message", [
        "@message" => $e->getMessage(),
      ]);

      return new JsonResponse([
        "success" => FALSE,
        "error" => $e->getMessage(),
        "code" => "INVALID_ARGUMENT",
      ], 400);
    }
    catch (\Exception $e) {
      $this->getLogger("echack_flowdrop_node_session")->error("Error creating session: @message", [
        "@message" => $e->getMessage(),
      ]);

      return new JsonResponse([
        "success" => FALSE,
        "error" => "Failed to create session",
        "code" => "INTERNAL_ERROR",
      ], 500);
    }
  }

  /**
   * Gets entity context information for a session.
   *
   * @param string $session_id
   *   The session UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with entity context data.
   */
  public function getEntityContext(string $session_id): JsonResponse {
    try {
      // Load session by UUID.
      $session = $this->loadSessionByUuid($session_id);

      if ($session === NULL) {
        return new JsonResponse([
          "success" => FALSE,
          "error" => "Session not found",
          "code" => "NOT_FOUND",
        ], 404);
      }

      // Get entity context.
      $entityContext = $this->nodeSessionService->getEntityContext($session);

      if ($entityContext === NULL) {
        return new JsonResponse([
          "success" => TRUE,
          "data" => NULL,
          "message" => "Session has no entity context",
        ]);
      }

      return new JsonResponse([
        "success" => TRUE,
        "data" => $entityContext,
        "message" => "Entity context retrieved successfully",
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger("echack_flowdrop_node_session")->error("Error getting entity context: @message", [
        "@message" => $e->getMessage(),
      ]);

      return new JsonResponse([
        "success" => FALSE,
        "error" => "Failed to get entity context",
        "code" => "INTERNAL_ERROR",
      ], 500);
    }
  }

  /**
   * Access check for session operations.
   *
   * @param string $session_id
   *   The session UUID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessSession(string $session_id, AccountInterface $account): AccessResultInterface {
    $session = $this->loadSessionByUuid($session_id);

    if ($session === NULL) {
      return AccessResult::forbidden("Session not found");
    }

    // Check if user can view this session.
    return $session->access("view", $account, TRUE);
  }

  /**
   * Loads a session entity by UUID.
   *
   * @param string $uuid
   *   The session UUID.
   *
   * @return \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface|null
   *   The session entity or NULL if not found.
   */
  protected function loadSessionByUuid(string $uuid): ?object {
    $sessionStorage = $this->entityTypeManager()->getStorage("flowdrop_playground_session");

    $sessions = $sessionStorage->loadByProperties(["uuid" => $uuid]);

    if (empty($sessions)) {
      return NULL;
    }

    return reset($sessions);
  }

}
