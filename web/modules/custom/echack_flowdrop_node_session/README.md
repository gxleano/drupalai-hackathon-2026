# FlowDrop Node Session

Provides entity context support for FlowDrop playground sessions, allowing workflows to be initialized with a Drupal entity (node, term, etc.) as context.

## Features

- **EntityContext Node Processor**: A FlowDrop node that outputs entity data to workflows
- **Entity-Context Sessions**: Create playground sessions pre-loaded with entity data
- **Revision Support**: Optionally load specific entity revisions
- **URL-based Playground**: Launch playground with entity context via URL parameters

## Installation

```bash
drush en echack_flowdrop_node_session -y
drush cr
```

## Usage

### 1. Add EntityContext Node to Workflow

In the FlowDrop editor, add an **EntityContext** node. It outputs:
- `entity`: Full serialized entity (fields, metadata)
- `entity_type`, `entity_id`, `bundle`, `revision_id`
- `is_default_revision`: Boolean

### 2. Launch Playground with Entity Context

Visit the URL with query parameters:

```
/admin/flowdrop/workflows/{workflow_id}/playground/entity?entity_type=node&entity_id=1
```

Optional parameters: `bundle`, `revision_id`, `session_name`

### 3. API Endpoint

```bash
POST /api/flowdrop-node-session/workflows/{workflow_id}/sessions
Content-Type: application/json

{
  "entity_type": "node",
  "entity_id": "1",
  "revision_id": "5",  // optional
  "bundle": "article", // optional
  "name": "Session Name" // optional
}
```

## Creating URLs Programmatically

### Route: `echack_flowdrop_node_session.playground.entity`

```php
use Drupal\Core\Url;

// Basic URL
$url = Url::fromRoute('echack_flowdrop_node_session.playground.entity', [
  'flowdrop_workflow' => 'my_workflow',
], [
  'query' => [
    'entity_type' => 'node',
    'entity_id' => '123',
  ],
]);

// With revision
$url = Url::fromRoute('echack_flowdrop_node_session.playground.entity', [
  'flowdrop_workflow' => 'my_workflow',
], [
  'query' => [
    'entity_type' => 'node',
    'entity_id' => '123',
    'revision_id' => '456',
    'bundle' => 'article',
    'session_name' => 'My Session',
  ],
]);

// Get URL string
$urlString = $url->toString();

// In Twig
{{ url('echack_flowdrop_node_session.playground.entity', {
  'flowdrop_workflow': 'my_workflow'
}, {
  'query': {
    'entity_type': 'node',
    'entity_id': node.id
  }
}) }}
```

### Route Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `flowdrop_workflow` | route | Workflow machine name |
| `entity_type` | query | Entity type ID (required) |
| `entity_id` | query | Entity ID (required) |
| `revision_id` | query | Revision ID (optional) |
| `bundle` | query | Bundle for validation (optional) |
| `session_name` | query | Custom session name (optional) |

## Services

```php
// Get the service
$nodeSessionService = \Drupal::service('echack_flowdrop_node_session.service');

// Create session with entity context
$session = $nodeSessionService->createSessionWithEntityContext(
  $workflow,
  'node',
  '123',
  'article',  // bundle (optional)
  '456'       // revision_id (optional)
);

// Check if session has entity context
$hasContext = $nodeSessionService->hasEntityContext($session);

// Get entity context from session
$context = $nodeSessionService->getEntityContext($session);
```
