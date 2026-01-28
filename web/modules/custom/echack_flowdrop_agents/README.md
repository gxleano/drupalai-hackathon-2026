# FlowDrop AI Agents Integration

This Drupal module integrates AI Agents with the FlowDrop workflow automation system, enabling AI agent execution as workflow nodes.

## Requirements

- Drupal 10 or 11
- [FlowDrop](https://www.drupal.org/project/flowdrop) module
- [AI Agents](https://www.drupal.org/project/ai_agents) module

## Installation

1. Enable required dependencies:
   ```bash
   drush en flowdrop ai_agents
   ```

2. Enable this module:
   ```bash
   drush en echack_flowdrop_agents
   ```

## Features

### AI Agent Executor Node

The module provides a FlowDrop Node Processor plugin (`ai_agents_executor`) that allows any configured AI agent to be executed within a workflow.

**Key capabilities:**

- Execute any plugin-based or config-based AI agent
- Support for multi-turn conversations via chat history
- Flexible creation mode (direct entity creation or blueprint return)
- Comprehensive status tracking and error handling
- Structured output for created/edited/deleted entities

## Configuration

### Input Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `agent_id` | string | Yes | The AI agent to execute (dynamically populated from available agents) |
| `task_description` | string | Yes | Detailed description of the task for the agent |
| `task_title` | string | No | Optional short title/identifier for the task |
| `chat_history` | array | No | Previous chat messages for context (each with `role` and `content`) |
| `create_directly` | boolean | No | If true, agent creates entities directly; if false, returns blueprints (default: true) |
| `additional_context` | string | No | Extra context or instructions appended to task description |

### Output Schema

| Output | Type | Description |
|--------|------|-------------|
| `response` | string | Agent response text |
| `agent_id` | string | ID of the executed agent |
| `agent_type` | string | Type of agent ("Plugin" or "Config") |
| `job_status` | integer | Numeric job status code |
| `job_status_label` | string | Human-readable job status |
| `structured_output` | object | Created/edited/deleted configs and entities |
| `questions` | array | Questions if agent needs clarification |
| `information` | string | Informational messages from agent |
| `success` | boolean | Whether execution was successful |
| `error_message` | string | Error details if execution failed |
| `execution_metadata` | object | Timestamp and execution details |

### Job Status Values

| Status | Label | Description |
|--------|-------|-------------|
| 0 | Not Solvable | Agent cannot solve the task |
| 1 | Solvable | Agent completed the task successfully |
| 2 | Needs Answers | Agent requires answers to questions |
| 3 | Should Answer Question | Agent provides an answer |
| 4 | Informs | Agent provides informational output |

## Usage Example

1. Create a FlowDrop workflow
2. Add an "AI Agent Executor" node
3. Configure the agent selection and task description
4. Connect inputs from previous workflow nodes (e.g., task_description, chat_history)
5. Use outputs in subsequent workflow nodes

## Module Structure

```
echack_flowdrop_agents/
├── echack_flowdrop_agents.info.yml
├── config/
│   └── install/
│       └── flowdrop_node_type.flowdrop_node_type.echack_flowdrop_agents_ai_agents_executor.yml
├── src/
│   └── Plugin/
│       └── FlowDropNodeProcessor/
│           └── Agents.php
└── README.md
```

## License

This project is licensed under the GPL-2.0-or-later license.
