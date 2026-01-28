<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_workflow_executor\DTO;

/**
 * Data transfer object for workflow execution results.
 *
 * This class encapsulates the result of a workflow execution,
 * including status, session information, outputs, and timing data.
 */
final class WorkflowExecutionResult {

  /**
   * Status constant for completed execution.
   */
  public const STATUS_COMPLETED = "completed";

  /**
   * Status constant for running execution.
   */
  public const STATUS_RUNNING = "running";

  /**
   * Status constant for pending execution.
   */
  public const STATUS_PENDING = "pending";

  /**
   * Status constant for failed execution.
   */
  public const STATUS_FAILED = "failed";

  /**
   * Status constant for timed out execution.
   */
  public const STATUS_TIMEOUT = "timeout";

  /**
   * Constructs a WorkflowExecutionResult object.
   *
   * @param bool $success
   *   Whether the execution was successful.
   * @param string $status
   *   The execution status (completed, running, pending, failed, timeout).
   * @param string $sessionId
   *   The playground session ID.
   * @param string $jobId
   *   The job ID if available.
   * @param array<string, mixed> $outputs
   *   The workflow outputs when completed.
   * @param string $errorMessage
   *   Error message if execution failed.
   * @param int $executionTimeMs
   *   Execution time in milliseconds.
   */
  public function __construct(
    private readonly bool $success,
    private readonly string $status,
    private readonly string $sessionId = "",
    private readonly string $jobId = "",
    private readonly array $outputs = [],
    private readonly string $errorMessage = "",
    private readonly int $executionTimeMs = 0,
  ) {}

  /**
   * Creates a successful result.
   *
   * @param string $sessionId
   *   The session ID.
   * @param array<string, mixed> $outputs
   *   The workflow outputs.
   * @param int $executionTimeMs
   *   Execution time in milliseconds.
   * @param string $jobId
   *   The job ID if available.
   *
   * @return self
   *   A new successful result instance.
   */
  public static function success(
    string $sessionId,
    array $outputs = [],
    int $executionTimeMs = 0,
    string $jobId = "",
  ): self {
    return new self(
      success: TRUE,
      status: self::STATUS_COMPLETED,
      sessionId: $sessionId,
      jobId: $jobId,
      outputs: $outputs,
      executionTimeMs: $executionTimeMs,
    );
  }

  /**
   * Creates a pending/async result.
   *
   * @param string $sessionId
   *   The session ID.
   * @param string $jobId
   *   The job ID if available.
   *
   * @return self
   *   A new pending result instance.
   */
  public static function pending(string $sessionId, string $jobId = ""): self {
    return new self(
      success: TRUE,
      status: self::STATUS_PENDING,
      sessionId: $sessionId,
      jobId: $jobId,
    );
  }

  /**
   * Creates a running result.
   *
   * @param string $sessionId
   *   The session ID.
   * @param string $jobId
   *   The job ID if available.
   *
   * @return self
   *   A new running result instance.
   */
  public static function running(string $sessionId, string $jobId = ""): self {
    return new self(
      success: TRUE,
      status: self::STATUS_RUNNING,
      sessionId: $sessionId,
      jobId: $jobId,
    );
  }

  /**
   * Creates a failed result.
   *
   * @param string $errorMessage
   *   The error message.
   * @param string $sessionId
   *   The session ID if available.
   *
   * @return self
   *   A new failed result instance.
   */
  public static function failure(string $errorMessage, string $sessionId = ""): self {
    return new self(
      success: FALSE,
      status: self::STATUS_FAILED,
      sessionId: $sessionId,
      errorMessage: $errorMessage,
    );
  }

  /**
   * Creates a timeout result.
   *
   * @param string $sessionId
   *   The session ID.
   * @param int $executionTimeMs
   *   How long we waited before timing out.
   *
   * @return self
   *   A new timeout result instance.
   */
  public static function timeout(string $sessionId, int $executionTimeMs = 0): self {
    return new self(
      success: FALSE,
      status: self::STATUS_TIMEOUT,
      sessionId: $sessionId,
      errorMessage: "Workflow execution timed out",
      executionTimeMs: $executionTimeMs,
    );
  }

  /**
   * Gets whether the execution was successful.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function isSuccess(): bool {
    return $this->success;
  }

  /**
   * Gets the execution status.
   *
   * @return string
   *   The status string.
   */
  public function getStatus(): string {
    return $this->status;
  }

  /**
   * Gets the session ID.
   *
   * @return string
   *   The session ID.
   */
  public function getSessionId(): string {
    return $this->sessionId;
  }

  /**
   * Gets the job ID.
   *
   * @return string
   *   The job ID.
   */
  public function getJobId(): string {
    return $this->jobId;
  }

  /**
   * Gets the workflow outputs.
   *
   * @return array<string, mixed>
   *   The outputs array.
   */
  public function getOutputs(): array {
    return $this->outputs;
  }

  /**
   * Gets the error message.
   *
   * @return string
   *   The error message.
   */
  public function getErrorMessage(): string {
    return $this->errorMessage;
  }

  /**
   * Gets the execution time in milliseconds.
   *
   * @return int
   *   The execution time.
   */
  public function getExecutionTimeMs(): int {
    return $this->executionTimeMs;
  }

  /**
   * Converts the result to an array.
   *
   * @return array<string, mixed>
   *   The result as an array.
   */
  public function toArray(): array {
    return [
      "success" => $this->success,
      "status" => $this->status,
      "session_id" => $this->sessionId,
      "job_id" => $this->jobId,
      "outputs" => $this->outputs,
      "error_message" => $this->errorMessage,
      "execution_time_ms" => $this->executionTimeMs,
    ];
  }

}
