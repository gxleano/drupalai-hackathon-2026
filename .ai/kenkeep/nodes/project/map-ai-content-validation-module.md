---
type: map
title: ai_content_validation module
description: >-
  Custom module providing an AI content validation entity plus dashboard, review
  form and per-revision validation listings for nodes.
tags:
  - custom-module
  - ai
  - content
  - routes
kk_schema_version: 3
kk_id: map-ai-content-validation-module
kk_derived_from: []
kk_relates_to:
  - map-content-model-article-and-page
kk_depends_on: []
kk_confidence: medium
---
`web/modules/custom/ai_content_validation/` holds the
`ai_content_validation_item` content entity together with the UI for reviewing
and tracking AI-proposed content validations against node revisions.

Routes it provides:

| Route | Path | Handler |
|---|---|---|
| `ai_content_validation.node_review` | `/node/{node}/ai-review` | `Form\AiReviewForm` |
| `ai_content_validation.ai_node_validations` | `/node/{node}/revisions/{node_revision}/validations` | `Controller\AiNodeValidationsController::revisionValidations` |
| `ai_content_validation.main_dashboard` | — | dashboard |
| `ai_content_validation.settings` | `/admin/config/system/ai_content_validation/settings` | `Form\SettingsForm` |
| `entity.ai_content_validation_item.settings` | `admin/structure/ai-content-validation-item` | `Form\AiValidationsSettingsForm` |

The module ships an `AI_CONTEXT.md`; read it before exploring the code. Note
that `web/modules/custom/**/AI_CONTEXT.md` is gitignored, so it is not shared
across clones.

<!-- kk:related:start -->
# Related

- Related: [map-content-model-article-and-page](/project/map-content-model-article-and-page.md)
<!-- kk:related:end -->
