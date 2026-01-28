<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_workflow_executor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult;
use Drupal\flowdrop\Service\EntitySerializer;
use Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface;
use Drupal\flowdrop_playground\Service\PlaygroundService;
use Drupal\flowdrop_workflow\FlowDropWorkflowInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for executing FlowDrop workflows programmatically.
 *
 * This service provides methods to execute workflows with different modes:
 * - job: Create session, trigger execution, wait for completion
 * - job_async: Create session, trigger execution, return immediately
 * - job_fire_forget: Queue for later execution (truly async, no blocking)
 */
class WorkflowExecutionService implements WorkflowExecutionServiceInterface {

  /**
   * Maximum execution depth to prevent infinite recursion.
   */
  private const MAX_EXECUTION_DEPTH = 5;

  /**
   * Metadata key for workflow executor input data.
   */
  private const INPUT_METADATA_KEY = "workflow_executor_input";

  /**
   * Metadata key for parent context.
   */
  private const PARENT_CONTEXT_KEY = "workflow_executor_parent_context";

  /**
   * Queue name for fire-and-forget workflow execution.
   */
  public const QUEUE_NAME = "workflow_executor_queue";

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The queue for fire-and-forget execution.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $queue;

  /**
   * Constructs a WorkflowExecutionService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\flowdrop_playground\Service\PlaygroundService $playgroundService
   *   The playground service.
   * @param \Drupal\flowdrop\Service\EntitySerializer $entitySerializer
   *   The entity serializer.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PlaygroundService $playgroundService,
    protected readonly EntitySerializer $entitySerializer,
    protected readonly StateInterface $state,
    QueueFactory $queueFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->queue = $queueFactory->get(self::QUEUE_NAME);
    $this->logger = $loggerFactory->get("workflow_executor");
  }

  /**
   * {@inheritdoc}
   */
  public function execute(
    FlowDropWorkflowInterface $workflow,
    array $inputData = [],
    string $mode = self::MODE_JOB,
    int $timeout = 300,
    int $pollIntervalMs = 2000,
    array $parentContext = [],
  ): WorkflowExecutionResult {
    $startTime = microtime(TRUE);
    $workflowId = $workflow->id();

    try {
      // Check execution depth to prevent infinite recursion.
      $currentDepth = ($parentContext["execution_depth"] ?? 0) + 1;
      if ($currentDepth > self::MAX_EXECUTION_DEPTH) {
        return WorkflowExecutionResult::failure(
          "Maximum execution depth (" . self::MAX_EXECUTION_DEPTH . ") exceeded. Possible infinite recursion detected."
        );
      }

      // Check for self-reference.
      $workflowChain = $parentContext["workflow_chain"] ?? [];
      if (in_array($workflowId, $workflowChain, TRUE)) {
        return WorkflowExecutionResult::failure(
          "Circular workflow reference detected: workflow '{$workflowId}' is already in the execution chain."
        );
      }

      // For fire-and-forget mode, queue the execution and return immediately.
      // This avoids blocking because Flowdrop uses synchronous_pipeline.
      if ($mode === self::MODE_JOB_FIRE_FORGET) {
        return $this->queueForExecution($workflowId, $inputData, $parentContext, $currentDepth);
      }

      // For job and job_async modes, execute immediately.
      return $this->executeImmediate($workflow, $inputData, $mode, $timeout, $pollIntervalMs, $parentContext, $currentDepth, $startTime);
    }
    catch (\Exception $e) {
      $this->logger->error("Workflow execution failed: @message", [
        "@message" => $e->getMessage(),
      ]);
      return WorkflowExecutionResult::failure($e->getMessage());
    }
  }

