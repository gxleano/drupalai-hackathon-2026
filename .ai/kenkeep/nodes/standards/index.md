# kenkeep Index: standards

↑ Parent: [kenkeep](../index.md)

> kenkeep navigation: the injected body above is the root index node, the top-level catalog of branches and root-level leaves. Do not expect the whole knowledge base here; descend on demand. Read the root index node, pick one or more branches whose intent and tags match your task (several branches can be relevant), and read those branch `index.md` nodes. Descend further only where the task needs it, opening only the leaves you have confirmed are relevant. Follow each leaf's `relates_to` and `depends_on` cross edges to reach related leaves in other branches. You decide how deep to go per branch.

> This index only orients you; leaves hold the durable guidance. Open at least one relevant leaf before acting.

## Subfolders
_None._

## Conventions (how we build)
- Open [**Verify service, field, route and permission names against site-api.json**](practice-verify-identifiers-against-site-api.md) to learn about: Check identifiers against .claude/site-api.json before using them; if it is not indexed there it does not exist on this site. #tooling #conventions #site-api
- Open [**Match the module's existing hook style rather than mixing the two**](practice-match-existing-procedural-hook-style.md) to learn about: Custom code here declares hooks as .module functions; Drupal 11 prefers #\[Hook\] attributes, so convert a whole module or stay procedural — never mix within one. #hooks #php #conventions #drupal11
- Open [**Write the phpcs rules phpcbf cannot fix correctly on the first pass**](practice-satisfy-phpcs-rules-with-no-autofixer.md) to learn about: Docblocks, comment line length, use-statement ordering and literal t() strings survive phpcbf and must be right when the code is written. #phpcs #standards #php #documentation

## Components (what exists)
_None yet._

## By topic

### #conventions
- Open [**Verify service, field, route and permission names against site-api.json**](practice-verify-identifiers-against-site-api.md) — Check identifiers against .claude/site-api.json before using them; if it is not indexed there it does not exist on this site.
- Open [**Match the module's existing hook style rather than mixing the two**](practice-match-existing-procedural-hook-style.md) — Custom code here declares hooks as .module functions; Drupal 11 prefers #\[Hook\] attributes, so convert a whole module or stay procedural — never mix within one.
### #php
- Open [**Match the module's existing hook style rather than mixing the two**](practice-match-existing-procedural-hook-style.md) — Custom code here declares hooks as .module functions; Drupal 11 prefers #\[Hook\] attributes, so convert a whole module or stay procedural — never mix within one.
- Open [**Write the phpcs rules phpcbf cannot fix correctly on the first pass**](practice-satisfy-phpcs-rules-with-no-autofixer.md) — Docblocks, comment line length, use-statement ordering and literal t() strings survive phpcbf and must be right when the code is written.
### #documentation
- Open [**Write the phpcs rules phpcbf cannot fix correctly on the first pass**](practice-satisfy-phpcs-rules-with-no-autofixer.md) — Docblocks, comment line length, use-statement ordering and literal t() strings survive phpcbf and must be right when the code is written.
### #drupal11
- Open [**Match the module's existing hook style rather than mixing the two**](practice-match-existing-procedural-hook-style.md) — Custom code here declares hooks as .module functions; Drupal 11 prefers #\[Hook\] attributes, so convert a whole module or stay procedural — never mix within one.
### #hooks
- Open [**Match the module's existing hook style rather than mixing the two**](practice-match-existing-procedural-hook-style.md) — Custom code here declares hooks as .module functions; Drupal 11 prefers #\[Hook\] attributes, so convert a whole module or stay procedural — never mix within one.
### #phpcs
- Open [**Write the phpcs rules phpcbf cannot fix correctly on the first pass**](practice-satisfy-phpcs-rules-with-no-autofixer.md) — Docblocks, comment line length, use-statement ordering and literal t() strings survive phpcbf and must be right when the code is written.
### #site-api
- Open [**Verify service, field, route and permission names against site-api.json**](practice-verify-identifiers-against-site-api.md) — Check identifiers against .claude/site-api.json before using them; if it is not indexed there it does not exist on this site.
### #standards
- Open [**Write the phpcs rules phpcbf cannot fix correctly on the first pass**](practice-satisfy-phpcs-rules-with-no-autofixer.md) — Docblocks, comment line length, use-statement ordering and literal t() strings survive phpcbf and must be right when the code is written.
### #tooling
- Open [**Verify service, field, route and permission names against site-api.json**](practice-verify-identifiers-against-site-api.md) — Check identifiers against .claude/site-api.json before using them; if it is not indexed there it does not exist on this site.
- Open [**DDEV local and DevPanel cloud environments**](../project/map-ddev-and-devpanel-environments.md) — Local development runs on DDEV via ddev install (which drops the database); DevPanel provides a disposable shared cloud environment built from the .devpanel directory.