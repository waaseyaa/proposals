# Research — Operational Spec

Status: descriptive. Describes the research subsystem as it exists at HEAD on 2026-04-18. Behavior-change proposals live in `docs/specs/_proposals/research.md` (not in this file).

---

## 1. Overview

"Research" in this codebase is the act of running bounded information-retrieval requests on behalf of an in-progress intake conversation, returning summarized hits with citations, and surfacing those hits to a human operator who can optionally promote them into canonical submission fields via the research-draft lifecycle.

It is distinct from **intake** (the structured-questioning loop in `DeterministicIntakeService` that owns the conversation and canonical-data mutation) and from **connectors** (the proposed typed-payload integration surface described in `docs/specs/connectors.md`). Research is the unstructured-recall layer: intake asks it questions, it returns prose + URLs, humans decide what to keep.

Today there are two in-tree executors (local filesystem corpus, DuckDuckGo HTML scrape) behind `ResearchExecutorInterface`, chosen by env var at boot.

---

## 2. Current contract

### 2.1 `ResearchExecutorInterface`

Defined at `src/Domain/Intake/ResearchExecutorInterface.php:7-26`. Three methods:

- `providerName(): string`
- `isEnabled(): bool`
- `executeRequests(array $requests): array` — input is `list<array{kind,query}>`, output is `list<array{kind,query,provider,status,summary,citations,executed_at}>` where `citations` is `list<array{title,url,snippet}>`. That return shape is the canonical `research_log` entry shape (written verbatim into the entity field).

The executor is called from `DeterministicIntakeService::handleMessage()` (`src/Domain/Intake/DeterministicIntakeService.php:119-179`): it reads `submission.research_log`, passes the structured-intake client's `research_requests` to `$researchExecutor->executeRequests(...)` (line 135 area), merges results back into `research_log`, and persists on line 179.

### 2.2 Research-draft lifecycle

Four controller methods on `SubmissionController`, each with a POST route registered in `AppServiceProvider.php`:

| Transition | Controller method | Route (line in `AppServiceProvider.php`) |
|---|---|---|
| create | `createResearchDraft` (`SubmissionController.php:721-791`) | `submissions.research.draft.create` at `AppServiceProvider.php:283-292` |
| apply  | `applyResearchDraft`  (`SubmissionController.php:794-862`) | `submissions.research.draft.apply`  at `AppServiceProvider.php:295-304` |
| reject | `rejectResearchDraft` (`SubmissionController.php:906-933`) | `submissions.research.draft.reject` at `AppServiceProvider.php:307-315` |
| restore| `restoreResearchDraft`(`SubmissionController.php:864-903`) | `submissions.research.draft.restore`at `AppServiceProvider.php:317-326` |

All four routes `allowAll()` and are `csrfExempt()` — see `docs/specs/AUDIT.md` §11.

Storage note: drafts are stored on the submission's `validation_state['research_drafts']` array (JSON on `proposal_submission`), **not** under a separate `research_drafts` top-level field. The field name mentioned in the audit brief refers to this nested key. See `SubmissionController.php:754, 787, 804, 856, 874, 898, 916, 928`.

**create (`SubmissionController.php:721-791`)** — reads `research_log[research_index]` from the submission; rejects if the item is "ungrounded" (`assessResearchItem` gate at `:748`); dedupes against existing pending/applied drafts on same `(research_index, target_path)` at `:762-767`; appends a new draft row with `status='pending'`, `source_*` mirrors of the research item, `suggested_value`, `draft_quality`, and `citations` at `:770-785`. Does **not** mutate `canonical_data` or `research_log`.

**apply (`SubmissionController.php:794-862`)** — copies `draft.suggested_value` into `canonical_data` at `draft.target_path`; saves previous value into `draft.previous_value` (`:829`); sets `draft.status='applied'`, `applied_at` stamped. Any other `applied` draft on the same `target_path` is demoted to `superseded` (`:835-853`). Only pending drafts are processed; reapplying an already-applied draft is a no-op by the pending-only guard at `:813-815`.

**reject (`SubmissionController.php:906-933`)** — sets `draft.status='rejected'`, stamps `rejected_at`. No `canonical_data` mutation. No status precondition (can reject from any status).

**restore (`SubmissionController.php:864-903`)** — only works on `status='applied'` drafts that carry `previous_value` (`:882`); writes `previous_value` back into `canonical_data`; sets `draft.status='restored'`. If nothing matches, redirect with `?error=research-restore`.

### 2.3 `research_log` shape

`research_log` is a JSON field on `proposal_submission` (`src/Entity/ProposalSubmission.php:37`). Each entry is the shape returned by `ResearchExecutorInterface::executeRequests()` — see §2.1. Written at `DeterministicIntakeService.php:137,179`. Example:

