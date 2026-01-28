<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_workflow_executor\Service;

use Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult;
use Drupal\flowdrop_workflow\FlowDropWorkflowInterface;

/**
 * Interface for the workflow execution service.
 *
 * This service provides methods to execute FlowDrop workflows programmatically,
 * supporting various execution modes including synchronous job-based execution,
 * async execution, and fire-and-forget patterns.
 */
interface WorkflowExecutionServiceInterface {

  /**
   * Execution mode: Create job and wait for completion.
   */
  public const MODE_JOB = "job";

  /**
   * Execution mode: Create job and return immediately (async).
   */
  public const MODE_JOB_ASYNC = "job_async";

  /**
   * Execution mode: Fire and forget, no result tracking.
   */
  public const MODE_JOB_FIRE_FORGET = "job_fire_forget";

  /**
   * Executes a workflow with the specified mode.
   *
   * @param \Drupal\flowdrop_workflow\FlowDropWorkflowInterface $workflow
   *   The workflow to execute.
   * @param array<string, mixed> $inputData
   *   Input data to pass to the workflow.
   * @param string $mode
   *   Execution mode: 'job', 'job_async', or 'job_fire_forget'.
   * @param int $timeout
   *   Timeout in seconds for 'job' mode (default: 300).
   * @param int $pollIntervalMs
   *   Polling interval in milliseconds (default: 2000).
   * @param array<string, mixed> $parentContext
   *   Parent workflow context for recursion tracking.
   *
   * @return \Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult
   *   The execution result.
   */
  public function execute(
    FlowDropWorkflowInterface $workflow,
    array $inputData = [],
    string $mode = self::MODE_JOB,
    int $timeout = 300,
    int $pollIntervalMs = 2000,
    array $parentContext = [],
  ): WorkflowExecutionResult;

  /**
   * Gets results for an existing session.
   *
   * @param string $sessionId
   *   The session ID to get results for.
   * @param bool $waitForCompletion
   *   Whether to wait for completion.
   * @param int $timeout
   *   Timeout in seconds.
   * @param int $pollIntervalMs
   *   Polling interval in milliseconds.
   *
   * @return \Drupal\echack_flowdrop_workflow_executor\DTO\WorkflowExecutionResult
   *   The execution result.
   */
  public function getResults(
    string $sessionId,
    bool $waitForCompletion = TRUE,
    int $timeout = 300,
    int $pollIntervalMs = 2000,
  ): WorkflowExecutionResult;

  /**
   * Checks if a workflow session has completed.
   *
   * @param string $sessionId
   *   The session ID.
   *
   * @return bool
   *   TRUE if completed, FALSE otherwise.
   */
  public function isComplete(string $sessionId): bool;

  /**
   * Gets the current status of a workflow session.
   *
   * @param string $sessionId
   *   The session ID.
   *
   * @return string
   *   The status string.
   */
  public function getStatus(string $sessionId): string;

}
