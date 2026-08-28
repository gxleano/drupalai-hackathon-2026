---
type: map
title: DDEV local and DevPanel cloud environments
description: >-
  Local development runs on DDEV via ddev install (which drops the database);
  DevPanel provides a disposable shared cloud environment built from the
  .devpanel directory.
tags:
  - environment
  - ddev
  - devpanel
  - tooling
kk_schema_version: 3
kk_id: map-ddev-and-devpanel-environments
kk_derived_from: []
kk_relates_to:
  - practice-reconfigure-amazeeio-after-every-deploy
kk_depends_on: []
kk_confidence: high
---
**Local (DDEV).** Bring the site up with `ddev start`, install it with
`ddev install`, then open it with `ddev launch`.

The install command lives at `.ddev/commands/web/install` and runs, in order:
`composer install`; `drush si --existing-config -y`; `drush cr`; `drush uli`.
Note that the site is installed **from the exported configuration**, not from a
database dump — and that the `drush si` step drops every table in the database
first. Treat `ddev install` as destructive to local content.

**Cloud (DevPanel).** One project per team, created by the team's tech lead from
the fork's `master` branch. It is built automatically from the `.devpanel/`
directory, whose `init.sh` generates a hash salt and then delegates to the same
DDEV install script. DevPanel environments are disposable; the git fork is not.
Drush is run from the browser VS Code terminal reached via **Open Application**.

If the automatic build fails, the manual equivalent is `composer install`
followed by `drush -y si --existing-config` with an explicit `--db-url` built
from the `DB_*` environment variables.

<!-- kk:related:start -->
# Related

- Related: [practice-reconfigure-amazeeio-after-every-deploy](/ai/practice-reconfigure-amazeeio-after-every-deploy.md)
<!-- kk:related:end -->
