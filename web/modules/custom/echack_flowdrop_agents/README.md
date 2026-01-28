# FlowDrop AI Agents Executor

Integrates Drupal AI Agents with FlowDrop workflows, allowing you to execute AI agents as workflow nodes with full status tracking, structured output, and error handling.

## Features

- **AI Agent Executor Node**: FlowDrop node that executes any AI Agent from the ai_agents module
- **Full Status Tracking**: Track job status (solvable, needs answers, informs, etc.)
- **Structured Output**: Get detailed information about created/edited/deleted configs and entities
- **Chat History Support**: Pass conversation context to agents for multi-turn interactions
- **Error Handling**: Comprehensive error handling with detailed error messages
- **Direct vs Blueprint Mode**: Choose whether agents create entities immediately or return blueprints
- **Dynamic Agent Discovery**: Automatically discovers all available AI agents

## Installation

```bash
drush en echack_flowdrop_agents -y
drush cr
```

## Usage

### 1. Add AI Agent Executor Node to Workflow

In the FlowDrop editor, add an **AI Agent Executor** node from the **Agents** category.

### 2. Configure Node Parameters

#### Required Parameters

- **agent_id**: Select the AI agent to execute (dropdown list of available agents)
- **task_description**: Detailed description of the task for the agent to perform

#### Optional Parameters

- **task_title**: Short title for the task
- **chat_history**: Array of previous chat messages for conversation context
- **create_directly**: Boolean (default: `true`)
  - `true`: Agent creates entities/configs immediately
  - `false`: Agent returns blueprint without creating anything
- **additional_context**: Extra context or instructions for the agent

### 3. Node Outputs

The node outputs a comprehensive object with the following properties:

- **response**: Agent response text
- **agent_id**: ID of the executed agent
- **agent_type**: Type of agent (`Plugin` or `Config`)
- **job_status**: Numeric job status code
- **job_status_label**: Human-readable status label
- **structured_output**: Object containing:
  - `created_configs`: Array of created configuration entities
  - `edited_configs`: Array of edited configuration entities
  - `deleted_configs`: Array of deleted configuration entities
  - `created_contents`: Array of created content entities
  - `edited_contents`: Array of edited content entities
  - `deleted_contents`: Array of deleted content entities
- **questions**: Array of questions if agent needs answers
- **information**: Informational messages from the agent
- **success**: Boolean indicating success/failure
- **error_message**: Error details if execution failed
- **execution_metadata**: Execution timestamp and metadata

## Job Status Values

The agent execution can result in different statuses:

| Status | Label | Description |
| ------ | ----- | ----------- |
| `1` | Solvable | Task can be solved and was executed successfully |
| `2` | Needs Answers | Agent needs more information to proceed |
| `3` | Should Answer Question | Agent is responding to a question |
| `4` | Informs | Agent is providing informational response |
| `0` | Not Solvable | Task cannot be solved by this agent |

## Example Workflows

### Example 1: Simple Agent Execution

```yaml
# FlowDrop workflow example
nodes:
  - id: agent_executor
    type: echack_flowdrop_agents_ai_agents_executor
    parameters:
      agent_id: 'my_agent'
      task_description: 'Create a new article node about AI'
      create_directly: true

  - id: output
    type: text_output
    inputs:
      text: '{{ agent_executor.response }}'
```

### Example 2: Agent with Chat History

```yaml
nodes:
  - id: build_history
    type: data_processor
    # Build chat history array

  - id: agent_executor
    type: echack_flowdrop_agents_ai_agents_executor
    parameters:
      agent_id: 'conversational_agent'
      task_description: 'Continue the conversation'
      chat_history: '{{ build_history.output }}'
      create_directly: false
```

### Example 3: Conditional Execution Based on Status

```yaml
nodes:
  - id: agent_executor
    type: echack_flowdrop_agents_ai_agents_executor
    parameters:
      agent_id: 'content_creator'
      task_description: 'Create a new page'

  - id: check_success
    type: if_condition
    condition: '{{ agent_executor.success }}'

  - id: success_handler
    type: text_output
    inputs:
      text: 'Agent created: {{ agent_executor.structured_output.created_contents }}'

  - id: error_handler
    type: text_output
    inputs:
      text: 'Error: {{ agent_executor.error_message }}'
```

