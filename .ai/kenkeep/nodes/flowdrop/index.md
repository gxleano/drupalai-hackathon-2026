# kenkeep Index: flowdrop

↑ Parent: [kenkeep](../index.md)

> kenkeep navigation: the injected body above is the root index node, the top-level catalog of branches and root-level leaves. Do not expect the whole knowledge base here; descend on demand. Read the root index node, pick one or more branches whose intent and tags match your task (several branches can be relevant), and read those branch `index.md` nodes. Descend further only where the task needs it, opening only the leaves you have confirmed are relevant. Follow each leaf's `relates_to` and `depends_on` cross edges to reach related leaves in other branches. You decide how deep to go per branch.

> This index only orients you; leaves hold the durable guidance. Open at least one relevant leaf before acting.

## Subfolders
_None._

## Conventions (how we build)
- Open [**Prefer contributed flowdrop_* submodules over custom equivalents**](practice-prefer-contrib-flowdrop-submodules.md) to learn about: Use the contrib flowdrop_* submodule when one exists rather than maintaining a custom implementation. #flowdrop #dependencies #modules
- Open [**Update FlowDrop workflow plugin ids after renaming a module**](practice-update-workflow-plugin-ids-after-module-rename.md) to learn about: Renaming a flowdrop_* module leaves stale executor/plugin ids in config/sync/flowdrop_workflow.*.yml that must be updated and re-exported. #flowdrop #config #refactoring
- Open [**Wire all three Entity Save inputs and shape field values as field items**](practice-flowdrop-entity-save-input-contract.md) to learn about: FlowDrop's Entity Save node needs entity_id, values and trigger wired, and multi-property fields must arrive as an array of field-item objects. #flowdrop #entity #workflow #gotcha

## Components (what exists)
- Open [**FlowDrop module stack and node categories**](map-flowdrop-module-stack.md) to learn about: The visual workflow engine in use, its submodules, and the categories that organise workflow nodes in config/sync/flowdrop_node_category.*. #flowdrop #modules #workflow #ai

## By topic

### #flowdrop
- Open [**FlowDrop module stack and node categories**](map-flowdrop-module-stack.md) — The visual workflow engine in use, its submodules, and the categories that organise workflow nodes in config/sync/flowdrop_node_category.*.
- Open [**Prefer contributed flowdrop_* submodules over custom equivalents**](practice-prefer-contrib-flowdrop-submodules.md) — Use the contrib flowdrop_* submodule when one exists rather than maintaining a custom implementation.
- Open [**Wire all three Entity Save inputs and shape field values as field items**](practice-flowdrop-entity-save-input-contract.md) — FlowDrop's Entity Save node needs entity_id, values and trigger wired, and multi-property fields must arrive as an array of field-item objects.
### #modules
- Open [**Prefer contributed flowdrop_* submodules over custom equivalents**](practice-prefer-contrib-flowdrop-submodules.md) — Use the contrib flowdrop_* submodule when one exists rather than maintaining a custom implementation.
- Open [**FlowDrop module stack and node categories**](map-flowdrop-module-stack.md) — The visual workflow engine in use, its submodules, and the categories that organise workflow nodes in config/sync/flowdrop_node_category.*.
### #workflow
- Open [**FlowDrop module stack and node categories**](map-flowdrop-module-stack.md) — The visual workflow engine in use, its submodules, and the categories that organise workflow nodes in config/sync/flowdrop_node_category.*.
- Open [**Wire all three Entity Save inputs and shape field values as field items**](practice-flowdrop-entity-save-input-contract.md) — FlowDrop's Entity Save node needs entity_id, values and trigger wired, and multi-property fields must arrive as an array of field-item objects.
- Open [**Export configuration before committing**](../config/practice-export-config-before-committing.md) — Run drush cex and commit config/sync/ changes alongside the code that depends on them.
### #ai
- Open [**Reconfigure the amazee.io provider after every DevPanel deployment**](../ai/practice-reconfigure-amazeeio-after-every-deploy.md) — amazee.io credentials are config-ignored, so they are never exported and must be re-entered by hand on each fresh environment.
- Open [**Use only the amazee.io AI provider, with at least one Mistral model**](../ai/practice-use-only-amazeeio-ai-provider.md) — amazee.io is the sole permitted AI provider; at least one Mistral model must be selected for the operation types in use.
- Open [**ai_content_validation module**](../project/map-ai-content-validation-module.md) — Custom module providing an AI content validation entity plus dashboard, review form and per-revision validation listings for nodes.
### #config
- Open [**Update FlowDrop workflow plugin ids after renaming a module**](practice-update-workflow-plugin-ids-after-module-rename.md) — Renaming a flowdrop_* module leaves stale executor/plugin ids in config/sync/flowdrop_workflow.*.yml that must be updated and re-exported.
- Open [**Export configuration before committing**](../config/practice-export-config-before-committing.md) — Run drush cex and commit config/sync/ changes alongside the code that depends on them.
- Open [**Reconfigure the amazee.io provider after every DevPanel deployment**](../ai/practice-reconfigure-amazeeio-after-every-deploy.md) — amazee.io credentials are config-ignored, so they are never exported and must be re-entered by hand on each fresh environment.
### #dependencies
- Open [**Prefer contributed flowdrop_* submodules over custom equivalents**](practice-prefer-contrib-flowdrop-submodules.md) — Use the contrib flowdrop_* submodule when one exists rather than maintaining a custom implementation.
- Open [**Vendor patches as local files instead of upstream commit URLs**](../config/practice-vendor-composer-patches-locally.md) — Patches referenced by upstream commit URL can change hash and break composer install; keep them under patches/ and point composer.json at the file.
### #entity
- Open [**Wire all three Entity Save inputs and shape field values as field items**](practice-flowdrop-entity-save-input-contract.md) — FlowDrop's Entity Save node needs entity_id, values and trigger wired, and multi-property fields must arrive as an array of field-item objects.
### #gotcha
- Open [**Wire all three Entity Save inputs and shape field values as field items**](practice-flowdrop-entity-save-input-contract.md) — FlowDrop's Entity Save node needs entity_id, values and trigger wired, and multi-property fields must arrive as an array of field-item objects.
### #refactoring
- Open [**Update FlowDrop workflow plugin ids after renaming a module**](practice-update-workflow-plugin-ids-after-module-rename.md) — Renaming a flowdrop_* module leaves stale executor/plugin ids in config/sync/flowdrop_workflow.*.yml that must be updated and re-exported.