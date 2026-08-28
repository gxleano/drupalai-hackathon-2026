---
type: practice
title: 'Use only the amazee.io AI provider, with at least one Mistral model'
description: >-
  amazee.io is the sole permitted AI provider; at least one Mistral model must
  be selected for the operation types in use.
tags:
  - ai
  - provider
  - constraint
  - mistral
kk_schema_version: 3
kk_id: practice-use-only-amazeeio-ai-provider
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: high
---
All AI-powered features must go through the amazee.io provider
(`ai_provider_amazeeio`). Other providers are not permitted unless the
hackathon organizers explicitly approve them. Configure it at
`/admin/config/ai/providers/amazeeio`.

At `/admin/config/ai/ai_defaults` at least one **Mistral** model must be
selected for the relevant operation types.

When authenticating the provider, enter the registration email with the exact
casing used at registration — email matching is case-sensitive on the amazee.io
backend. The region to choose is `Play to Impact - Drupal AI Hackathon 2026`.