## Chat History Format

When passing chat history, use this format:

```json
[
  {
    "role": "user",
    "content": "Create a new article"
  },
  {
    "role": "assistant",
    "content": "I'll create an article. What should the title be?"
  },
  {
    "role": "user",
    "content": "Use 'AI in Drupal' as the title"
  }
]
```

## Working with Structured Output

The `structured_output` object provides detailed information about what the agent created, edited, or deleted:

```php
// Example structured output
{
  "created_configs": [
    {
      "type": "node_type",
      "id": "article",
      "label": "Article"
    }
  ],
  "edited_configs": [],
  "deleted_configs": [],
  "created_contents": [
    {
      "type": "node",
      "id": "123",
      "bundle": "article",
      "title": "AI in Drupal"
    }
  ],
  "edited_contents": [],
  "deleted_contents": []
}
```

## Error Handling

The node provides comprehensive error handling:

1. **Validation Errors**: Invalid parameters trigger validation errors before execution
2. **Agent Errors**: Errors during agent execution are caught and returned in `error_message`
3. **Success Flag**: Always check the `success` boolean before processing results
4. **Job Status**: Use `job_status` to determine why execution failed

Example error handling in workflow:

```yaml
nodes:
  - id: agent_executor
    type: echack_flowdrop_agents_ai_agents_executor

  - id: check_result
    type: if_condition
    condition: '{{ agent_executor.success == false }}'

  - id: log_error
    type: logger
    inputs:
      message: 'Agent execution failed: {{ agent_executor.error_message }}'
      severity: 'error'
```

## Available AI Agents

The agent dropdown dynamically discovers all available agents from the AI Agents module. Available agents depend on which agents are installed in your Drupal site.

To see available agents:
1. Navigate to `/admin/config/ai/agents` (or equivalent AI agents admin page)
2. Or use: `drush eval "print_r(array_keys(\Drupal::service('plugin.manager.ai_agents')->getDefinitions()));"`

## Integration with AI Agents Module

This module requires the `ai_agents` module from the Drupal AI ecosystem. Agents can be:

- **Plugin-based agents**: Defined in code as PHP plugins
- **Config-based agents**: Created through the UI and stored as configuration entities

The `agent_type` output indicates which type of agent was executed.

## Troubleshooting

### Agent not appearing in dropdown
- Ensure the agent is properly defined and discoverable
- Clear caches: `drush cr`
- Check that ai_agents module is enabled

### "Agent needs more information" response
- The agent returned `JOB_NEEDS_ANSWERS` status
- Check the `questions` output for what information is needed
- Provide additional context via `additional_context` parameter

### "Task is not solvable" error
- The agent determined it cannot solve this task
- Try a different agent or rephrase the task description
- Check the `error_message` for details

## Advanced Usage

### Programmatic Execution

```php
use Drupal\flowdrop\DTO\ParameterBag;

// Get the processor
$processor = \Drupal::service('plugin.manager.flowdrop_node_processor')
  ->createInstance('ai_agents_executor');

// Create parameters
$params = new ParameterBag([
  'agent_id' => 'my_agent',
  'task_description' => 'Create a new article',
  'create_directly' => TRUE,
]);

// Execute
$result = $processor->process($params);

if ($result['success']) {
  $response = $result['response'];
  $created = $result['structured_output']['created_contents'];
  // Handle success...
}
else {
  $error = $result['error_message'];
  // Handle error...
}
```

### Creating Custom Workflows

For complex agent workflows:
1. Chain multiple agent executions
2. Use conditional logic based on `job_status`
3. Pass `structured_output` from one agent to another
4. Build conversation flows using `chat_history`

## See Also

- [FlowDrop Node Session](../echack_flowdrop_node_session/README.md) - Entity context support for workflows
- [Drupal AI Module](https://www.drupal.org/project/ai) - Core AI functionality
- [AI Agents Module](https://www.drupal.org/project/ai_agents) - AI agents framework
