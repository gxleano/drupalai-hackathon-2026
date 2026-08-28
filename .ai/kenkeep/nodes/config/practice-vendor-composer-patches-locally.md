---
type: practice
title: Vendor patches as local files instead of upstream commit URLs
description: >-
  Patches referenced by upstream commit URL can change hash and break composer
  install; keep them under patches/ and point composer.json at the file.
tags:
  - composer
  - patches
  - dependencies
kk_schema_version: 3
kk_id: practice-vendor-composer-patches-locally
kk_derived_from: []
kk_relates_to: []
kk_depends_on: []
kk_confidence: high
---
A patch referenced in `composer.json` by an upstream commit URL can have its
content — and therefore its hash — change upstream, which breaks
`composer install` with a hash mismatch against `patches.lock.json`.

Download the patch, commit it under `patches/`, and point `composer.json` at
that local file path instead of the URL.
