---
id: 2
group: "workflow-config"
dependencies: [1]
status: "completed"
created: 2026-08-27
skills:
  - drupal-config-management
complexity_score: 3
execution_profile: "docs-and-config"
---
# Retire superseded workflows and reduce AI Review to a single button

## Objective
Remove the `content_validation`, `fact_check`, and `fact_check_fixer` workflow configs, disable `scheduled_validator`, and reduce `flowdrop_node_session.settings` `entity_operations` to the single "Assistance" operation.

## Skills Required
Drupal configuration management (config/sync YAML, drush cim/cex).

## Acceptance Criteria
- [ ] Files deleted from `config/sync/`: `flowdrop_workflow.flowdrop_workflow.content_validation.yml`, `flowdrop_workflow.flowdrop_workflow.fact_check.yml`, `flowdrop_workflow.flowdrop_workflow.fact_check_fixer.yml`.
- [ ] `config/sync/flowdrop_workflow.flowdrop_workflow.scheduled_validator.yml` has `status: false` (file kept).
- [ ] `config/sync/flowdrop_node_session.settings.yml` `entity_operations` contains exactly one entry: label `Assistance` (or similar single label), `workflow_id: content_validation_fixer`.
- [ ] No other config file references the deleted workflow ids (`grep -rl "content_validation'" config/sync` style checks; specifically check `field.field.*field_flowdrop_workflow*` default values and any `workflow_executor` `workflow_id` keys outside scheduled_validator).
- [ ] Verify: `ddev drush cim -y` succeeds with no unmet dependency errors, and `ddev drush cex -y && git status --porcelain config/sync` shows no unexpected drift.

Use your internal Todo tool to track these and keep on track.

## Technical Requirements
- Config entity deletions via file removal + `drush cim` (config sync handles removal).
- Historical `ai_content_validation_item` entities referencing deleted workflows are acceptable — the review form already renders "Unknown workflow" for a NULL entity reference.
- Do not rename `content_validation_fixer`'s machine id.
- Do not commit.

## Input Dependencies
Task 1 (unified workflow must absorb the fact-check branch before the standalone `fact_check` is deleted).

## Output Artifacts
Cleaned `config/sync/` — consumed by task 6 (docs) and final validation.

## Implementation Notes
<details>
<summary>Detailed guidance</summary>

- `scheduled_validator` launches `fact_check` and `content_validation` by string via `workflow_executor` nodes — no hard config dependency, but it would fail at runtime; that's why it is disabled (`status: false` at the top level of its YAML), not repointed. Leave its nodes untouched.
- Check `flowdrop_node_session.settings.yml` current shape first; keep its other keys intact and only trim `entity_operations`.
- The old `fact_check` prompt content needed by task 1 is preserved in git history; deletion here is safe.
- After `drush cim`, spot-check `/admin/flowdrop` (or `ddev drush php:eval` loading the workflow storage) that only the expected workflows remain enabled.
</details>
