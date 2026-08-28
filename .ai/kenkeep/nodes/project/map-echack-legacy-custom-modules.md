---
type: map
title: echack_* modules exist only for clean uninstall
description: >-
  echack_flowdrop_agents, echack_flowdrop_node_session and
  echack_flowdrop_workflow_executor are superseded by contrib and are retained
  only so they can be uninstalled cleanly.
tags:
  - custom-module
  - flowdrop
  - legacy
kk_schema_version: 3
kk_id: map-echack-legacy-custom-modules
kk_derived_from: []
kk_relates_to:
  - practice-prefer-contrib-flowdrop-submodules
kk_depends_on: []
kk_confidence: high
---
Three modules under `web/modules/custom/` are retained solely so the site can
uninstall them cleanly; each was replaced by a contributed equivalent:

| Legacy module | Replaced by |
|---|---|
| `echack_flowdrop_agents` | `flowdrop_agents` |
| `echack_flowdrop_node_session` | `flowdrop_node_session` |
| `echack_flowdrop_workflow_executor` | `flowdrop_workflow_executor` (from `drupal/flowdrop`) |

Do not add features to them or use them as a model for new modules.

<!-- kk:related:start -->
# Related

- Related: [practice-prefer-contrib-flowdrop-submodules](/flowdrop/practice-prefer-contrib-flowdrop-submodules.md)
<!-- kk:related:end -->
