---
type: practice
title: Update FlowDrop workflow plugin ids after renaming a module
description: >-
  Renaming a flowdrop_* module leaves stale executor/plugin ids in
  config/sync/flowdrop_workflow.*.yml that must be updated and re-exported.
tags:
  - flowdrop
  - config
  - refactoring
kk_schema_version: 3
kk_id: practice-update-workflow-plugin-ids-after-module-rename
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: medium
---
Workflow configuration in `config/sync/flowdrop_workflow.*.yml` stores executor
and node-processor plugin ids as plain strings. Renaming or refactoring a
`flowdrop_*` module does not update them, and the workflow then fails at runtime
with a missing-plugin error.

Whenever a module providing workflow plugins is renamed, grep the workflow YAML
for the old plugin ids, update the `executor` and plugin-id keys, then re-export
with `drush cex`.
