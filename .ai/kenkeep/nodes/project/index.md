# kenkeep Index: project

↑ Parent: [kenkeep](../index.md)

> kenkeep navigation: the injected body above is the root index node, the top-level catalog of branches and root-level leaves. Do not expect the whole knowledge base here; descend on demand. Read the root index node, pick one or more branches whose intent and tags match your task (several branches can be relevant), and read those branch `index.md` nodes. Descend further only where the task needs it, opening only the leaves you have confirmed are relevant. Follow each leaf's `relates_to` and `depends_on` cross edges to reach related leaves in other branches. You decide how deep to go per branch.

> This index only orients you; leaves hold the durable guidance. Open at least one relevant leaf before acting.

## Subfolders
_None._

## Conventions (how we build)
_None yet._

## Components (what exists)
- Open [**Content model: Article and Utility page**](map-content-model-article-and-page.md) to learn about: Two content types — article (field_body, field_metatags, field_tags) and page (field_content, field_description, field_featured_image, field_tags). #content-model #fields #nodes
- Open [**ai_content_validation module**](map-ai-content-validation-module.md) to learn about: Custom module providing an AI content validation entity plus dashboard, review form and per-revision validation listings for nodes. #custom-module #ai #content #routes
- Open [**DDEV local and DevPanel cloud environments**](map-ddev-and-devpanel-environments.md) to learn about: Local development runs on DDEV via ddev install (which drops the database); DevPanel provides a disposable shared cloud environment built from the .devpanel directory. #environment #ddev #devpanel #tooling
- Open [**DrupalAI Hackathon 2026 project**](map-drupalai-hackathon-2026-project.md) to learn about: A Drupal CMS 2.0.0-beta2 (Drupal 11.3, PHP 8.3) site built for the DrupalAI Hackathon 2026, developed in a fork and submitted as one PR per team. #project #overview #hackathon
- Open [**echack_* modules exist only for clean uninstall**](map-echack-legacy-custom-modules.md) to learn about: echack_flowdrop_agents, echack_flowdrop_node_session and echack_flowdrop_workflow_executor are superseded by contrib and are retained only so they can be uninstalled cleanly. #custom-module #flowdrop #legacy

## By topic

### #custom-module
- Open [**ai_content_validation module**](map-ai-content-validation-module.md) — Custom module providing an AI content validation entity plus dashboard, review form and per-revision validation listings for nodes.
- Open [**echack_* modules exist only for clean uninstall**](map-echack-legacy-custom-modules.md) — echack_flowdrop_agents, echack_flowdrop_node_session and echack_flowdrop_workflow_executor are superseded by contrib and are retained only so they can be uninstalled cleanly.
### #ai
- Open [**Reconfigure the amazee.io provider after every DevPanel deployment**](../ai/practice-reconfigure-amazeeio-after-every-deploy.md) — amazee.io credentials are config-ignored, so they are never exported and must be re-entered by hand on each fresh environment.
- Open [**Use only the amazee.io AI provider, with at least one Mistral model**](../ai/practice-use-only-amazeeio-ai-provider.md) — amazee.io is the sole permitted AI provider; at least one Mistral model must be selected for the operation types in use.
- Open [**ai_content_validation module**](map-ai-content-validation-module.md) — Custom module providing an AI content validation entity plus dashboard, review form and per-revision validation listings for nodes.
### #content
- Open [**ai_content_validation module**](map-ai-content-validation-module.md) — Custom module providing an AI content validation entity plus dashboard, review form and per-revision validation listings for nodes.
### #content-model
- Open [**Content model: Article and Utility page**](map-content-model-article-and-page.md) — Two content types — article (field_body, field_metatags, field_tags) and page (field_content, field_description, field_featured_image, field_tags).
### #ddev
- Open [**DDEV local and DevPanel cloud environments**](map-ddev-and-devpanel-environments.md) — Local development runs on DDEV via ddev install (which drops the database); DevPanel provides a disposable shared cloud environment built from the .devpanel directory.
### #devpanel
- Open [**DDEV local and DevPanel cloud environments**](map-ddev-and-devpanel-environments.md) — Local development runs on DDEV via ddev install (which drops the database); DevPanel provides a disposable shared cloud environment built from the .devpanel directory.
### #environment
- Open [**DDEV local and DevPanel cloud environments**](map-ddev-and-devpanel-environments.md) — Local development runs on DDEV via ddev install (which drops the database); DevPanel provides a disposable shared cloud environment built from the .devpanel directory.
### #fields
- Open [**Content model: Article and Utility page**](map-content-model-article-and-page.md) — Two content types — article (field_body, field_metatags, field_tags) and page (field_content, field_description, field_featured_image, field_tags).
### #flowdrop
- Open [**FlowDrop module stack and node categories**](../flowdrop/map-flowdrop-module-stack.md) — The visual workflow engine in use, its submodules, and the categories that organise workflow nodes in config/sync/flowdrop_node_category.*.
- Open [**Prefer contributed flowdrop_* submodules over custom equivalents**](../flowdrop/practice-prefer-contrib-flowdrop-submodules.md) — Use the contrib flowdrop_* submodule when one exists rather than maintaining a custom implementation.
- Open [**Wire all three Entity Save inputs and shape field values as field items**](../flowdrop/practice-flowdrop-entity-save-input-contract.md) — FlowDrop's Entity Save node needs entity_id, values and trigger wired, and multi-property fields must arrive as an array of field-item objects.
### #hackathon
- Open [**DrupalAI Hackathon 2026 project**](map-drupalai-hackathon-2026-project.md) — A Drupal CMS 2.0.0-beta2 (Drupal 11.3, PHP 8.3) site built for the DrupalAI Hackathon 2026, developed in a fork and submitted as one PR per team.
### #legacy
- Open [**echack_* modules exist only for clean uninstall**](map-echack-legacy-custom-modules.md) — echack_flowdrop_agents, echack_flowdrop_node_session and echack_flowdrop_workflow_executor are superseded by contrib and are retained only so they can be uninstalled cleanly.
### #nodes
- Open [**Content model: Article and Utility page**](map-content-model-article-and-page.md) — Two content types — article (field_body, field_metatags, field_tags) and page (field_content, field_description, field_featured_image, field_tags).
### #overview
- Open [**DrupalAI Hackathon 2026 project**](map-drupalai-hackathon-2026-project.md) — A Drupal CMS 2.0.0-beta2 (Drupal 11.3, PHP 8.3) site built for the DrupalAI Hackathon 2026, developed in a fork and submitted as one PR per team.
### #project
- Open [**DrupalAI Hackathon 2026 project**](map-drupalai-hackathon-2026-project.md) — A Drupal CMS 2.0.0-beta2 (Drupal 11.3, PHP 8.3) site built for the DrupalAI Hackathon 2026, developed in a fork and submitted as one PR per team.
### #routes
- Open [**ai_content_validation module**](map-ai-content-validation-module.md) — Custom module providing an AI content validation entity plus dashboard, review form and per-revision validation listings for nodes.
### #tooling
- Open [**Verify service, field, route and permission names against site-api.json**](../standards/practice-verify-identifiers-against-site-api.md) — Check identifiers against .claude/site-api.json before using them; if it is not indexed there it does not exist on this site.
- Open [**DDEV local and DevPanel cloud environments**](map-ddev-and-devpanel-environments.md) — Local development runs on DDEV via ddev install (which drops the database); DevPanel provides a disposable shared cloud environment built from the .devpanel directory.