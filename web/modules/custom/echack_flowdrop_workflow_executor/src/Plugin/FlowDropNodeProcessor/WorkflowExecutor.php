<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_workflow_executor\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
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
 * Workflow Executor node processor for FlowDrop workflows.
 *
 * This plugin enables executing another FlowDrop workflow from within a workflow,
 * supporting synchronous (job), asynchronous (job_async), and fire-and-forget
 * (job_fire_forget) execution modes.
 */
#[FlowDropNodeProcessor(
  id: "workflow_executor",
  label: new TranslatableMarkup("Workflow Executor"),
  description: "Execute another FlowDrop workflow with configurable execution mode",
  version: "1.0.0"
)]
class WorkflowExecutor extends AbstractFlowDropNodeProcessor {

  /**
   * Constructs a WorkflowExecutor object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\echack_flowdrop_workflow_executor\Service\WorkflowExecutionServiceInterface $workflowExecutionService
   *   The workflow execution service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get("entity_type.manager"),
      $container->get("echack_flowdrop_workflow_executor.service")
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    $errors = [];

    // Validate workflow_id is provided.
    $workflowId = $params["workflow_id"] ?? "";
    if (empty($workflowId)) {
      $errors[] = new ValidationError("workflow_id", "Workflow ID is required");
    }
    else {
      // Check if workflow exists.
      try {
        $workflow = $this->entityTypeManager
          ->getStorage("flowdrop_workflow")
          ->load($workflowId);

        if ($workflow === NULL) {
          $errors[] = new ValidationError("workflow_id", "Workflow not found: {$workflowId}");
        }
      }
      catch (\Exception $e) {
        $errors[] = new ValidationError("workflow_id", "Error loading workflow: {$e->getMessage()}");
      }
    }

    // Validate execution_mode.
    $mode = $params["execution_mode"] ?? WorkflowExecutionServiceInterface::MODE_JOB;
    $validModes = [
      WorkflowExecutionServiceInterface::MODE_JOB,
      WorkflowExecutionServiceInterface::MODE_JOB_ASYNC,
      WorkflowExecutionServiceInterface::MODE_JOB_FIRE_FORGET,
    ];
    if (!in_array($mode, $validModes, TRUE)) {
      $errors[] = new ValidationError("execution_mode", "Invalid execution mode: {$mode}");
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
    $workflowId = $params->getString("workflow_id", "");
    $inputData = $params->getArray("input_data", []);
    $mode = $params->getString("execution_mode", WorkflowExecutionServiceInterface::MODE_JOB);
    $timeout = $params->getInt("timeout", 300);
    $pollIntervalMs = $params->getInt("poll_interval_ms", 2000);

    // Load the workflow.
    $workflow = $this->entityTypeManager
      ->getStorage("flowdrop_workflow")
      ->load($workflowId);

    if ($workflow === NULL) {
      return [
        "success" => FALSE,
        "status" => WorkflowExecutionResult::STATUS_FAILED,
        "error_message" => "Workflow not found: {$workflowId}",
        "session_id" => "",
        "job_id" => "",
        "outputs" => [],
        "execution_time_ms" => 0,
      ];
    }

    // Build parent context from current execution if available.
    $parentContext = [];
    $sessionMetadata = $params->getArray("__session_metadata", []);
    if (!empty($sessionMetadata["workflow_executor_parent_context"])) {
      $parentContext = $sessionMetadata["workflow_executor_parent_context"];
    }

    // Execute the workflow.
    $result = $this->workflowExecutionService->execute(
      $workflow,
      $inputData,
      $mode,
      $timeout,
      $pollIntervalMs,
      $parentContext
    );

    return $result->toArray();
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    // Get available workflows for the dropdown.
    $workflowOptions = $this->getWorkflowOptions();

    return [
      "type" => "object",
      "properties" => [
        "workflow_id" => [
          "type" => "string",
          "title" => "Workflow",
          "description" => "Select the workflow to execute",
          "enum" => array_keys($workflowOptions),
          "options" => array_map(
            fn($id, $label) => ["value" => $id, "label" => $label],
            array_keys($workflowOptions),
            array_values($workflowOptions)
          ),
        ],
        "input_data" => [
          "type" => "object",
          "title" => "Input Data",
          "description" => "Data to pass to the child workflow (available in workflow metadata)",
          "additionalProperties" => TRUE,
          "default" => [],
        ],
        "execution_mode" => [
          "type" => "string",
          "title" => "Execution Mode",
          "description" => "How to execute the workflow",
          "enum" => [
            WorkflowExecutionServiceInterface::MODE_JOB,
            WorkflowExecutionServiceInterface::MODE_JOB_ASYNC,
            WorkflowExecutionServiceInterface::MODE_JOB_FIRE_FORGET,
          ],
          "options" => [
            ["value" => "job", "label" => "Job (wait for completion)"],
            ["value" => "job_async", "label" => "Async (return session ID, get results later)"],
            ["value" => "job_fire_forget", "label" => "Fire & Forget (no result tracking)"],
          ],
          "default" => "job",
        ],
        "timeout" => [
          "type" => "integer",
          "title" => "Timeout (seconds)",
          "description" => "Maximum time to wait for completion (job mode only)",
          "default" => 300,
          "minimum" => 1,
          "maximum" => 3600,
        ],
        "poll_interval_ms" => [
          "type" => "integer",
          "title" => "Poll Interval (ms)",
          "description" => "How often to check for completion (job mode only)",
          "default" => 2000,
          "minimum" => 100,
          "maximum" => 30000,
        ],
      ],
      "required" => ["workflow_id"],
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
          "description" => "Whether the workflow was executed/started successfully",
        ],
        "status" => [
          "type" => "string",
          "description" => "Execution status: completed, running, pending, failed, timeout",
          "enum" => [
            WorkflowExecutionResult::STATUS_COMPLETED,
            WorkflowExecutionResult::STATUS_RUNNING,
            WorkflowExecutionResult::STATUS_PENDING,
            WorkflowExecutionResult::STATUS_FAILED,
            WorkflowExecutionResult::STATUS_TIMEOUT,
          ],
        ],
        "session_id" => [
          "type" => "string",
          "description" => "The session ID of the executed workflow (for async result retrieval)",
        ],
        "job_id" => [
          "type" => "string",
          "description" => "The job ID if available",
        ],
        "outputs" => [
          "type" => "object",
          "description" => "Outputs from the executed workflow (when completed)",
          "additionalProperties" => TRUE,
        ],
        "error_message" => [
          "type" => "string",
          "description" => "Error message if execution failed",
        ],
        "execution_time_ms" => [
          "type" => "integer",
          "description" => "Total execution time in milliseconds",
        ],
      ],
    ];
  }

  /**
   * Gets available workflows for the dropdown.
   *
   * @return array<string, string>
   *   Array of workflow IDs to labels.
   */
  protected function getWorkflowOptions(): array {
    $options = [];

    try {
      $workflows = $this->entityTypeManager
        ->getStorage("flowdrop_workflow")
        ->loadMultiple();

      foreach ($workflows as $workflow) {
        $options[$workflow->id()] = $workflow->label() ?? $workflow->id();
      }
    }
    catch (\Exception $e) {
      // Return empty options on error.
    }

    return $options;
  }

}
