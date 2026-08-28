---
type: practice
title: Prefer contributed flowdrop_* submodules over custom equivalents
description: >-
  Use the contrib flowdrop_* submodule when one exists rather than maintaining a
  custom implementation.
tags:
  - flowdrop
  - dependencies
  - modules
kk_schema_version: 3
kk_id: practice-prefer-contrib-flowdrop-submodules
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: high
---
Where the `flowdrop` / `flowdrop_agents` contrib packages ship a submodule that
covers the need, use it instead of writing or keeping a custom one.

The `echack_flowdrop_agents`, `echack_flowdrop_node_session`, and
`echack_flowdrop_workflow_executor` modules in `web/modules/custom/` are the
worked example: each was replaced by its contrib counterpart and now exists only
so the site can uninstall them cleanly. Do not add code to them or model new
modules on them.
