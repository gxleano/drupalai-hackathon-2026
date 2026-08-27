---
id: 3
group: "validation-data"
dependencies: []
status: "completed"
created: 2026-08-27
skills:
  - drupal-module-development
complexity_score: 4
execution_profile: "standard-implementation"
---
# Supersede prior pending validation items on save

## Objective
Extend the `flowdrop_node_processor_validation_save` node processor (ai_content_validation module) so that saving a new validation item marks all prior `pending` items for the same node + workflow as `superseded`, killing duplicate noise.

## Skills Required
Drupal 11 custom module development (entity queries, node processor plugins).

## Acceptance Criteria
- [ ] `superseded` is an allowed value for `field_validation_status` (added wherever the allowed values live — field storage config in `config/sync` or the entity definition).
- [ ] After the processor saves a new item, all other `ai_content_validation_item` entities with the same `field_content_revision.target_id` AND same `field_flowdrop_workflow` value AND status `pending` are set to `superseded`. Items with status `done` or `ignored` are never modified.
- [ ] Entity query uses `accessCheck(FALSE)` with a justifying comment (system-level state transition).
- [ ] Verify: `ddev drush php:eval` script that creates two pending items for the same nid+workflow via the processor path (or directly then invokes the supersede logic), then asserts exactly one `pending` remains and the older one is `superseded` — command output shows the assertion passing.

Use your internal Todo tool to track these and keep on track.

## Technical Requirements
- File: the FlowDropNodeProcessor plugin under `web/modules/custom/ai_content_validation/src/Plugin/FlowDropNodeProcessor/` that provides `flowdrop_node_processor_validation_save`.
- Constructor-injected `EntityTypeManagerInterface` (no `\Drupal::` in the class) — follow existing DI in the plugin.
- Exclude the just-saved entity by id from the supersede query.
- If `field_validation_status` is a list_string field with fixed allowed values in config, update the field storage YAML in `config/sync` and note that `drush cim` applies it; if allowed values are unconstrained (plain string), no config change is needed — verify which case applies first.
- phpcs-clean per project standards (docblocks, comment punctuation, line length ≤80 for comments).

## Input Dependencies
None (independent of workflow YAML changes).

## Output Artifacts
Updated validation-save processor + possibly field config — consumed by task 5 (UI grouping renders `superseded`).

## Implementation Notes
<details>
<summary>Detailed guidance</summary>

- Read the existing processor first to find where the entity is saved; add the supersede step immediately after a successful save, inside the same `process()` flow. Keep it inline — no new service class (ponytail).
- Query shape:
  ```php
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_content_revision.target_id', $target_id)
    ->condition('field_flowdrop_workflow', $workflow_id)
    ->condition('field_validation_status', 'pending')
    ->condition('id', $saved->id(), '<>')
    ->execute();
  ```
  then loadMultiple + set status + save each.
- Output of the processor must stay JSON-serializable (FlowDrop 2.x contract) — return scalars/arrays only; optionally add a `superseded_count` key to the output.
- Check `web/modules/custom/ai_content_validation/AI_CONTEXT.md` and the entity class `AiValidations.php` for where statuses are defined before deciding on the config vs code change for the allowed value.
</details>