  /**
   * Queues a workflow for later execution (fire-and-forget).
   *
   * @param string $workflowId
   *   The workflow ID.
   * @param array<string, mixed> $inputData
   *   Input data for the workflow.
   * @param array<string, mixed> $parentContext
   *   Parent context.
   * @param int $currentDepth
   *   Current execution depth.
   *
   * @return \Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult
   *   The execution result (pending status).
   */
  protected function queueForExecution(
    string $workflowId,
    array $inputData,
    array $parentContext,
    int $currentDepth,
  ): WorkflowExecutionResult {
    $queueItem = [
      "workflow_id" => $workflowId,
      "input_data" => $inputData,
      "parent_context" => [
        "execution_depth" => $currentDepth,
        "workflow_chain" => array_merge($parentContext["workflow_chain"] ?? [], [$workflowId]),
      ],
    ];

    $this->queue->createItem($queueItem);

    $this->logger->info("Queued workflow '@workflow' for fire-and-forget execution.", [
      "@workflow" => $workflowId,
    ]);

    // Return pending with a generated reference ID (no session yet).
    $queueRef = "queued_" . uniqid();
    return WorkflowExecutionResult::pending($queueRef);
  }

  /**
   * Executes a workflow immediately (job or job_async mode).
   *
   * @param \Drupal\flowdrop_workflow\FlowDropWorkflowInterface $workflow
   *   The workflow.
   * @param array<string, mixed> $inputData
   *   Input data.
   * @param string $mode
   *   Execution mode.
   * @param int $timeout
   *   Timeout in seconds.
   * @param int $pollIntervalMs
   *   Poll interval in milliseconds.
   * @param array<string, mixed> $parentContext
   *   Parent context.
   * @param int $currentDepth
   *   Current execution depth.
   * @param float $startTime
   *   Start time for timing.
   *
   * @return \Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult
   *   The execution result.
   */
  protected function executeImmediate(
    FlowDropWorkflowInterface $workflow,
    array $inputData,
    string $mode,
    int $timeout,
    int $pollIntervalMs,
    array $parentContext,
    int $currentDepth,
    float $startTime,
  ): WorkflowExecutionResult {
    $workflowId = $workflow->id();

    // Build metadata for the session.
    $metadata = $this->buildSessionMetadata($inputData, $parentContext, $workflowId, $currentDepth);

    // Create the playground session.
    $sessionName = "WorkflowExecutor: {$workflow->label()}";
    $session = $this->createSession($workflow, $sessionName, $metadata);

    if ($session === NULL) {
      return WorkflowExecutionResult::failure("Failed to create playground session");
    }

    $sessionId = (string) $session->id();

    // Create triggering message to start workflow execution.
    $this->createTriggerMessage($session, $inputData);

    $this->logger->info("Triggered workflow '@workflow' in mode '@mode' (session: @session)", [
      "@workflow" => $workflow->label(),
      "@mode" => $mode,
      "@session" => $sessionId,
    ]);

    // Handle execution modes.
    if ($mode === self::MODE_JOB_ASYNC) {
      // Return immediately with session ID for later retrieval.
      return WorkflowExecutionResult::pending($sessionId);
    }

    // Default: job mode - wait for completion.
    return $this->waitForCompletion($sessionId, $timeout, $pollIntervalMs, $startTime);
  }

