# Intake — Operational Spec

## 1. Overview

"Intake" in this codebase is the **bounded, turn-based, LLM-mediated conversation** that hydrates a `ProposalSubmission`'s `canonical_data` from free-text user messages. It is orchestrated by `DeterministicIntakeService` (`src/Domain/Intake/DeterministicIntakeService.php`), routed through the `StructuredIntakeClientInterface` abstraction, and implemented today by `AnthropicStructuredIntakeClient`. The HTTP surface is two routes under `/submissions/{submission}/intake` (GET + POST), wired in `AppServiceProvider::routes()` and served by `IntakeController`. Intake is the first canonical-data source; `SubmissionController::updateCanonical` and `applyResearchDraft` are the two other code paths that write into the same fields.

## 2. Current contract

### 2a. Bounded question plan (the "9 fields")

Two parallel structures define the plan today — both must stay in sync:

- **Class constant** `DeterministicIntakeService::QUESTION_PLAN` at `src/Domain/Intake/DeterministicIntakeService.php:13` — full list with `path`, `priority`, `question`, `example` per entry. Drives `nextQuestionForData()` ordering and `buildAssistantMessage()`.
- **Allowlist array** passed to `AnthropicStructuredIntakeClient` via `$allowedPaths` constructor argument, wired at `src/Provider/AppServiceProvider.php:82-91`. Constrains which paths the LLM may emit patches for.

The 9 field paths (order as declared in `QUESTION_PLAN`):

1. `business.identity.business_name`
2. `business.operations.launch_timeline`
3. `business.market.customers`
4. `funding_request.support_rationale`
5. `business.market.marketing_plan`
6. `business.operations.location`
7. `career_plan.three_year_plan`
8. `applicant.contact.email`
9. `applicant.contact.telephone`

All nine are `priority: 'core'`. No expanded/optional tier exists in code. Order is enforced: `nextQuestionForData()` iterates `QUESTION_PLAN` linearly and returns the first path whose value is missing (`DeterministicIntakeService.php:~153`). No branching — the plan is a flat sequence.

### 2b. LLM invocation path

`handleTurn()` (`DeterministicIntakeService.php:~105`) calls `$this->intakeClient->processTurn($message, $activeQuestion, $canonicalData, $unresolved, $researchContext)`. Contract (`StructuredIntakeClientInterface.php:7-25`):

- Returns `?array` with keys `assistant_message`, `patches`, `unresolved_hints`, `research_requests`, or `null` to signal "fall back to deterministic heuristic."
- `processTurn()` return shape is normalized on both sides; callers tolerate `null`.

`AnthropicStructuredIntakeClient` (`src/Domain/Intake/AnthropicStructuredIntakeClient.php`) behaviors:

- `isEnabled()` checks `apiKey` and `model` are non-empty (`AnthropicStructuredIntakeClient.php:27-30`). Disabled → `processTurn()` returns `null` at line `:34-36`.
- `sendRequest()` uses `curl`; on non-200, non-string response, or non-array JSON decode it returns `null` (`AnthropicStructuredIntakeClient.php:207-214`). There is no retry, no logging of the failure.
- Malformed or absent JSON payload in the Anthropic content block → `processTurn()` returns `null`. The service then falls through to `$this->extractPatches($trimmedMessage, $activeQuestion)` at `DeterministicIntakeService.php:139` — a regex-based heuristic that scans the user message for `path: value` pairs.
- LLM "refusal" is indistinguishable from malformed JSON in current code: both paths collapse to `null` → deterministic fallback. No explicit refusal detection.

### 2c. Canonical promotion from intake turns

`handleTurn()` writes (atomic, single `$submissionStorage->save()` at `DeterministicIntakeService.php:184`):

| Field | Source | Persisted |
|---|---|---|
| `canonical_data` | merged from `$patches` via `setValueAtPath()` | yes |
| `intake_transcript` | appended `role:user` turn with `created_at` | yes |
| `conversation_summary` | derived via `summarizeTranscript()` | yes |
| `unresolved_items` | `deriveUnresolvedItems()` diff vs `QUESTION_PLAN` | yes |
| `research_log` | executor output, accumulated | yes |
| `confidence_state` | merged from patches via `mergeConfidenceState()` | yes |
| `status` | `'intake_in_progress'` if patches applied; else prior | yes |
| `current_step` | literal `'intake'` | yes |

**Not written by intake**: `source_form_data` and `source_artifacts`. Those are populated by `NorthOpsSeedImporter` on import and by `SubmissionController::applyResearchDraft` on research draft apply. Intake never touches them today.

**Transient** per-turn (returned to controller, not persisted): `assistant_message`, `next_question`, `provider`, `provider_unresolved_hints`, `provider_research_requests`, `executed_research`, `research_insights` (`DeterministicIntakeService.php:91-103` return type).

### 2d. Pipeline → Submission relation does NOT drive the plan