```json
{
  "kind": "funding_opportunity",
  "query": "ISET proposals Ontario 2026",
  "provider": "local_corpus",
  "status": "ok",
  "summary": "Found 3 matching corpus entries...",
  "citations": [{"title":"...","url":"file:///...","snippet":"..."}],
  "executed_at": "2026-04-18T12:30:00+00:00"
}
```

### 2.4 Provenance / grounded citations today

Provenance today is thin:
- Each `research_log` entry carries `citations: list<{title,url,snippet}>` and a `provider` tag.
- Drafts copy the citations verbatim into `draft.citations` at `SubmissionController.php:784`.
- `draft.previous_value` lets `restore` roll back `canonical_data`.
- There is **no** per-canonical-field provenance record after `apply`. The draft row itself is the audit trail; once `canonical_data` is mutated, nothing on the canonical field records which draft produced it.

Gap vs. the `Provenance` type proposed in `docs/specs/connectors.md` §4: the proposed `source_artifacts.canonical_field_provenance` map does not exist yet. `source_artifacts` is currently used only by `NorthOpsSeedImporter` (`source_directory`, `form_html` etc.) per `ProposalSubmission.php:40`.

### 2.5 Executors

**`LocalCorpusResearchExecutor`** — default. Constructor is `(bool $enabled, array $paths)` — the class itself is paths-agnostic. The smell is the DI binding in `AppServiceProvider.php:94-113`, which hardcodes seven absolute paths under `/home/fsd42/NorthOps/...`. See `docs/migrations/northops-seed-extraction.md` §1a for the full enumeration and §4 for the fix.

**`DuckDuckGoResearchExecutor`** — constructor `(bool $enabled=false, int $resultLimit=3, string $searchUrl=...)` at `src/Domain/Intake/DuckDuckGoResearchExecutor.php:13-17`. Fans out to DuckDuckGo HTML, Bing, and Wikipedia (`:9-11`). Outbound user-agent `WaaseyaaProposalsResearchBot/1.0` at `:99` and `WaaseyaaProposalsResearchBot/1.0 (research loop)` at `:227` — external services see this string. Slated for rebrand (see §4).

### 2.6 Executor selection

`AppServiceProvider.php:94-113` binds `ResearchExecutorInterface::class` as a **singleton**. Selection by env var `INTAKE_RESEARCH_PROVIDER` (`:95`):
- `duckduckgo` → `new DuckDuckGoResearchExecutor(true)`
- `local_corpus` (default when unset) → `new LocalCorpusResearchExecutor(true, [hardcoded NorthOps paths])`
- anything else → `new LocalCorpusResearchExecutor(false, [])` (disabled no-op)

---

## 3. Invariants

- `ResearchExecutorInterface::executeRequests()` return shape **is** the `research_log` entry shape. Changing either breaks the other. (Verified: `DeterministicIntakeService.php:137` merges executor output directly into `research_log`.)
- `research_log` is append-only from the intake loop's perspective. Nothing in `DeterministicIntakeService` removes or rewrites entries. (Verified: only `array_merge` at `:137`.)
- Draft `apply` mutates `canonical_data`; `create`, `reject`, and `restore`'s failure branch do not. (Verified per-method, §2.2.)
- `apply` is idempotent on already-applied drafts: the inner loop requires `status === 'pending'` at `SubmissionController.php:813`, so re-POSTing `apply` after success is a no-op.
- `create` is idempotent per `(research_index, target_path)` when an existing draft is pending or applied (`:762-767`) — a duplicate request returns `?updated=research-draft-existing`.
- `restore` requires `previous_value` to have been captured at `apply` time (`:882`). Drafts created but never applied cannot be restored.
- `LocalCorpusResearchExecutor` does not know about organizations. It takes a paths array and searches it. (Confirmed in `northops-seed-extraction.md` §4 "Abstraction boundary".)
- Ungrounded research items (no citations, non-ok status) cannot be promoted to drafts — guarded at `SubmissionController.php:748` via `assessResearchItem`.

---

## 4. UI strings and log tags

| String / tag | Location | Preserve / rebrand |
|---|---|---|
| `WaaseyaaProposalsResearchBot/1.0` | `DuckDuckGoResearchExecutor.php:99` | **rebrand** (outbound HTTP header, external-visible) |
| `WaaseyaaProposalsResearchBot/1.0 (research loop)` | `DuckDuckGoResearchExecutor.php:227` | **rebrand** (outbound HTTP header) |
| `?updated=research-draft`, `?updated=research-applied`, `?updated=research-rejected`, `?updated=research-restored`, `?updated=research-draft-existing` | `SubmissionController.php:790, 861, 932, 902, 766` | **preserve** (URL flash keys, not user copy) |
| `?error=research-draft`, `?error=research-draft-ungrounded`, `?error=research-apply`, `?error=research-restore` | `SubmissionController.php:735, 746, 750, 824, 901` | **preserve** (URL flash keys) |
| Draft statuses: `pending`, `applied`, `rejected`, `restored`, `superseded` | `SubmissionController.php` draft rows | **preserve** (enum values in stored JSON; renaming would be a data migration) |
| Provider tags: `local_corpus`, `duckduckgo` | `LocalCorpusResearchExecutor::providerName()`, `DuckDuckGoResearchExecutor.php:21` | **preserve** (stored in `research_log.provider`, used for env-var selection at `AppServiceProvider.php:97`) |

