<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_workflow_executor\Plugin\FlowDropNodeProcessor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult;
use Drupal\echack_flowdrop_workflow_executor\Service\WorkflowExecutionServiceInterface;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationError;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Workflow Results Retriever node processor for FlowDrop workflows.
 *
 * This plugin retrieves results from asynchronously executed workflows.
 * Use this after a WorkflowExecutor node with 'job_async' mode to get
 * the results when they're ready.
 */
#[FlowDropNodeProcessor(
  id: "workflow_results_retriever",
  label: new TranslatableMarkup("Workflow Results Retriever"),
  description: "Retrieve results from an async workflow execution",
  version: "1.0.0"
)]
class WorkflowResultsRetriever extends AbstractFlowDropNodeProcessor {

  /**
   * Constructs a WorkflowResultsRetriever object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\echack_flowdrop_workflow_executor\Service\WorkflowExecutionServiceInterface $workflowExecutionService
   *   The workflow execution service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly WorkflowExecutionServiceInterface $workflowExecutionService,
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
      $container->get("echack_flowdrop_workflow_executor.service")
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    $errors = [];

    // Validate session_id is provided.
    $sessionId = $params["session_id"] ?? "";
    if (empty($sessionId)) {
      $errors[] = new ValidationError("session_id", "Session ID is required");
    }

    // Validate timeout.
    $timeout = $params["timeout"] ?? 300;
    if (!is_int($timeout) && !is_numeric($timeout)) {
      $errors[] = new ValidationError("timeout", "Timeout must be a number");
    }
    elseif ((int) $timeout < 1 || (int) $timeout > 3600) {
      $errors[] = new ValidationError("timeout", "Timeout must be between 1 and 3600 seconds");
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
    $sessionId = $params->getString("session_id", "");
    $waitForCompletion = $params->getBool("wait_for_completion", TRUE);
    $timeout = $params->getInt("timeout", 300);
    $pollIntervalMs = $params->getInt("poll_interval_ms", 2000);

    // Get results from the workflow execution service.
    $result = $this->workflowExecutionService->getResults(
      $sessionId,
      $waitForCompletion,
      $timeout,
      $pollIntervalMs
    );

    return [
      "success" => $result->isSuccess(),
      "status" => $result->getStatus(),
      "outputs" => $result->getOutputs(),
      "error_message" => $result->getErrorMessage(),
      "wait_time_ms" => $result->getExecutionTimeMs(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      "type" => "object",
      "properties" => [
        "session_id" => [
          "type" => "string",
          "title" => "Session ID",
          "description" => "The session ID from a WorkflowExecutor node (async mode)",
        ],
        "wait_for_completion" => [
          "type" => "boolean",
          "title" => "Wait for Completion",
          "description" => "Block until the workflow completes or times out",
          "default" => TRUE,
        ],
        "timeout" => [
          "type" => "integer",
          "title" => "Timeout (seconds)",
          "description" => "Maximum time to wait for completion",
          "default" => 300,
          "minimum" => 1,
          "maximum" => 3600,
        ],
        "poll_interval_ms" => [
          "type" => "integer",
          "title" => "Poll Interval (ms)",
          "description" => "How often to check for completion",
          "default" => 2000,
          "minimum" => 100,
          "maximum" => 30000,
        ],
      ],
      "required" => ["session_id"],
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
          "description" => "Whether results were retrieved successfully",
        ],
        "status" => [
          "type" => "string",
          "description" => "Current status: completed, running, pending, failed, timeout",
          "enum" => [
            WorkflowExecutionResult::STATUS_COMPLETED,
            WorkflowExecutionResult::STATUS_RUNNING,
            WorkflowExecutionResult::STATUS_PENDING,
            WorkflowExecutionResult::STATUS_FAILED,
            WorkflowExecutionResult::STATUS_TIMEOUT,
          ],
        ],
        "outputs" => [
          "type" => "object",
          "description" => "Outputs from the executed workflow",
          "additionalProperties" => TRUE,
        ],
        "error_message" => [
          "type" => "string",
          "description" => "Error message if retrieval failed",
        ],
        "wait_time_ms" => [
          "type" => "integer",
          "description" => "How long we waited for results (in milliseconds)",
        ],
      ],
    ];
  }

}