`ProposalSubmission` has `pipeline_id` (`ProposalSubmission.php:23`) linking to `ProposalPipeline`. But `ProposalPipeline` has NO `question_plan` field (`ProposalPipeline.php:21-31` — fields are `label`, `machine_name`, `version`, `status`, `pipeline_type`, `appendix_order`, `field_map`, `workflow_definition`, `document_template_config`). The 9-field plan lives entirely in application code (`DeterministicIntakeService::QUESTION_PLAN` + `AppServiceProvider.php:82-91`). **A submission's pipeline does not determine its intake questions**; every submission runs the same plan.

### 2e. HTTP surface

Routes registered in `src/Provider/AppServiceProvider.php:252-272`:

| Route name | Method | Path | Controller |
|---|---|---|---|
| `submissions.intake` | GET | `/submissions/{submission}/intake` | `IntakeController::show($submission)` |
| `submissions.intake.handle` | POST | `/submissions/{submission}/intake` | `IntakeController::handle($request, $submission)` |

Both carry `->allowAll()`; the POST carries `->csrfExempt()`. `show()` (`IntakeController.php:18`) loads the submission via `DeterministicIntakeService::loadSubmission()`, renders the transcript + unresolved + research log + next-question as inline HTML. `handle()` reads the POST body, calls `handleTurn()`, then returns a redirect back to `show()`.

## 3. Invariants

- **Question order is fixed by `QUESTION_PLAN` declaration order.** Reordering silently changes which field is "next" for all mid-intake submissions — breaks resume UX.
- **Field paths are load-bearing strings.** `source_form_data`, `source_artifacts`, `intake_transcript`, `confidence_state`, `unresolved_items`, `canonical_data`, `research_log`, `conversation_summary` are keyed by these literal names in both entity schema and controller HTML rendering. Renaming requires migration + controller edits.
- **`StructuredIntakeClientInterface::processTurn` returning `null` is a valid control-flow signal**, not an error. It means "fall through to deterministic heuristic." Changes to the interface must preserve nullable return.
- **Intake writes only 8 fields per turn.** `source_form_data` / `source_artifacts` are written by seed import and research-draft apply, never intake. Research-draft apply and intake are additive writers into the same entity; they must not stomp each other.
- **The allowlist in `AppServiceProvider.php:82-91` MUST equal the `path` column of `QUESTION_PLAN`.** Drift permits the LLM to patch off-plan paths, or disallows patches the deterministic flow expects. No test enforces this.
- **Status transition `* → intake_in_progress`** is recorded as a `ProposalReviewService::recordSystemAction` audit entry (`DeterministicIntakeService.php:186-192`). Removing or renaming the status literal breaks audit continuity.
- **`current_step = 'intake'`** is a literal string consumed elsewhere (dashboard/submissions list). It is not an enum.
- **Two routes, same path, split by method.** Keep both; merging into one route-with-method-dispatch breaks the `RouteBuilder` contract used across the app.

## 4. UI strings and log tags

| String / tag | preserve / rebrand |
|---|---|
| `src/Controller/IntakeController.php` HTML heading `Conversational Intake` (line ~281 in template block) | preserve (generic) |
| `src/Controller/IntakeController.php` `No intake turns recorded yet.` empty-state | preserve |
| `src/Controller/IntakeController.php` `No unresolved core intake fields.` empty-state | preserve |
| `AnthropicStructuredIntakeClient::providerName()` → `'anthropic'` | preserve (behavior-load-bearing; identifies provider in transcript metadata) |
| Submission status literal `'intake_in_progress'` (`DeterministicIntakeService.php:182`) | preserve (stored in DB, audited) |
| Submission `current_step` literal `'intake'` (`DeterministicIntakeService.php:183`) | preserve |
| Review audit action title `Submission returned to intake` (`DeterministicIntakeService.php:189`) | preserve (audit log continuity) |
| Field names `intake_transcript`, `unresolved_items`, `confidence_state`, `source_form_data`, `source_artifacts`, `research_log`, `canonical_data`, `conversation_summary` | preserve (entity schema) |
| HTTP User-Agent `WaaseyaaProposalsResearchBot/1.0` in `DuckDuckGoResearchExecutor.php:99,:227` | rebrand (product-name leak; intake reaches this via research executor) |
| Route names `submissions.intake`, `submissions.intake.handle` | preserve (referenced by `RouteBuilder` URL generation) |
| Env var `INTAKE_AI_PROVIDER` (`AppServiceProvider.php:76-80`) | preserve (deployment config surface) |
| Env vars `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` (default `claude-sonnet-4-6`) | preserve (vendor-owned) |

No dedicated intake log prefix or metric name is emitted today. No structured logger call in `DeterministicIntakeService` or `IntakeController`.

## 5. Integration seams

### 5a. Connector seam