No hardcoded error strings embed the product name today (verified via audit; research layer has no user-facing error copy beyond the URL flash keys above).

---

## 5. Integration seams

### 5.1 Connector seam

Canonical mapping (per `docs/specs/connectors.md` §2 and §6):

- **`ConnectorInterface`** is the typed-payload integration surface for structured external systems (grant catalogs, CRM, benchmark APIs). Connector hits become `ResearchDraft` rows — **same entity, same `validation_state['research_drafts']` storage, same `create/apply/reject/restore` controller surface**. Connectors do not introduce a parallel draft concept.
- **`ResearchExecutorInterface`** stays as-is for unstructured RAG-style recall (filesystem corpus, web scrape). It continues to feed `research_log`, and `research_log` entries continue to be the source for `createResearchDraft(research_index, target_path)`.

Both layers converge on `research_drafts` as the human-review surface. The difference is upstream: executors return prose+citations; connectors return typed payloads + richer `Provenance`.

Touchpoint files when connectors land:
- `SubmissionController::createResearchDraft` gains a dispatcher path for connector-sourced hits (per `connectors.md` §6 step 2).
- `source_artifacts` schema extends with `canonical_field_provenance` (per `connectors.md` §4 "Provenance travel").
- No route additions — reuses the four routes at `AppServiceProvider.php:283-326`.
- `ResearchExecutorInterface` is untouched.

### 5.2 Tenancy seam

Today's binding at `AppServiceProvider.php:94-113` is a **global singleton** with seven hardcoded `/home/fsd42/NorthOps/...` absolute paths. Per `AUDIT.md` §13 this blocks fresh installs on any machine except Russell's.

Per `docs/migrations/northops-seed-extraction.md` §4 (authoritative design), the binding becomes a per-request **factory**:

```php
$this->bind(ResearchExecutorInterface::class, function () {
    $org = $this->resolve(CurrentOrganization::class)->get();
    $corpusPaths = $this->resolve(CorpusRepository::class)->pathsFor($org);
    return new LocalCorpusResearchExecutor(enabled: true, paths: $corpusPaths);
});
```

Key shape changes:
- `singleton()` → `bind()` — new instance per resolution.
- Paths sourced from `CorpusRepository::pathsFor(Organization)`, backed by the new `organization_research_corpus` table (schema in `northops-seed-extraction.md` §4 "Proposed shape").
- CLI / test fallback: empty-corpus no-op `LocalCorpusResearchExecutor(false, [])` when no org is selected.

The executor class itself doesn't change — this is a wiring-only seam.

### 5.3 Seed-extraction seam

Promise: the contract of `ResearchExecutorInterface` does not change as part of NorthOps seed extraction. What changes:

- The seven `/home/fsd42/NorthOps/...` strings move from PHP code into rows of `organization_research_corpus` keyed by `organization_id`.
- A `CorpusRepository` reads those rows; DI resolves it per request via `CurrentOrganization`.
- The `NorthOpsSeedImporter` graduates to a `NorthOpsSeeder` under `src/Seed/NorthOps/` and is invoked only via a generic `SeedCommand` with `--seeder=northops --org=northops --source=...` (see `northops-seed-extraction.md` §7 step 7).
- No changes to `research_log` shape, `research_drafts` schema, or the four controller methods.

---

## 6. Open questions

- **Per-field provenance shape.** `source_artifacts.canonical_field_provenance` is proposed in `connectors.md` §4 but not yet in the entity. Should research-draft `apply` start writing this map *before* connectors land (to close the audit-trail gap identified in §2.4), or is the draft row itself sufficient provenance until connectors arrive?
- **Draft retention.** Nothing prunes old `rejected` or `superseded` drafts from `validation_state['research_drafts']`. Unbounded growth per submission — acceptable or do we want a TTL / archive?
- **Multi-target apply.** Current `apply` demotes other `applied` drafts on the same `target_path` to `superseded` (`SubmissionController.php:835-853`). If a connector emits a single hit fanning out to multiple `target_path`s, should that be one draft with multiple targets or N drafts? The N-draft model matches today; worth deciding before connectors land.
- **Executor concurrency.** Singleton binding today; per-request factory post-tenancy. Are there executors that legitimately want process-lifetime caching (e.g. indexed corpus)? If so, the factory should delegate to a cached `LocalCorpusCorpusIndex` rather than rebuilding on every request.
- **DuckDuckGo user-agent.** Rebrand target name is unspecified in `rename-to-miikana.md` §6 — "MiikanaResearchBot/1.0"? Needs Russell to confirm the external identity string.
