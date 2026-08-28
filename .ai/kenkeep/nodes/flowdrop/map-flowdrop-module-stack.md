---
type: map
title: FlowDrop module stack and node categories
description: >-
  The visual workflow engine in use, its submodules, and the categories that
  organise workflow nodes in config/sync/flowdrop_node_category.*.
tags:
  - flowdrop
  - modules
  - workflow
  - ai
kk_schema_version: 3
kk_id: map-flowdrop-module-stack
kk_derived_from: []
kk_relates_to:
  - practice-update-workflow-plugin-ids-after-module-rename
kk_depends_on: []
kk_confidence: medium
---
FlowDrop is the visual workflow builder driving the AI automation in this
project. Core pieces: `flowdrop` (engine), `flowdrop_ui` and
`flowdrop_ui_agents` (visual builders), `flowdrop_workflow`,
`flowdrop_pipeline`, `flowdrop_job`, `flowdrop_trigger`, `flowdrop_node_type`,
`flowdrop_node_category`, `flowdrop_stategraph`, `flowdrop_interrupt`, plus
`flowdrop_agents`, `flowdrop_ai_context`, `flowdrop_ai_provider`,
`flowdrop_gin`, `flowdrop_node_session` and `flowdrop_field_widget_actions`.

Node categories (see `config/sync/flowdrop_node_category.*.yml`): Agents, AI,
Bundles, Data, Embeddings, Helpers, Human in Loop, Inputs, Logic, Memories,
Models, Outputs, Processing, Prompts, Stategraph, Tools, Vector Stores.

Entry points: `/admin/flowdrop` (with `/structure` and `/config` sub-pages) for
workflows, and `/admin/config/ai/ai-assistant/{ai_assistant}/edit-flowdrop` to
edit an AI Assistant's flow.

<!-- kk:related:start -->
# Related

- Related: [practice-update-workflow-plugin-ids-after-module-rename](/flowdrop/practice-update-workflow-plugin-ids-after-module-rename.md)
<!-- kk:related:end -->
