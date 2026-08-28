# kenkeep Index: config

↑ Parent: [kenkeep](../index.md)

> kenkeep navigation: the injected body above is the root index node, the top-level catalog of branches and root-level leaves. Do not expect the whole knowledge base here; descend on demand. Read the root index node, pick one or more branches whose intent and tags match your task (several branches can be relevant), and read those branch `index.md` nodes. Descend further only where the task needs it, opening only the leaves you have confirmed are relevant. Follow each leaf's `relates_to` and `depends_on` cross edges to reach related leaves in other branches. You decide how deep to go per branch.

> This index only orients you; leaves hold the durable guidance. Open at least one relevant leaf before acting.

## Subfolders
_None._

## Conventions (how we build)
- Open [**Export configuration before committing**](practice-export-config-before-committing.md) to learn about: Run drush cex and commit config/sync/ changes alongside the code that depends on them. #config #workflow #drush
- Open [**Vendor patches as local files instead of upstream commit URLs**](practice-vendor-composer-patches-locally.md) to learn about: Patches referenced by upstream commit URL can change hash and break composer install; keep them under patches/ and point composer.json at the file. #composer #patches #dependencies

## Components (what exists)
_None yet._

## By topic

### #composer
- Open [**Vendor patches as local files instead of upstream commit URLs**](practice-vendor-composer-patches-locally.md) — Patches referenced by upstream commit URL can change hash and break composer install; keep them under patches/ and point composer.json at the file.
### #config
- Open [**Update FlowDrop workflow plugin ids after renaming a module**](../flowdrop/practice-update-workflow-plugin-ids-after-module-rename.md) — Renaming a flowdrop_* module leaves stale executor/plugin ids in config/sync/flowdrop_workflow.*.yml that must be updated and re-exported.
- Open [**Export configuration before committing**](practice-export-config-before-committing.md) — Run drush cex and commit config/sync/ changes alongside the code that depends on them.
- Open [**Reconfigure the amazee.io provider after every DevPanel deployment**](../ai/practice-reconfigure-amazeeio-after-every-deploy.md) — amazee.io credentials are config-ignored, so they are never exported and must be re-entered by hand on each fresh environment.
### #dependencies
- Open [**Prefer contributed flowdrop_* submodules over custom equivalents**](../flowdrop/practice-prefer-contrib-flowdrop-submodules.md) — Use the contrib flowdrop_* submodule when one exists rather than maintaining a custom implementation.
- Open [**Vendor patches as local files instead of upstream commit URLs**](practice-vendor-composer-patches-locally.md) — Patches referenced by upstream commit URL can change hash and break composer install; keep them under patches/ and point composer.json at the file.
### #drush
- Open [**Export configuration before committing**](practice-export-config-before-committing.md) — Run drush cex and commit config/sync/ changes alongside the code that depends on them.
### #patches
- Open [**Vendor patches as local files instead of upstream commit URLs**](practice-vendor-composer-patches-locally.md) — Patches referenced by upstream commit URL can change hash and break composer install; keep them under patches/ and point composer.json at the file.
### #workflow
- Open [**FlowDrop module stack and node categories**](../flowdrop/map-flowdrop-module-stack.md) — The visual workflow engine in use, its submodules, and the categories that organise workflow nodes in config/sync/flowdrop_node_category.*.
- Open [**Wire all three Entity Save inputs and shape field values as field items**](../flowdrop/practice-flowdrop-entity-save-input-contract.md) — FlowDrop's Entity Save node needs entity_id, values and trigger wired, and multi-property fields must arrive as an array of field-item objects.
- Open [**Export configuration before committing**](practice-export-config-before-committing.md) — Run drush cex and commit config/sync/ changes alongside the code that depends on them.