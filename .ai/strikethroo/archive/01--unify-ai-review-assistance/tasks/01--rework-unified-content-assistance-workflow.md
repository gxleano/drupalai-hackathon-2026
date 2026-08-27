---
id: 1
group: "workflow-config"
dependencies: []
status: "completed"
created: 2026-08-27
skills:
  - flowdrop-workflows
complexity_score: 6
complexity_notes: "Hand-editing FlowDrop 2.x stored YAML: branch handles, edge rewiring, and prompt contract must all line up; verified only at runtime/diagnose since config sync bypasses validation."
execution_profile: "complex-architecture"
---
# Rework content_validation_fixer into the unified Content Assistance workflow

## Objective
Edit `config/sync/flowdrop_workflow.flowdrop_workflow.content_validation_fixer.yml` in place so it becomes the single "Content Assistance" workflow: three HITL choices (Validate / Improve / Fact Check), no placeholder NOTICE detour, all branches following the shared 10-EU-guidelines rubric and structured JSON result contract.

## Skills Required
FlowDrop 2.x stored workflow format authoring (nodes, edges, handle naming, gateway branches, HITL choice_input).

## Acceptance Criteria
- [x] `choice_input.1` has three options: `validate` ("Run Validation"), `improve` ("Improve Article"), `fact_check` ("Fact Check"); `switch_gateway.1` has matching `validate`, `improve`, `fact_check` branches.
- [x] Nodes `prompt_template.7` and `chat_output.4` and all edges touching them are deleted; `switch_gateway.1-output-improve` now triggers `prompt_template.3-input-trigger` directly.
- [x] New fact-check branch exists: a `flowdrop_ai_provider_chat` node (model `mistral-large-latest`, temperature 0) whose system prompt is the fact-check prompt from the old `fact_check` workflow updated to the shared result contract; triggered from `switch_gateway.1-output-fact_check`; message fed from `data_to_json.1-output-json`; response wired into `json_to_data.5-input-json` (joining the existing normalize → save tail).
- [x] All three branch system prompts enumerate the same 10 EU guidelines (Accuracy & Evidence; Clarity & Plain Language; Neutrality & Objectivity; Source Transparency; Legal & Policy Consistency; Audience Relevance; Structure & Coherence; Completeness & Context; Inclusivity & Language Ethics; Practical Value) and demand `field_validation_result` as an object `{"score": <1-100 integer, validate branch only, null otherwise>, "summary": "...", "suggestions": [{field,label,current,suggested,reason}]}` with `field_validation_status: "pending"`.
- [x] Workflow `label` changed to `'Content Assistance'`; machine `id` stays `content_validation_fixer`; `dependencies.config` list updated (no stale node types, alphabetical).
- [x] Verify: `ddev drush cim -y && ddev drush flowdrop:workflow:diagnose content_validation_fixer` reports no findings, and `grep -c "Automatic Tagging" config/sync/flowdrop_workflow.flowdrop_workflow.content_validation_fixer.yml` outputs `0`.

Use your internal Todo tool to track these and keep on track.

## Technical Requirements
- FlowDrop 2.x slim stored format: per node only `id`, `position`, `data.label`, `data.config`, `data.metadata.node_type_id`; edge handles are exact `{nodeId}-output-{port}` / `{nodeId}-input-{port}`; branch handles use the branch `name` verbatim (`switch_gateway.1-output-fact_check`).
- Two chat nodes already feed `json_to_data.5` (edges `edge-chat3-json2`, `edge-chat2-json5`) — add the fact-check chat as a third source following the same pattern.
- Keep amazee.io provider + Mistral models only. Temperature 0 for validate and fact-check branches; improve branch keeps 0.7.
- Do NOT hand-author `parameter_schema`/`schema_version` (leave as-is).
- Do NOT commit; export nothing beyond this file edit (config already lives in git).

## Input Dependencies
None. Reference prompts: `config/sync/flowdrop_workflow.flowdrop_workflow.content_validation.yml` (10 guidelines + score) and `config/sync/flowdrop_workflow.flowdrop_workflow.fact_check.yml` (fact-check prompt) — read them BEFORE task 2 deletes them (they are in git history regardless).

## Output Artifacts
Updated `config/sync/flowdrop_workflow.flowdrop_workflow.content_validation_fixer.yml` — consumed by tasks 2, 4, 5.

## Implementation Notes
<details>
<summary>Detailed guidance</summary>

Current relevant wiring in the file (verify before editing):
- Improve path today: `switch_gateway.1-output-improve` → `chat_output.4-input-trigger`; `prompt_template.7-output-prompt` → `chat_output.4-input-message`; `chat_output.4-output-trigger` → `prompt_template.3-input-trigger`. Delete `prompt_template.7`, `chat_output.4`, and these three edges; add one trigger edge `switch_gateway.1-output-improve` → `prompt_template.3-input-trigger` (edgeType trigger, like the existing `switch_gateway.1-output-validate` → `flowdrop_ai_provider_chat.3-input-trigger` edge).
- Validate path (`flowdrop_ai_provider_chat.3`) already works: keep, but update its system prompt to include the 10 guidelines list verbatim from `content_validation` and the `score` key in the example output; set `"field_validation_status":"pending"` (already is) and label prefix e.g. "Validation: ...".
- Improve chat (`flowdrop_ai_provider_chat.2`): prompt already lists standards loosely — replace the parenthetical list with the same 10-guideline enumeration; add `"score": null` to its example output.
- New node id suggestion: `flowdrop_ai_provider_chat.4` at a free position (e.g. x: 2480, y: 1200). Config mirrors chat.3 (model mistral-large-latest, temperature 0, maxTokens 2000). System prompt: fact_check workflow's prompt, but change `field_validation_result` from an escaped-string to a JSON OBJECT with `score: null`, `summary`, `suggestions`, and `"field_flowdrop_workflow":"content_validation_fixer"`, `"label":"Fact Check: ..."`.
- Edges to add: trigger `switch_gateway.1-output-fact_check` → `flowdrop_ai_provider_chat.4-input-trigger`; data `data_to_json.1-output-json` → `flowdrop_ai_provider_chat.4-input-message`; data `flowdrop_ai_provider_chat.4-output-response` → `json_to_data.5-input-json`. Plain edge entries (id/source/target/sourceHandle/targetHandle) suffice — style/markerEnd are cosmetic.
- switch_gateway.1 `branches` config gains `- {name: fact_check, value: fact_check}`. Branch matching is on `value` from choice_input's `selected` output; handle name = branch `name`.
- `dependencies.config` gains nothing new (all node types already listed); confirm nothing became unused (`chat_output` is still used by `chat_output.3`; `prompt_template` still used by .3/.6).
- After editing run the verification commands in Acceptance Criteria. If DDEV isn't running, `ddev start` first.
</details>
