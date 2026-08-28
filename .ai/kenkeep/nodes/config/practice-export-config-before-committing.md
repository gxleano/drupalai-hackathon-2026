---
type: practice
title: Export configuration before committing
description: >-
  Run drush cex and commit config/sync/ changes alongside the code that depends
  on them.
tags:
  - config
  - workflow
  - drush
kk_schema_version: 3
kk_id: practice-export-config-before-committing
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: high
---
Any change made through the Drupal UI has to be exported before it is
committed:

```bash
ddev drush cex
```

Review `git diff config/sync/` and commit the configuration together with the
code that depends on it. Configuration lives in a single `config/sync/`
directory — there are no config splits in this project.