Per `docs/specs/connectors.md` §6, connector output flows through the existing research-draft lifecycle (`SubmissionController::createResearchDraft` → `applyResearchDraft`), NOT through `IntakeController::handle`. Intake's touchpoint with connectors is therefore indirect:

1. Intake's `processTurn` return may include `research_requests` (`StructuredIntakeClientInterface.php:22`), which `DeterministicIntakeService` today routes through `ResearchExecutorInterface` (`LocalCorpusResearchExecutor` / `DuckDuckGoResearchExecutor`).
2. After connectors land, a user-initiated research draft (separate POST to `/submissions/{sub}/research/draft`) materializes as a `ResearchDraft`. On `applyResearchDraft`, canonical fields are set and provenance is written into `source_artifacts.canonical_field_provenance` (connectors.md §4).
3. **Intake must tolerate `canonical_data` arriving partially pre-filled by research-draft apply between turns.** `deriveUnresolvedItems()` reads `canonicalData` fresh each turn, so this works today — any field already set by research-draft is skipped in the next `nextQuestionForData()` call.

No code change to intake is required for connector integration; the seam is `canonical_data` + `source_artifacts` as shared state.

Touchpoint files today: `src/Domain/Intake/DeterministicIntakeService.php` (reads `canonical_data`), `src/Controller/SubmissionController.php` (writes `canonical_data` + `source_artifacts` on `applyResearchDraft`).

### 5b. Tenancy seam

Per `docs/migrations/tenancy-migration.md`, `proposal_submission`, `proposal_pipeline`, `proposal_cohort` gain an `organization_id` foreign key. Intake's relationship to tenancy:

- `DeterministicIntakeService::loadSubmission()` takes a submission ID and returns the entity. Org scoping enforced upstream by `AccessPolicy` + `CurrentOrganization` service, not in intake.
- The question plan today is global. Per-org or per-pipeline plans are explicitly deferred (see `northops-seed-extraction.md` §5). Intake loop stays constant; what changes is how `QUESTION_PLAN` is resolved (constant → injected per-request).
- Shared-readable pipelines instantiated by other orgs produce submissions that run the same plan. No intake code change needed year-1.

Touchpoint files: `src/Domain/Intake/DeterministicIntakeService.php` (constructor gains no new deps), `src/Controller/IntakeController.php` (route-level access check added by tenancy work, not intake).

Constant across tenancy work: `handleTurn()` signature, `processTurn()` signature, the 8 fields written per turn, the two HTTP routes.

### 5c. Seed extraction seam

Per `docs/migrations/northops-seed-extraction.md` §5, `DeterministicIntakeService` depends only on `ResearchExecutorInterface` for corpus access. When the executor is rebound per-request with the current org's corpus, intake gets tenant-correct research transparently. The extraction plan records the 9-field fixed plan as a deferred concern: all orgs share one plan in MVP.

**Intake loop promises that will not break during seed extraction:**

- `DeterministicIntakeService` keeps its current constructor (storage, review service, intake client, research executor).
- `QUESTION_PLAN` stays as-is until a per-tenant plan design lands.
- The 8 intake-written fields stay identical.
- Research requests from `processTurn()` stay the only path intake uses to reach tenant corpus.

Touchpoint files: `src/Provider/AppServiceProvider.php` (executor binding goes per-request), `src/Domain/Intake/LocalCorpusResearchExecutor.php` (constructor takes per-org path list). `DeterministicIntakeService.php` unchanged.

## 6. Open questions

1. **Does `QUESTION_PLAN` belong on `ProposalPipeline`?** Today it's hardcoded. Putting `question_plan` on the Pipeline entity would make per-org / per-program plans trivial and aligns with the `field_map`, `workflow_definition`, `appendix_order` fields already present. This is a data-model change, not a rename blocker — but it affects spec stability.
2. **Should `allowedPaths` in `AppServiceProvider.php:82-91` be derived from `QUESTION_PLAN::path` instead of being a parallel hand-maintained list?** Two sources of truth today; no test catches drift.
3. **Is the deterministic regex fallback (`extractPatches()`) still load-bearing**, or is Anthropic effectively always on in production? If the fallback is dead code, refusal handling is a non-issue; if it's the local-dev path, it needs preservation through any LLM-client refactor.
4. **Intake never writes `source_artifacts`** — but the rename + tenancy work will touch `source_artifacts.canonical_field_provenance` via connectors. Does intake need to start tagging its own writes with `{source_id: 'intake_turn', confidence, fetched_at}` for parity with connector provenance? Not a rename concern, but a contract-completeness one.
5. **The `applicant.contact.email` field has no validation** in `extractPatches()` or `annotatePatches()`. Confidence state is set but format is not checked. Should intake reject malformed emails before persisting, or leave it to downstream validation? Current behavior: accepts any string.
6. **Route auth.** Both intake routes are `->allowAll()`. Tenancy migration replaces this with org-scoped access. Confirm that submission owner OR org admin OR reviewer can all hit both routes, or split the policy.
