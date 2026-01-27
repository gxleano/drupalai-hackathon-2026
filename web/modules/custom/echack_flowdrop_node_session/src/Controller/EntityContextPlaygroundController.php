<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_node_session\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\echack_flowdrop_node_session\Service\NodeSessionService;
use Drupal\flowdrop_playground\Service\PlaygroundService;
use Drupal\flowdrop_workflow\Entity\FlowDropWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for entity-context FlowDrop Playground UI pages.
 *
 * This controller provides a playground page that automatically initializes
 * a session with entity context from URL parameters. This allows users to
 * launch a playground pre-configured with a specific entity as context.
 *
 * URL format:
 * /admin/flowdrop/workflows/{workflow_id}/playground/entity?entity_type=node&entity_id=1&revision_id=5
 */
class EntityContextPlaygroundController extends ControllerBase {

  /**
   * The FlowDrop endpoint config service.
   *
   * @var \Drupal\flowdrop_ui_components\Service\FlowDropEndpointConfigService
   */
  protected $endpointConfigService;

  /**
   * The node session service.
   *
   * @var \Drupal\echack_flowdrop_node_session\Service\NodeSessionService
   */
  protected NodeSessionService $nodeSessionService;

  /**
   * The playground service.
   *
   * @var \Drupal\flowdrop_playground\Service\PlaygroundService
   */
  protected PlaygroundService $playgroundService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->endpointConfigService = $container->get("flowdrop_ui_components.endpoint_config");
    $instance->nodeSessionService = $container->get("echack_flowdrop_node_session.service");
    $instance->playgroundService = $container->get("flowdrop_playground.service");
    return $instance;
  }

  /**
   * Renders the entity-context playground page for a workflow.
   *
   * This page automatically creates a session with entity context from URL
   * query parameters and launches the playground with that session active.
   *
   * Query parameters:
   * - entity_type (required): The entity type (e.g., "node", "taxonomy_term")
   * - entity_id (required): The entity ID
   * - bundle (optional): The entity bundle for validation
   * - revision_id (optional): Specific revision ID to load
   * - session_name (optional): Custom name for the session
   *
   * @param string $workflow_id
   *   The workflow ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array<string, mixed>
   *   Render array for the playground page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If required parameters are missing or entity is not found.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the user cannot access the entity.
   */
  public function playgroundPage(string $workflow_id, Request $request): array {
    // Load the workflow entity.
    $flowdrop_workflow = $this->entityTypeManager()->getStorage("flowdrop_workflow")->load($workflow_id);
    if (!$flowdrop_workflow instanceof FlowDropWorkflow) {
      throw new NotFoundHttpException("Workflow not found: {$workflow_id}");
    }
    // Extract entity context parameters from query string.
    $entityType = $request->query->get("entity_type", "");
    $entityId = $request->query->get("entity_id", "");
    $bundle = $request->query->get("bundle", "");
    $revisionId = $request->query->get("revision_id", "");
    $sessionName = $request->query->get("session_name");

    // Validate required parameters.
    if (empty($entityType)) {
      throw new NotFoundHttpException("Missing required parameter: entity_type");
    }

    if (empty($entityId)) {
      throw new NotFoundHttpException("Missing required parameter: entity_id");
    }

    // Validate entity type exists.
    try {
      $this->entityTypeManager()->getDefinition($entityType);
    }
    catch (\Exception $e) {
      throw new NotFoundHttpException("Invalid entity type: {$entityType}");
    }

    // Check entity access (strict mode).
    if (!$this->nodeSessionService->canAccessEntity($entityType, $entityId, $revisionId)) {
      throw new AccessDeniedHttpException("Entity not found or access denied");
    }

    // Load the entity to get its label for the session name.
    $entity = $this->nodeSessionService->loadEntityWithRevision($entityType, $entityId, $revisionId);
    if ($entity === NULL) {
      throw new NotFoundHttpException("Entity not found: {$entityType} with ID {$entityId}");
    }

    // Generate session name if not provided.
    if (empty($sessionName)) {
      $entityLabel = $entity->label() ?? "Entity {$entityId}";
      $sessionName = "Context: {$entityLabel}";
      if (!empty($revisionId)) {
        $sessionName .= " (rev {$revisionId})";
      }
    }

    // Create the session with entity context.
    try {
      $session = $this->nodeSessionService->createSessionWithEntityContext(
        $flowdrop_workflow,
        $entityType,
        $entityId,
        $bundle,
        $revisionId,
        $sessionName
      );
    }
    catch (\Exception $e) {
      throw new NotFoundHttpException("Failed to create session: " . $e->getMessage());
    }

    // Get entity context for passing to frontend.
    $entityContext = $this->nodeSessionService->getEntityContext($session);

    // Prepare workflow data for the frontend.
    $workflow_data = [
      "id" => $flowdrop_workflow->id(),
      "label" => $flowdrop_workflow->label(),
      "description" => $flowdrop_workflow->getDescription(),
      "nodes" => $flowdrop_workflow->getNodes(),
      "edges" => $flowdrop_workflow->getEdges(),
      "metadata" => $flowdrop_workflow->getMetadata(),
      "created" => $flowdrop_workflow->getCreated(),
      "changed" => $flowdrop_workflow->getChanged(),
    ];

    // Build the API base URL.
    $url_options = [
      "absolute" => TRUE,
      "language" => $this->languageManager()->getCurrentLanguage(),
    ];
    $base_url = Url::fromRoute("<front>", [], $url_options)->toString() . "/api/flowdrop";

    // Generate endpoint configuration.
    $endpoint_config = $this->endpointConfigService->generateEndpointConfig($base_url);

    // Build back URL - navigates to workflow listing when back button clicked.
    $back_url = Url::fromRoute("flowdrop.workflows")->toString();

    // Build editor URL - navigates to workflow editor when edit button clicked.
    $editor_url = Url::fromRoute("flowdrop.workflow.editor", [
      "flowdrop_workflow" => $flowdrop_workflow->id(),
    ])->toString();

    // Format session data for the frontend.
    $sessionData = $this->playgroundService->formatSessionForApi($session);
    $sessionData["entity_context"] = $entityContext;

    // Render using the SDC playground component in standalone mode.
    // Pass the pre-created session so the playground opens with it active.
    $build["content"] = [
      "#type" => "component",
      "#component" => "flowdrop_ui_components:playground",
      "#props" => [
        "workflow" => $workflow_data,
        "endpoint_config" => $endpoint_config,
        "container_id" => "flowdrop-playground-entity-" . $flowdrop_workflow->id(),
        "mode" => "standalone",
        "height" => "100vh",
        "width" => "100vw",
        "back_url" => $back_url,
        "editor_url" => $editor_url,
        "workflow_name" => $flowdrop_workflow->label(),
        // Pass the pre-created session to auto-select it.
        "initial_session" => $sessionData,
      ],
      "#attached" => [
        "library" => [
          "flowdrop_playground/playground",
        ],
        // Pass entity context info to JavaScript.
        "drupalSettings" => [
          "echack_flowdrop_node_session" => [
            "session_id" => $session->uuid(),
            "entity_context" => $entityContext,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Title callback for the entity-context playground page.
   *
   * @param string $workflow_id
   *   The workflow ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   The page title.
   */
  public function playgroundTitle(string $workflow_id, Request $request): string {
    // Load the workflow entity.
    $flowdrop_workflow = $this->entityTypeManager()->getStorage("flowdrop_workflow")->load($workflow_id);
    $workflowLabel = $flowdrop_workflow instanceof FlowDropWorkflow
      ? $flowdrop_workflow->label()
      : $workflow_id;

    $entityType = $request->query->get("entity_type", "");
    $entityId = $request->query->get("entity_id", "");

    if (!empty($entityType) && !empty($entityId)) {
      // Try to get entity label for title.
      $entity = $this->nodeSessionService->loadEntityWithRevision($entityType, $entityId);
      if ($entity !== NULL) {
        $entityLabel = $entity->label() ?? "{$entityType} {$entityId}";
        return $this->t("Playground - @workflow (@entity)", [
          "@workflow" => $workflowLabel,
          "@entity" => $entityLabel,
        ])->__toString();
      }
    }

    return $this->t("Playground - @name (Entity Context)", [
      "@name" => $workflowLabel,
    ])->__toString();
  }

}
