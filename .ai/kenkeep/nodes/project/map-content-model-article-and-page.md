---
type: map
title: 'Content model: Article and Utility page'
description: >-
  Two content types — article (field_body, field_metatags, field_tags) and page
  (field_content, field_description, field_featured_image, field_tags).
tags:
  - content-model
  - fields
  - nodes
kk_schema_version: 3
kk_id: map-content-model-article-and-page
kk_derived_from: []
kk_relates_to:
  - practice-verify-identifiers-against-site-api
kk_depends_on: []
kk_confidence: medium
---
`article` — Article, for article pages:

- `field_body` (text_long)
- `field_metatags` (metatag)
- `field_tags` (entity_reference)

`page` — Utility page, for static content such as a privacy policy:

- `field_content` (text_long)
- `field_description` (string_long, required)
- `field_featured_image` (entity_reference)
- `field_tags` (entity_reference)

Roles are `administrator`, `anonymous`, `authenticated` and `content_editor`.

When changing this, verify the field machine names against
`.claude/site-api.json` rather than this node — the site index is regenerated
from the live site, this is a snapshot.

<!-- kk:related:start -->
# Related

- Related: [practice-verify-identifiers-against-site-api](/standards/practice-verify-identifiers-against-site-api.md)
<!-- kk:related:end -->
