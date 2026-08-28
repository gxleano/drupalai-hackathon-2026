---
type: practice
title: Match the module's existing hook style rather than mixing the two
description: >-
  Custom code here declares hooks as .module functions; Drupal 11 prefers
  #[Hook] attributes, so convert a whole module or stay procedural — never mix
  within one.
tags:
  - hooks
  - php
  - conventions
  - drupal11
kk_schema_version: 3
kk_id: practice-match-existing-procedural-hook-style
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: medium
---
The scanned custom code declares 10 hooks as procedural `.module` functions and
zero as `#[Hook]` attribute methods, while Drupal 11 treats
`\Drupal\Core\Hook\Attribute\Hook` as the preferred style for new code.

New hooks should follow the style already used by the module being edited. If a
module is converted to attribute hooks, convert all of its hooks in the same
change — a module with both styles risks duplicate discovery. Do not block
unrelated work on converting legacy procedural hooks.

Other measured conventions in the custom code: `declare(strict_types=1)` in
every file, `final class` and `readonly` properties dominant, `AutowireTrait` in
use for plugins, and `switch` currently outnumbering `match`.
