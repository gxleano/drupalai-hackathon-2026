---
id: 4
group: "review-ux"
dependencies: [1]
status: "completed"
created: 2026-08-27
skills:
  - drupal-forms
  - flowdrop-interrupts
complexity_score: 6
complexity_notes: "Touches the flowdrop_interrupt resolution API; resolutions are job-bound in FlowDrop 2.x and the turn must be resumed after resolving."
execution_profile: "complex-architecture"
---
# Resolve HITL interrupts inline on the AI Review page

## Objective
Replace the redirect-to-`flowdrop_interrupt.detail` flow in `AiReviewForm` with inline resolution: the pending interrupt's choice options render as radios with a Continue button directly on `/node/{nid}/ai-review`, and submitting resolves the interrupt and resumes the workflow without ever leaving the page.

## Skills Required
Drupal FormAPI; FlowDrop interrupt/session APIs (`InterruptManagerInterface`, `SessionTurnServiceInterface`).

## Acceptance Criteria
- [ ] `runWorkflow()` no longer calls `$form_state->setRedirect('flowdrop_interrupt.detail', ...)`; on `STATUS_AWAITING_INPUT` it stays on the review page (form rebuild shows the inline question).
- [ ] `buildPendingInterrupts()` renders, per pending interrupt for this node: the interrupt message, its choice options as `#type => radios` (options taken from the interrupt's payload/options), and a Continue submit button; non-choice interrupts fall back to a `#type => textfield`.
- [ ] Continue submit resolves the interrupt via the interrupt manager with the selected value, then resumes/executes the turn (`wait: TRUE`) and rebuilds the page showing either the next interrupt or the finished result message.
- [ ] No link to `flowdrop_interrupt.detail` remains in the file (`grep -c "flowdrop_interrupt.detail" web/modules/custom/ai_content_validation/src/Form/AiReviewForm.php` outputs `0`).
- [ ] Verify end-to-end with Playwright MCP: log in (`ddev drush uli`), open `/node/{nid}/ai-review`, click Run Assistance, assert URL still matches `/node/\d+/ai-review` and radios "Run Validation / Improve Article / Fact Check" are visible; choose one, click Continue, assert a completion status message appears on the same URL.

Use your internal Todo tool to track these and keep on track.

## Technical Requirements
- File: `web/modules/custom/ai_content_validation/src/Form/AiReviewForm.php` only (plus no new routes/JS).
- Inspect `flowdrop_interrupt` contrib API for the resolve call: `InterruptManagerInterface` (e.g. `resolveInterrupt()`/`respond()` — read `web/modules/contrib/flowdrop/modules/flowdrop_interrupt/src/` to find the exact method and the choice options accessor on the interrupt entity/DTO).
- Resolutions are job-bound (FlowDrop 2.x): resolve the exact pending interrupt UUID surfaced for this node's session, then re-invoke `executeTurn`/resume so the paused pipeline continues; check how the contrib resolve form (`flowdrop_interrupt.detail`) does it and mirror that server-side.
- Keep the existing session-context filtering (entity_type/entity_id match) when listing interrupts.
- Plain full-page form submits; no AJAX/modal.
- phpcs-clean; DI only.

## Input Dependencies
Task 1 (the unified workflow provides the 3-option choice interrupt this UI resolves).

## Output Artifacts
Updated `AiReviewForm.php` with inline interrupt section — task 5 builds on this file.

## Implementation Notes
<details>
<summary>Detailed guidance</summary>

- Current flow: `runWorkflow()` executes the turn; on `STATUS_AWAITING_INPUT` it redirects to the contrib detail form with `?destination=` back — the user ends up on `/admin/content` because the contrib form's own redirect wins. Deleting the redirect entirely and rendering the question inline removes that failure mode.
- The pending-interrupt section already exists (`buildPendingInterrupts()`): keep its discovery logic (per configured operation → `getPendingInterruptsForWorkflow()` → session entity-context match), replace the `Respond` link with form elements. Suggested element names: `interrupt_choice_{uuid}` and a submit `#name => 'resolve:{uuid}'` handled by a new `::resolveInterrupt` submit callback.
- In the resolve callback: load the interrupt by UUID via the manager, call the contrib resolve method with the selected option value (for `choice_input` the resolution value is the option `value`, e.g. `validate`), then call `$this->turnService->executeTurn($session_id, '', new TurnOptions(wait: TRUE))` or the dedicated resume method if the manager resumes automatically — read the contrib `InterruptManager` to see whether resolving triggers resumption itself; do not resume twice.
- After resolution the workflow may either finish (validation item saved — the page's validations list shows it) or raise another interrupt (rebuild shows it). `$form_state->setRebuild()` or a redirect-to-self both work; redirect-to-self (`ai_content_validation.node_review`) gives a clean GET.
- The interrupt's options: `choice_input` stores its options in the node config, and the interrupt DTO/entity carries the payload — find the accessor (likely `getData()`/`getPayload()['options']`). If options are absent on the interrupt, fall back to loading them from the workflow's `choice_input` node config.
</details>
