---
type: practice
title: Write the phpcs rules phpcbf cannot fix correctly on the first pass
description: >-
  Docblocks, comment line length, use-statement ordering and literal t() strings
  survive phpcbf and must be right when the code is written.
tags:
  - phpcs
  - standards
  - php
  - documentation
kk_schema_version: 3
kk_id: practice-satisfy-phpcs-rules-with-no-autofixer
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: high
---
`phpcbf` auto-fixes whitespace, indentation and array syntax. It has **no
fixer** for the rules below, so they survive auto-fix and surface as errors:

- Every `.module`/`.inc`/`.install`/`.profile` file opens with a `@file` block;
  class files do not.
- Every function/method has a docblock: a one-line summary ending in `.`, then
  `@param`/`@return`/`@throws` as needed, or `{@inheritdoc}` when overriding.
  `Drupal.Commenting.FunctionComment.Missing` is the most common survivor.
- Comment lines are ≤ 80 chars (`Drupal.Files.LineLength`); code lines are
  exempt, comments are not.
- No unused `use` statements, and `use` statements are ordered alphabetically.
- Inline comments are full sentences, capitalised, ending in `.`, `!` or `?`.
- `@var` on every class property.
- `t()` arguments are literal strings — no concatenation or variables; use
  `@placeholder` / `%placeholder` args instead.
