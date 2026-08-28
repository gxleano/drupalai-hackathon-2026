---
type: practice
title: Wire all three Entity Save inputs and shape field values as field items
description: >-
  FlowDrop's Entity Save node needs entity_id, values and trigger wired, and
  multi-property fields must arrive as an array of field-item objects.
tags:
  - flowdrop
  - entity
  - workflow
  - gotcha
kk_schema_version: 3
kk_id: practice-flowdrop-entity-save-input-contract
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: medium
---
The Entity Save node silently does nothing unless all three inputs are
connected: `entity_id` (typically from a Content Context node), `values`, and
`trigger` (from the true branch of a Boolean Gateway, when a confirmation step
gates the save).

The `values` payload must match Drupal field structure, not flat scalars. A
multi-property field such as `field_body` has to be an array of field-item
objects, and each item's `format` must name a real text format:

```json
{"field_body": [{"value": "<p>…</p>", "summary": "…", "format": "content_format"}]}
```

A bare string (`"field_body": "<p>…</p>"`) is accepted by the node but leaves
the field unchanged.

Chat models routinely wrap JSON output in markdown code fences, so route an AI
model's output through a `json_to_data` node before it reaches Entity Save
rather than relying on the prompt to suppress the fences.
