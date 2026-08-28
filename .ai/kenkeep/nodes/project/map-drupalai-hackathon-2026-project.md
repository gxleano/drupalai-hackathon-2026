---
type: map
title: DrupalAI Hackathon 2026 project
description: >-
  A Drupal CMS 2.0.0-beta2 (Drupal 11.3, PHP 8.3) site built for the DrupalAI
  Hackathon 2026, developed in a fork and submitted as one PR per team.
tags:
  - project
  - overview
  - hackathon
kk_schema_version: 3
kk_id: map-drupalai-hackathon-2026-project
kk_derived_from: []
kk_relates_to:
  - practice-use-only-amazeeio-ai-provider
kk_depends_on: []
kk_confidence: high
---
This repository is a Drupal CMS 2.0.0-beta2 site (Drupal 11.3 on PHP 8.3) that
serves as the starting point for DrupalAI Hackathon 2026 entries.

Working model: each team forks the reference repository — without renaming it —
develops in the fork, which is the team's single source of truth, and opens a
pull request back to the reference repository. Only one PR per team is accepted,
and it must be open by the start of the pitch presentation.

The main branch is `master`; feature work goes on `feature/*` branches.

Key top-level locations: `web/` (docroot), `config/sync/` (exported
configuration), `recipes/` (Drupal CMS recipes), `patches/` (vendored composer
patches), `.ddev/` and `.devpanel/` (environment configuration).

<!-- kk:related:start -->
# Related

- Related: [practice-use-only-amazeeio-ai-provider](/ai/practice-use-only-amazeeio-ai-provider.md)
<!-- kk:related:end -->
