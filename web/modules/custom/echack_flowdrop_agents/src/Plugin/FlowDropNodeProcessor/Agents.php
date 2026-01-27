<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_agents\Plugin\FlowDropNodeProcessor;

use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Agent Executor processor for FlowDrop workflows.
 *
 * Executes AI Agents from the ai_agents module within FlowDrop workflows,
 * providing full status tracking, structured output, and error handling.
 */
#[FlowDropNodeProcessor(
  id: "ai_agents_executor",
  label: new TranslatableMarkup("AI Agent Executor"),
  description: "Execute AI Agents with full status and output tracking",
  version: "1.0.0"
)]
class Agents extends AbstractFlowDropNodeProcessor {

  /**
   * Constructs an Agents processor.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $agentManager
   *   AI Agent manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AiAgentManager $agentManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.ai_agents')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    // Dynamically discover available agents from the agent manager.
    $agent_definitions = $this->agentManager->getDefinitions();
    $agent_options = [];
    $agent_enum = [];

    foreach ($agent_definitions as $agent_id => $definition) {
      $agent_enum[] = $agent_id;
      $label = $definition['label'] ?? $agent_id;
      $type = $this->getAgentType($agent_id);
      $agent_options[] = [
        'value' => $agent_id,
        'label' => "$label ($type)",
      ];
    }

    // Set default to first available agent, or empty if none exist.
    $default_agent = !empty($agent_enum) ? $agent_enum[0] : '';

    return [
      'type' => 'object',
      'properties' => [
        'agent_id' => [
          'type' => 'string',
          'title' => 'AI Agent',
          'description' => 'Select the AI agent to execute',
          'default' => $default_agent,
          'enum' => $agent_enum,
          'options' => $agent_options,
        ],
        'task_description' => [
          'type' => 'string',
          'title' => 'Task Description',
          'description' => 'Detailed description of the task for the agent to perform',
          'required' => TRUE,
          'format' => 'textarea',
        ],
        'task_title' => [
          'type' => 'string',
          'title' => 'Task Title',
          'description' => 'Optional short title for the task',
          'required' => FALSE,
        ],
        'chat_history' => [
          'type' => 'array',
          'title' => 'Chat History',
          'description' => 'Array of previous chat messages with role and content keys',
          'required' => FALSE,
          'default' => [],
          'items' => [
            'type' => 'object',
            'properties' => [
              'role' => ['type' => 'string'],
              'content' => ['type' => 'string'],
            ],
          ],
        ],
        'create_directly' => [
          'type' => 'boolean',
          'title' => 'Create Directly',
          'description' => 'If true, agent creates entities/configs immediately. If false, returns blueprint.',
          'required' => FALSE,
          'default' => TRUE,
        ],
        'additional_context' => [
          'type' => 'string',
          'title' => 'Additional Context',
          'description' => 'Extra context or instructions for the agent',
          'required' => FALSE,
          'format' => 'textarea',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'response' => [
          'type' => 'string',
          'description' => 'Agent response text',
        ],
        'agent_id' => [
          'type' => 'string',
          'description' => 'ID of the executed agent',
        ],
        'agent_type' => [
          'type' => 'string',
          'description' => 'Type of agent (Plugin or Config)',
        ],
        'job_status' => [
          'type' => 'integer',
          'description' => 'Job status code',
        ],
        'job_status_label' => [
          'type' => 'string',
          'description' => 'Human-readable job status',
        ],
        'structured_output' => [
          'type' => 'object',
          'description' => 'Created/edited/deleted configs and entities',
        ],
        'questions' => [
          'type' => 'array',
          'description' => 'Questions if agent needs answers',
        ],
        'information' => [
          'type' => 'string',
          'description' => 'Informational messages',
        ],
        'success' => [
          'type' => 'boolean',
          'description' => 'Whether execution was successful',
        ],
        'error_message' => [
          'type' => 'string',
          'description' => 'Error details if failed',
        ],
        'execution_metadata' => [
          'type' => 'object',
          'description' => 'Execution timestamp and metadata',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    $errors = [];

    // Validate agent_id.
    $agent_id = $params['agent_id'] ?? '';
    if (empty($agent_id)) {
      $errors[] = 'Agent ID is required';
    }
    else {
      $definitions = $this->agentManager->getDefinitions();
      if (!isset($definitions[$agent_id])) {
        $errors[] = "Agent ID '$agent_id' does not exist";
      }
    }

    // Validate task_description.
    $task_description = $params['task_description'] ?? '';
    if (empty($task_description)) {
      $errors[] = 'Task description is required';
    }

    // Validate chat_history structure.
    $chat_history = $params['chat_history'] ?? [];
    if (!empty($chat_history) && is_array($chat_history)) {
      foreach ($chat_history as $index => $message) {
        if (!is_array($message)) {
          $errors[] = "Chat history item at index $index must be an array";
          continue;
        }
        if (!isset($message['role'], $message['content'])) {
          $errors[] = "Chat history item at index $index must have 'role' and 'content' keys";
        }
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
    try {
      // Extract parameters.
      $agent_id = $params->getString('agent_id');
      $task_description = $params->getString('task_description');
      $task_title = $params->getString('task_title', '');
      $chat_history = $params->getArray('chat_history', []);
      $create_directly = $params->getBool('create_directly', TRUE);
      $additional_context = $params->getString('additional_context', '');

      // Create Task object.
      $task = new Task($task_description);
      if (!empty($task_title)) {
        $task->setTitle($task_title);
      }

      // Build ChatInput from history + current message.
      $chat_messages = [];
      foreach ($chat_history as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
          $chat_messages[] = new ChatMessage($msg['role'], $msg['content']);
        }
      }

      // Add current task as user message with additional context if provided.
      $current_message = $task_description;
      if (!empty($additional_context)) {
        $current_message .= "\n\nAdditional context: " . $additional_context;
      }
      $chat_messages[] = new ChatMessage('user', $current_message);
      $chat_input = new ChatInput($chat_messages);

      // Get agent instance.
      $agent = $this->agentManager->createInstance($agent_id);

      // Configure agent.
      $agent->setTask($task);
      $agent->setChatInput($chat_input);
      $agent->setCreateDirectly($create_directly);

      // Check solvability.
      $job_status = $agent->determineSolvability();

      // Initialize output array.
      $output = [
        'agent_id' => $agent_id,
        'agent_type' => $this->getAgentType($agent_id),
        'job_status' => $job_status,
        'job_status_label' => $this->getJobStatusLabel($job_status),
        'execution_metadata' => [
          'timestamp' => time(),
          'create_directly' => $create_directly,
        ],
      ];

      // Handle job status.
      switch ($job_status) {
        case AiAgentInterface::JOB_SOLVABLE:
          // Execute the agent.
          $agent->solve();
          $structured_output = $agent->getStructuredOutput();

          $output['success'] = TRUE;
          $output['response'] = $agent->answerQuestion() ?? 'Task completed successfully';
          $output['structured_output'] = [
            'created_configs' => $structured_output->getCreatedConfigs(),
            'edited_configs' => $structured_output->getEditedConfigs(),
            'deleted_configs' => $structured_output->getDeletedConfigs(),
            'created_contents' => $structured_output->getCreatedContents(),
            'edited_contents' => $structured_output->getEditedContents(),
            'deleted_contents' => $structured_output->getDeletedContents(),
          ];
          break;

        case AiAgentInterface::JOB_NEEDS_ANSWERS:
          $output['success'] = FALSE;
          $output['response'] = $agent->answerQuestion() ?? 'Agent needs more information';
          $output['questions'] = $agent->askQuestion() ?? [];
          break;

        case AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION:
          $output['success'] = TRUE;
          $output['response'] = $agent->answerQuestion() ?? 'Question answered';
          break;

        case AiAgentInterface::JOB_INFORMS:
          $output['success'] = TRUE;
          $output['response'] = $agent->answerQuestion() ?? 'Information provided';
          $output['information'] = $agent->inform() ?? '';
          break;

        case AiAgentInterface::JOB_NOT_SOLVABLE:
        default:
          $output['success'] = FALSE;
          $output['response'] = $agent->answerQuestion() ?? 'Task is not solvable';
          $output['error_message'] = $agent->inform() ?? 'Agent cannot solve this task';
          break;
      }

      return $output;

    }
    catch (\Exception $e) {
      return [
        'success' => FALSE,
        'error_message' => $e->getMessage(),
        'agent_id' => $agent_id ?? NULL,
        'agent_type' => isset($agent_id) ? $this->getAgentType($agent_id) : NULL,
        'job_status' => AiAgentInterface::JOB_NOT_SOLVABLE,
        'job_status_label' => $this->getJobStatusLabel(AiAgentInterface::JOB_NOT_SOLVABLE),
        'response' => 'An error occurred during agent execution',
        'execution_metadata' => [
          'timestamp' => time(),
          'exception_class' => get_class($e),
        ],
      ];
    }
  }

  /**
   * Determines the agent type (Plugin or Config).
   *
   * @param string $agent_id
   *   The agent ID.
   *
   * @return string
   *   Either 'Plugin' or 'Config'.
   */
  protected function getAgentType(string $agent_id): string {
    $definitions = $this->agentManager->getDefinitions();
    if (!isset($definitions[$agent_id])) {
      return 'Unknown';
    }

    $definition = $definitions[$agent_id];
    // Plugin-based agents have a 'class' key in their definition.
    return isset($definition['class']) ? 'Plugin' : 'Config';
  }

  /**
   * Converts job status constant to human-readable label.
   *
   * @param int $status
   *   Job status constant.
   *
   * @return string
   *   Human-readable status label.
   */
  protected function getJobStatusLabel(int $status): string {
    return match ($status) {
      AiAgentInterface::JOB_NOT_SOLVABLE => 'Not Solvable',
      AiAgentInterface::JOB_SOLVABLE => 'Solvable',
      AiAgentInterface::JOB_NEEDS_ANSWERS => 'Needs Answers',
      AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION => 'Should Answer Question',
      AiAgentInterface::JOB_INFORMS => 'Informs',
      default => 'Unknown',
    };
  }

}
