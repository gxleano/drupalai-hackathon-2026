---
type: practice
title: 'Verify service, field, route and permission names against site-api.json'
description: >-
  Check identifiers against .claude/site-api.json before using them; if it is
  not indexed there it does not exist on this site.
tags:
  - tooling
  - conventions
  - site-api
kk_schema_version: 3
kk_id: practice-verify-identifiers-against-site-api
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: medium
---
`.claude/site-api.json` is the ground-truth index of the running site: valid
service ids, real entity/bundle/field machine names and types, route names,
permissions, and enabled modules. Consult it before injecting a service,
referencing a field or route, or querying an entity. If an identifier is not
there, it does not exist — do not invent it.

Query it rather than reading it whole:

```bash
jq '.bundles."node.article".fields' .claude/site-api.json
```

Regenerate it with `.claude/tools/site-api.sh` after `drush cim`, module
install/uninstall, or field changes; `meta.generated_at` shows staleness.
`.claude/project-map.md` is the static fallback when the site is not bootable.