  /**
   * {@inheritdoc}
   */
  public function getResults(
    string $sessionId,
    bool $waitForCompletion = TRUE,
    int $timeout = 300,
    int $pollIntervalMs = 2000,
  ): WorkflowExecutionResult {
    $startTime = microtime(TRUE);

    try {
      if ($waitForCompletion) {
        return $this->waitForCompletion($sessionId, $timeout, $pollIntervalMs, $startTime);
      }

      // Just check current status without waiting.
      $session = $this->loadSession($sessionId);
      if ($session === NULL) {
        return WorkflowExecutionResult::failure("Session not found: {$sessionId}");
      }

      $status = $this->getSessionStatus($session);

      if ($status === WorkflowExecutionResult::STATUS_COMPLETED) {
        $outputs = $this->extractOutputs($session);
        $executionTimeMs = (int) ((microtime(TRUE) - $startTime) * 1000);
        return WorkflowExecutionResult::success($sessionId, $outputs, $executionTimeMs);
      }

      if ($status === WorkflowExecutionResult::STATUS_FAILED) {
        return WorkflowExecutionResult::failure("Workflow execution failed", $sessionId);
      }

      return WorkflowExecutionResult::running($sessionId);
    }
    catch (\Exception $e) {
      return WorkflowExecutionResult::failure($e->getMessage(), $sessionId);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isComplete(string $sessionId): bool {
    $session = $this->loadSession($sessionId);
    if ($session === NULL) {
      return FALSE;
    }

    $status = $this->getSessionStatus($session);
    return $status === WorkflowExecutionResult::STATUS_COMPLETED
      || $status === WorkflowExecutionResult::STATUS_FAILED;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(string $sessionId): string {
    $session = $this->loadSession($sessionId);
    if ($session === NULL) {
      return WorkflowExecutionResult::STATUS_FAILED;
    }

    return $this->getSessionStatus($session);
  }

  /**
   * Builds metadata for the session.
   *
   * @param array<string, mixed> $inputData
   *   Input data for the workflow.
   * @param array<string, mixed> $parentContext
   *   Parent context for recursion tracking.
   * @param string $workflowId
   *   The workflow ID.
   * @param int $currentDepth
   *   Current execution depth.
   *
   * @return array<string, mixed>
   *   The metadata array.
   */
  protected function buildSessionMetadata(
    array $inputData,
    array $parentContext,
    string $workflowId,
    int $currentDepth,
  ): array {
    // Build workflow chain for circular reference detection.
    $workflowChain = $parentContext["workflow_chain"] ?? [];
    $workflowChain[] = $workflowId;

    return [
      self::INPUT_METADATA_KEY => $inputData,
      self::PARENT_CONTEXT_KEY => [
        "execution_depth" => $currentDepth,
        "workflow_chain" => $workflowChain,
        "parent_session_id" => $parentContext["session_id"] ?? NULL,
      ],
    ];
  }

  /**
   * Creates a playground session for workflow execution.
   *
   * @param \Drupal\flowdrop_workflow\FlowDropWorkflowInterface $workflow
   *   The workflow.
   * @param string $sessionName
   *   The session name.
   * @param array<string, mixed> $metadata
   *   Session metadata.
   *
   * @return \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface|null
   *   The created session or NULL on failure.
   */
  protected function createSession(
    FlowDropWorkflowInterface $workflow,
    string $sessionName,
    array $metadata,
  ): ?FlowDropPlaygroundSessionInterface {
    try {
      $session = $this->playgroundService->createSession($workflow, $sessionName, $metadata);

      // Workaround: Re-set metadata to ensure proper JSON encoding.
      // (Same pattern as NodeSessionService).
      $session->setMetadata($metadata);
      $session->save();

      return $session;
    }
    catch (\Exception $e) {
      $this->logger->error("Failed to create session: @message", [
        "@message" => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Creates a trigger message to start workflow execution.
   *
   * @param \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface $session
   *   The session.
   * @param array<string, mixed> $inputData
   *   Input data for the workflow.
   */
  protected function createTriggerMessage(
    FlowDropPlaygroundSessionInterface $session,
    array $inputData,
  ): void {
    try {
      // Create a user message to trigger workflow execution.
      // The message content can include the input data context.
      $messageContent = !empty($inputData)
        ? "Execute workflow with input data"
        : "Execute workflow";

      $messageStorage = $this->entityTypeManager->getStorage("flowdrop_playground_message");
      $message = $messageStorage->create([
        "session_id" => ["target_id" => $session->id()],
        "role" => "user",
        "content" => $messageContent,
      ]);

      // Set metadata properly using the entity method (JSON encodes it).
      if (method_exists($message, "setMessageMetadata")) {
        $message->setMessageMetadata([
          "inputs" => $inputData,
          "triggered_by" => "workflow_executor",
        ]);
      }

      $message->save();
    }
    catch (\Exception $e) {
      $this->logger->error("Failed to create trigger message: @message", [
        "@message" => $e->getMessage(),
      ]);
    }
  }

  /**
   * Waits for workflow completion with polling.
   *
   * @param string $sessionId
   *   The session ID.
   * @param int $timeout
   *   Timeout in seconds.
   * @param int $pollIntervalMs
   *   Polling interval in milliseconds.
   * @param float $startTime
   *   Start time for timing calculation.
   *
   * @return \Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult
   *   The execution result.
   */
  protected function waitForCompletion(
    string $sessionId,
    int $timeout,
    int $pollIntervalMs,
    float $startTime,
  ): WorkflowExecutionResult {
    $timeoutMs = $timeout * 1000;
    $elapsed = 0;

    while ($elapsed < $timeoutMs) {
      $session = $this->loadSession($sessionId);
      if ($session === NULL) {
        return WorkflowExecutionResult::failure("Session not found: {$sessionId}");
      }

      $status = $this->getSessionStatus($session);

      if ($status === WorkflowExecutionResult::STATUS_COMPLETED) {
        $outputs = $this->extractOutputs($session);
        $executionTimeMs = (int) ((microtime(TRUE) - $startTime) * 1000);
        return WorkflowExecutionResult::success($sessionId, $outputs, $executionTimeMs);
      }

      if ($status === WorkflowExecutionResult::STATUS_FAILED) {
        $executionTimeMs = (int) ((microtime(TRUE) - $startTime) * 1000);
        return WorkflowExecutionResult::failure("Workflow execution failed", $sessionId);
      }

      // Sleep and continue polling.
      usleep($pollIntervalMs * 1000);
      $elapsed = (int) ((microtime(TRUE) - $startTime) * 1000);
    }

    // Timeout reached.
    $executionTimeMs = (int) ((microtime(TRUE) - $startTime) * 1000);
    return WorkflowExecutionResult::timeout($sessionId, $executionTimeMs);
  }

  /**
   * Loads a session by ID.
   *
   * @param string $sessionId
   *   The session ID.
   *
   * @return \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface|null
   *   The session or NULL if not found.
   */
  protected function loadSession(string $sessionId): ?FlowDropPlaygroundSessionInterface {
    try {
      $session = $this->entityTypeManager
        ->getStorage("flowdrop_playground_session")
        ->load($sessionId);

      if ($session instanceof FlowDropPlaygroundSessionInterface) {
        return $session;
      }
    }
    catch (\Exception $e) {
      $this->logger->error("Failed to load session @id: @message", [
        "@id" => $sessionId,
        "@message" => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Gets the status of a session.
   *
   * @param \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface $session
   *   The session.
   *
   * @return string
   *   The status string.
   */
  protected function getSessionStatus(FlowDropPlaygroundSessionInterface $session): string {
    // Check if session has a status method.
    if (method_exists($session, "getStatus")) {
      $status = $session->getStatus();
      // Map session status to our status constants.
      return match ($status) {
        "completed", "finished" => WorkflowExecutionResult::STATUS_COMPLETED,
        "failed", "error" => WorkflowExecutionResult::STATUS_FAILED,
        "running", "processing" => WorkflowExecutionResult::STATUS_RUNNING,
        default => WorkflowExecutionResult::STATUS_PENDING,
      };
    }

    // Fallback: Check messages to determine status.
    // If there's an assistant message, workflow likely completed.
    try {
      $messages = $this->entityTypeManager
        ->getStorage("flowdrop_playground_message")
        ->loadByProperties([
          "session_id" => $session->id(),
          "role" => "assistant",
        ]);

      if (!empty($messages)) {
        return WorkflowExecutionResult::STATUS_COMPLETED;
      }
    }
    catch (\Exception $e) {
      // Ignore errors, return pending.
    }

    return WorkflowExecutionResult::STATUS_PENDING;
  }

  /**
   * Extracts outputs from a completed session.
   *
   * @param \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundSessionInterface $session
   *   The session.
   *
   * @return array<string, mixed>
   *   The outputs array.
   */
  protected function extractOutputs(FlowDropPlaygroundSessionInterface $session): array {
    $outputs = [];

    try {
      // Get assistant messages which contain workflow outputs.
      $messages = $this->entityTypeManager
        ->getStorage("flowdrop_playground_message")
        ->loadByProperties([
          "session_id" => $session->id(),
          "role" => "assistant",
        ]);

      foreach ($messages as $message) {
        // Extract content and metadata from message.
        if (method_exists($message, "getContent")) {
          $outputs["content"] = $message->getContent();
        }
        if (method_exists($message, "getMessageMetadata")) {
          $metadata = $message->getMessageMetadata();
          if (!empty($metadata["outputs"])) {
            $outputs = array_merge($outputs, $metadata["outputs"]);
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error("Failed to extract outputs: @message", [
        "@message" => $e->getMessage(),
      ]);
    }

    return $outputs;
  }

}
