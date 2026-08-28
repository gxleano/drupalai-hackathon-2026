---
type: practice
title: Reconfigure the amazee.io provider after every DevPanel deployment
description: >-
  amazee.io credentials are config-ignored, so they are never exported and must
  be re-entered by hand on each fresh environment.
tags:
  - ai
  - provider
  - config
  - deployment
kk_schema_version: 3
kk_id: practice-reconfigure-amazeeio-after-every-deploy
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: high
---
The amazee.io credentials are stored in the Keys module
(`/admin/config/system/keys`) and would be exported in **clear text**, so these
entities are excluded via `config/sync/config_ignore.settings.yml`:

- `ai_provider_amazeeio.settings`
- `key.key.amazeeio_ai`
- `key.key.amazeeio_ai_database`

Consequence: `drush cex` never captures them and `drush cim` never restores
them. After any DevPanel deployment — or any fresh install — walk the provider
authentication flow again manually. Do not attempt to "fix" this by removing the
config-ignore entries.
