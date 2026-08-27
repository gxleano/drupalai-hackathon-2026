---
id: 6
group: "docs"
dependencies: [2, 5]
status: "completed"
created: 2026-08-27
skills:
  - technical-writing
complexity_score: 1
execution_profile: "docs-and-config"
---
# Update ai_content_validation documentation

## Objective
Update `web/modules/custom/ai_content_validation/AI_CONTEXT.md` to describe the unified Content Assistance workflow, the `superseded` status, supersede-on-save behavior, and inline interrupt resolution; confirm root `CLAUDE.md`/`AGENTS.md` need no changes.

## Skills Required
Technical writing.

## Acceptance Criteria
- [ ] `AI_CONTEXT.md` documents: single "Content Assistance" workflow (machine id `content_validation_fixer`) with Validate/Improve/Fact Check HITL branches; retired workflows (`content_validation`, `fact_check`, `fact_check_fixer`) and disabled `scheduled_validator`; the structured result contract (`score`/`summary`/`suggestions`); statuses `pending|done|ignored|superseded` and the supersede-on-save rule; inline interrupt resolution on `/node/{nid}/ai-review`.
- [ ] `grep -n "fact_check\|content_validation" CLAUDE.md AGENTS.md` at repo root shows no stale references to the retired workflow ids that would need updating (or they are updated).
- [ ] Verify: `grep -c "superseded" web/modules/custom/ai_content_validation/AI_CONTEXT.md` outputs ≥ 1.

Use your internal Todo tool to track these and keep on track.

## Technical Requirements
Markdown only; keep the existing AI_CONTEXT.md structure and tone; concise.

## Input Dependencies
Tasks 2 and 5 completed (documentation reflects final state).

## Output Artifacts
Updated `AI_CONTEXT.md`.

## Implementation Notes
<details>
<summary>Detailed guidance</summary>

Read the current AI_CONTEXT.md first and edit sections in place rather than rewriting. Mention that headless/scheduled mode is deferred (scheduled_validator disabled) so future agents don't "fix" it accidentally.
</details>
