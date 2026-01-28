<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_workflow_executor\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\flowdrop_playground\Entity\FlowDropPlaygroundMessageInterface;
use Drupal\flowdrop_playground\Service\PlaygroundService;
use Drupal\flowdrop_workflow\FlowDropWorkflowInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for fire-and-forget workflow execution.
 *
 * Processes queued workflows that were submitted via fire-and-forget mode.
 * This allows the parent workflow to complete without waiting for child
 * workflows to finish.
 *
 * @todo Follow up with FlowDrop maintainers about StatusTracker warnings.
 *   When workflows execute via queue/cron, FlowDrop's StatusTracker logs
 *   "Attempted to update status for unknown node" warnings because the
 *   in-memory tracking doesn't have nodes registered from the original
 *   request context. These warnings are harmless but noisy. Consider
 *   proposing a fix upstream to either:
 *   - Auto-register nodes on first status update
 *   - Add a configuration option to disable status tracking for non-UI execution
 *   - Reduce log level from warning to debug for this specific case
 *   See: flowdrop_runtime/src/Service/RealTime/StatusTracker.php:115
 *
 * @QueueWorker(
 *   id = "workflow_executor_queue",
 *   title = @Translation("Workflow Executor Queue"),
 *   cron = {"time" = 120}
 * )
 */
class WorkflowExecutionQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a WorkflowExecutionQueueWorker object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\flowdrop_playground\Service\PlaygroundService $playgroundService
   *   The playground service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PlaygroundService $playgroundService,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get("workflow_executor");
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
      $container->get("flowdrop_playground.service"),
      $container->get("logger.factory")
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array{workflow_id: string, input_data: array, parent_context: array} $data
   *   The queue item data.
   */
  public function processItem($data): void {
    $workflowId = $data["workflow_id"] ?? "";
    $inputData = $data["input_data"] ?? [];
    $parentContext = $data["parent_context"] ?? [];

    if (empty($workflowId)) {
      $this->logger->error("Queue item missing workflow_id.");
      return;
    }

    // Load the workflow.
    $workflow = $this->entityTypeManager
      ->getStorage("flowdrop_workflow")
      ->load($workflowId);

    if (!$workflow instanceof FlowDropWorkflowInterface) {
      $this->logger->error("Workflow not found: @id", ["@id" => $workflowId]);
      return;
    }

    try {
      // Build metadata for the session.
      $workflowChain = $parentContext["workflow_chain"] ?? [];
      $currentDepth = $parentContext["execution_depth"] ?? 1;

      $metadata = [
        "workflow_executor_input" => $inputData,
        "workflow_executor_parent_context" => [
          "execution_depth" => $currentDepth,
          "workflow_chain" => $workflowChain,
          "queued_execution" => TRUE,
        ],
      ];

      // Create the playground session.
      $sessionName = "WorkflowExecutor (queued): {$workflow->label()}";
      $session = $this->playgroundService->createSession($workflow, $sessionName, $metadata);

      // Re-set metadata to ensure proper JSON encoding.
      $session->setMetadata($metadata);
      $session->save();

      // Create triggering message.
      $messageStorage = $this->entityTypeManager->getStorage("flowdrop_playground_message");
      $message = $messageStorage->create([
        "session_id" => ["target_id" => $session->id()],
        "role" => "user",
        "content" => "Execute workflow (queued)",
      ]);

      // Set metadata properly using the entity method (JSON encodes it).
      if (method_exists($message, "setMessageMetadata")) {
        $message->setMessageMetadata([
          "inputs" => $inputData,
          "triggered_by" => "workflow_executor_queue",
        ]);
      }

      $message->save();

      // IMPORTANT: Call processMessage to actually execute the workflow.
      // This goes through the orchestrator and creates a pipeline entity
      // that shows up in /admin/flowdrop/pipelines.
      if ($message instanceof FlowDropPlaygroundMessageInterface) {
        $this->playgroundService->processMessage($message);
      }

      $this->logger->info("Executed queued workflow '@workflow' (session: @session)", [
        "@workflow" => $workflow->label(),
        "@session" => $session->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error("Failed to execute queued workflow '@workflow': @message", [
        "@workflow" => $workflowId,
        "@message" => $e->getMessage(),
      ]);
      // Re-throw to mark item as failed and retry.
      throw $e;
    }
  }

}
