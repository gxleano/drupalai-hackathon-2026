---
id: 5
group: "review-ux"
dependencies: [3, 4]
status: "completed"
created: 2026-08-27
skills:
  - drupal-forms
complexity_score: 4
execution_profile: "standard-implementation"
---
# Prominent quality score and grouped validation results

## Objective
Rework the results section of `AiReviewForm` so each validation item shows its quality score prominently, and items are grouped under Pending / Done / Ignored headings with superseded items hidden (or collapsed last), newest pending open by default.

## Skills Required
Drupal FormAPI / render arrays.

## Acceptance Criteria
- [ ] `buildValidations()` parses `field_validation_result` and, when a numeric `score` is present, renders it prominently at the top of the item (e.g. large "82 / 100" with a label) before the summary.
- [ ] Items are grouped in order: Pending (open, newest first), Done, Ignored — each under a visible heading; items with status `superseded` are not shown in these groups (either omitted or in a single collapsed "Superseded" details at the bottom).
- [ ] Only the newest pending item is `#open => TRUE`; everything else is collapsed.
- [ ] Raw JSON is never rendered: when `field_validation_result` parses as JSON, only `summary`, `score`, and the suggestions table are shown; unparseable legacy values fall back to plain text as today.
- [ ] The existing tableselect + Apply/Ignore machinery keeps working for pending items (Apply still creates a revision and sets status `done`).
- [ ] Verify with Playwright MCP: after a Validate run on a node, screenshot `/node/{nid}/ai-review` and assert the score element and the "Pending" group heading are present, and that no `{"` raw-JSON text appears in the page body.

Use your internal Todo tool to track these and keep on track.

## Technical Requirements
- File: `web/modules/custom/ai_content_validation/src/Form/AiReviewForm.php`.
- All render arrays carry appropriate `#cache` metadata where new cacheable elements are added (the form itself is uncacheable per request; entity list already queried fresh).
- Score rendering: simple markup with an inline class or `<strong>`-scale heading — no new CSS library unless one already exists in the module (check `*.libraries.yml`; the module has none → keep it plain markup).
- Keep `buildRecentResponses()` only as an error-visibility fallback; it must not duplicate items that now land as validation entities.

## Input Dependencies
Task 3 (`superseded` status exists), Task 4 (same file already reworked — apply on top of it).

## Output Artifacts
Final `AiReviewForm.php` presentation — consumed by task 6 (docs) and final validation.

## Implementation Notes
<details>
<summary>Detailed guidance</summary>

- Grouping: build three sub-arrays keyed by status while iterating the loaded items (already sorted `created DESC`), then emit `#type => 'details'` (or plain `h3` markup + children) per group. Simplest: a container per group with `#markup` heading; keep each item as the existing `details` element.
- Score extraction: `$parsed['score'] ?? null`; render only for `is_numeric`. The unified workflow (task 1) emits `score` on the validate branch and `null` elsewhere.
- `field_validation_result` may arrive as an object serialized by normalize or as a JSON string — `json_decode` the string form; if the decoded value is itself a string (double-encoded legacy fact-check items), decode once more before giving up.
- Superseded: filter them out of the main query display but keep the query limit sensible (`range(0, 30)`).
</details>
