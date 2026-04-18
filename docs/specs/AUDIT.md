# Audit Delta Report — 2026-04-18 audit vs HEAD

Audit claims re-verified against working tree at commit `9e74952` (main, clean).
File:line references are relative to repo root.

## 1. Entity types

| Claim | Status | Evidence |
|---|---|---|
| `proposal_pipeline` entity | confirmed | `src/Entity/ProposalPipeline.php` |
| `proposal_submission` entity | confirmed | `src/Entity/ProposalSubmission.php` |
| `proposal_document` entity | confirmed | `src/Entity/ProposalDocument.php` |
| `proposal_review` entity | confirmed | `src/Entity/ProposalReview.php` |
| `proposal_cohort` entity | confirmed | `src/Entity/ProposalCohort.php` |
| Entities under `src/Entity/` | confirmed | 5 files + `.gitkeep` |

Registration canonical in `src/Support/ProposalSchemaBootstrap.php:14-20` (`ENTITY_TYPES` constant).

## 2. Schema bootstrap

| Claim | Status | Evidence |
|---|---|---|
| `src/Support/ProposalSchemaBootstrap.php` | confirmed | File exists; `ensure()` iterates the constant array above and delegates to `SqlSchemaHandler::ensureTable()` + `addFieldColumns()` |

## 3. Service wiring / routes

| Claim | Status | Evidence |
|---|---|---|
| `src/Provider/AppServiceProvider.php` carries DI and route map | confirmed | Imports all domain services, wires controllers, returns route table from `routes()` |
| First live domain route is `/submissions` | confirmed | `AppServiceProvider.php:234` (`submissions.index`) |
| Every route uses `allowAll()` | confirmed | 14+ occurrences at lines 188, 197, 206, 216, 226, 236, 245, 255, 265, 276, 287, 298, 309, 320 — continues beyond 320 (route table extends to ~line 539) |

## 4. Intake

| Claim | Status | Evidence |
|---|---|---|
| `DeterministicIntakeService` exists | confirmed | `src/Domain/Intake/DeterministicIntakeService.php` |
| Bounded question plan | confirmed | Field list wired at `AppServiceProvider.php:82-91` — 9 fields: `business.identity.business_name`, `business.operations.launch_timeline`, `business.market.customers`, `funding_request.support_rationale`, `business.market.marketing_plan`, `business.operations.location`, `career_plan.three_year_plan`, `applicant.contact.email`, `applicant.contact.telephone` |
| AI provider via `AnthropicStructuredIntakeClient` | confirmed | `src/Domain/Intake/AnthropicStructuredIntakeClient.php`; env-selected at `AppServiceProvider.php:76-80` via `INTAKE_AI_PROVIDER` (default `anthropic`); uses `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` (default `claude-sonnet-4-6`) |
| Provider-shaped via interface | drifted (audit silent) | `StructuredIntakeClientInterface` exists at `src/Domain/Intake/StructuredIntakeClientInterface.php` — worth calling out; the abstraction is already there |

## 5. Research executors

| Claim | Status | Evidence |
|---|---|---|
| `LocalCorpusResearchExecutor` (default) | confirmed | `src/Domain/Intake/LocalCorpusResearchExecutor.php`; default when `INTAKE_RESEARCH_PROVIDER` unset or `local_corpus` (`AppServiceProvider.php:96-111`) |
| `DuckDuckGoResearchExecutor` | confirmed | `src/Domain/Intake/DuckDuckGoResearchExecutor.php`; selected when `INTAKE_RESEARCH_PROVIDER=duckduckgo` |
| `ResearchExecutorInterface` | drifted (audit silent) | `src/Domain/Intake/ResearchExecutorInterface.php` — interface already exists |

## 6. Research-draft lifecycle

| Claim | Status | Evidence |
|---|---|---|
| `create` / `apply` / `reject` / `restore` in `SubmissionController` | confirmed | Routes in `AppServiceProvider.php`: create (L284-293), apply (L295-304), reject (L306-315), restore (L317-326). Controller methods: `createResearchDraft`, `applyResearchDraft`, `rejectResearchDraft`, `restoreResearchDraft` |

## 7. Review service

| Claim | Status | Evidence |
|---|---|---|
| `src/Domain/Review/ProposalReviewService.php` | confirmed | File exists; `loadSubmission()`, `summarizeSubmission()`, aggregates submission + review storages |
| `src/Controller/ReviewController.php` | confirmed | File exists; wires into routes in `AppServiceProvider.php` |

## 8. Generation

| Claim | Status | Evidence |
|---|---|---|
| `ArtifactAuditService` | confirmed | `src/Domain/Generation/ArtifactAuditService.php` |
| `DocumentPreviewService` | confirmed | `src/Domain/Generation/DocumentPreviewService.php` |
| `PdfGenerationService` | confirmed | `src/Domain/Generation/PdfGenerationService.php` |
| `ArtifactBundleService` | confirmed | `src/Domain/Generation/ArtifactBundleService.php` |

## 9. Cohort

| Claim | Status | Evidence |
|---|---|---|
| `CohortOverviewService` | confirmed | `src/Domain/Cohort/CohortOverviewService.php` |
| `CohortController` | confirmed | `src/Controller/CohortController.php` |
| **`CohortBundleService`** | **missing from audit** | `src/Domain/Cohort/CohortBundleService.php` — second service in the Cohort bounded context, wired at `AppServiceProvider.php`; composes `CohortOverviewService + ArtifactBundleService + ArtifactAuditService + ProposalReviewService`. Materially relevant because cohort-level bundle export is a productization target. |

## 10. NorthOps seeder

| Claim | Status | Evidence |
|---|---|---|
| `src/Command/SeedNorthOpsCommand.php` | confirmed | Symfony console command `proposals:seed-northops`; `--source` option defaults to `/home/fsd42/NorthOps` (L35) |
| `src/Domain/Import/NorthOpsSeedImporter.php` | confirmed | File exists; hardcodes `conversation_summary` = "Seeded from the latest NorthOps ISET package in ~/NorthOps for Waaseyaa proposal development." |

## 11. `allowAll()` on every route

Confirmed. 14+ `->allowAll()` occurrences in `AppServiceProvider.php` lines 188-320; route table continues to ~L539 with same pattern. No `AuthManager` or Symfony Security wiring anywhere in `src/`. `.env.example` contains a **"Dev-only: auto-authenticate as admin on the built-in PHP server"** stanza — worth noting because it implies the framework has an auth surface that the app has not wired up.

## 12. Empty `tests/`

Confirmed. Tree is:
- `tests/.gitkeep`
- `tests/Integration/.gitkeep`
- `tests/Unit/.gitkeep`

PHPUnit is declared in `composer.json` (`^10.5 || ^11.0`) but no test files exist.

## 13. Hardcoded absolute paths

Confirmed. Two hot spots:

- `src/Provider/AppServiceProvider.php:102-108` — LocalCorpusResearchExecutor corpus paths:
  - `/home/fsd42/NorthOps/knowledge-base`
  - `/home/fsd42/NorthOps/sources/OIATC`
  - `/home/fsd42/NorthOps/sources/mefunding-docs`
  - `/home/fsd42/NorthOps/PROJECT_STATUS.md`
  - `/home/fsd42/NorthOps/README.md`
  - `/home/fsd42/NorthOps/apps/iset-application/README.md`
  - `/home/fsd42/NorthOps/apps/oiatc-demo/README.md`
- `src/Command/SeedNorthOpsCommand.php:35` — seed source default `/home/fsd42/NorthOps`

These block any install that isn't Russell's WSL workstation. They are a hard blocker for SaaS deployment.

---

## Items the audit did not mention but materially affect productization

1. **Composer package name is `northops/waaseyaa-proposals`, not `waaseyaa/proposals`.** `composer.json:2`. Vendor prefix is `northops`. Miikana rename has to decide: keep `northops/` vendor (tenant-operator name) or re-vendor under `waaseyaa/` (framework namespace).

2. **PHP root namespace is `App\`, not `Waaseyaa\Proposals\`.** `composer.json` autoload `{"App\\": "src/"}`. Every PHP file declares `namespace App\...`. This is a *huge* simplifier for the rename — the code does not carry the old product name as a PHP namespace. The rename is mostly composer/metadata/strings, not a code-wide namespace churn.

3. **`CohortBundleService` is separate from `CohortOverviewService`** (see §9). Audit treated cohort as a single service.

4. **`IntakeController` and `DocumentController` both exist** but were not named in the audit. `AppServiceProvider.php` wires 6 controllers total: Dashboard, Submission, Document, Intake, Cohort, Review.

5. **`waaseyaa/bimaaji` is a first-class dependency** (`composer.json:9`, `^0.1@dev`). Dashboard renders a Bimaaji graph snapshot + sovereignty context. This is not just "optional introspection"; it is load-bearing for the root route at `AppServiceProvider.php:167-170`.

6. **`composer.local.json` is referenced by `docs/local-dev.md` and by `CLAUDE.md`, but it does not exist on disk.** Neither does `composer.local.json.example` that `docs/local-dev.md:6` instructs developers to copy. Path-based dev workflow is documented, not wired up. Rename planning does *not* need to worry about fixing a sibling monorepo today because that link isn't active.

7. **Hardcoded UI strings carry the old product name**:
   - `src/Controller/SubmissionController.php` — inline HTML `<title>Submissions · Waaseyaa Proposals</title>`
   - `src/Domain/Intake/DuckDuckGoResearchExecutor.php:99, :227` — User-Agent `WaaseyaaProposalsResearchBot/1.0`
   - `src/Controller/DashboardController.php` — body copy references Bimaaji context; dashboard text strings need a visual pass
   - `src/Domain/Import/NorthOpsSeedImporter.php` — seeded `conversation_summary` text

8. **No `User`, no `Organization`, no membership concept anywhere in `src/`.** Multi-tenancy is a pure greenfield add; nothing exists to repurpose. `.env.example` mentions a dev-only admin auto-auth, which is framework-level and irrelevant to the app right now.

9. **Only 2 commits on main (`init`, `docs: refresh claude guidance`).** The codebase is brand-new and unbranched. No downstream history to protect during the rename.

10. **`docs/specs/` did not exist before this pass.** Only `docs/local-dev.md` was present. All specs are greenfield.

11. **The framework's dev-only auth fallback** (`.env.example` stanza) is likely the mechanism that makes `allowAll()` okay in dev today. Productization must replace *both* the app-level `allowAll()` *and* understand what the framework's dev-fallback does in production before deploying to `miikana.waaseyaa.org`.

## Summary posture

The audit is directionally correct and the drifts are additive — additional abstractions (`StructuredIntakeClientInterface`, `ResearchExecutorInterface`) and an additional cohort service the audit missed. No claim was flat wrong. The two most consequential facts the audit didn't surface: **namespace is `App\` (rename is cheap)** and **composer vendor is `northops/` (rename has a vendor-prefix decision).**

## Addendum from intake-spec pass — 2026-04-18

1. **Question plan is defined in TWO places that must stay in sync.** The audit points to `AppServiceProvider.php:82-91` as "the field list," but the authoritative plan is actually `DeterministicIntakeService::QUESTION_PLAN` at `src/Domain/Intake/DeterministicIntakeService.php:13` (with `path` + `priority` + `question` + `example` per entry). The `AppServiceProvider.php:82-91` array is the `allowedPaths` LLM allowlist passed to `AnthropicStructuredIntakeClient`. Both must list the same nine paths; no test enforces this invariant.

2. **`ProposalPipeline` has NO `question_plan` field.** Its fields are `label`, `machine_name`, `version`, `status`, `pipeline_type`, `appendix_order`, `field_map`, `workflow_definition`, `document_template_config` (`src/Entity/ProposalPipeline.php:22-30`). Every submission runs the same global 9-field plan regardless of pipeline. Per-pipeline or per-org plans require a schema change on `proposal_pipeline`.

3. **Intake never writes `source_form_data` or `source_artifacts`.** Those fields are populated only by `NorthOpsSeedImporter` and are the designated target for research-draft provenance per `connectors.md` §4. `DeterministicIntakeService::handleTurn` (`DeterministicIntakeService.php:175-184`) writes 8 fields: `canonical_data`, `intake_transcript`, `conversation_summary`, `unresolved_items`, `research_log`, `confidence_state`, `status`, `current_step`. Relevant when auditing canonical-data mutation surfaces.

4. **LLM refusal and malformed-JSON collapse to the same control-flow path.** `AnthropicStructuredIntakeClient::processTurn` returns `null` for disabled-state, non-200 HTTP, non-string response, or non-array decode (`AnthropicStructuredIntakeClient.php:34-36,207-214`). `DeterministicIntakeService` treats `null` as "fall through to deterministic `extractPatches()` regex heuristic" (`DeterministicIntakeService.php:139`). No failure is logged. If the regex fallback path is dead code in practice, the entire deterministic branch is a liability during any intake-client refactor.

5. **Intake status literal `'intake_in_progress'` + `current_step = 'intake'`** are load-bearing strings (`DeterministicIntakeService.php:182-183`) — stored in DB, consumed by dashboard/submissions list, and referenced in `ProposalReviewService::recordSystemAction` audit messages. Rename-adjacent cleanup must not touch these.

## Addendum from generation-spec pass — 2026-04-18

1. **`ArtifactAuditService` does not actually record or append.** Despite its name, the service has one public method — `summarize(EntityInterface $submission)` (`src/Domain/Generation/ArtifactAuditService.php:19`) — which enumerates an expected-kind map (lines 21-31) and reports readiness flags against existing `proposal_document` rows. There is no append-only log. Any "audit trail" claim against this service is point-in-time readiness, not history. Rename candidate: `ArtifactReadinessService`.

2. **`ArtifactAuditService` expected-kind map duplicates generator-side kind strings.** Lines 21-31 hard-code `appendix_a_preview`, `appendix_b_preview`, `appendix_f_preview`, `appendix_g_preview`, `appendix_h_preview`, `appendix_m_preview`, `merged_package_preview`, `merged_package_pdf`, `artifact_bundle_zip`. If any generator adds a new `document_type` (e.g. future `business_plan_preview`), this map silently drifts. No test enforces the invariant.

3. **PDF generation shells out to Chrome, not a PHP PDF library.** `PdfGenerationService.php:17-18` takes `string $chromeBinary` (default `/usr/bin/google-chrome`) and requires it to be executable (line 26-28). Hosts without Chrome return 500 on every PDF route. Not Dompdf, not mpdf, not wkhtmltopdf. Deployment implication for `miikana.waaseyaa.org`.

4. **Cohort ZIP is not persisted as a `proposal_document`.** Per-submission bundles persist an `artifact_bundle_zip` row (`ArtifactBundleService.php`) but `CohortBundleService::build` (`src/Domain/Cohort/CohortBundleService.php:25`) emits the ZIP to disk only. No entity row, no audit visibility, no `proposal_cohort_document` peer entity. Asymmetry with per-submission artifacts.

5. **`DocumentPreviewService::FOOTER` is Sagamok/NorthOps tenant data, not product branding.** Constant at `DocumentPreviewService.php:12` reads `'Education Department, 717 Sagamok Road, Sagamok ON P0P 2L0, Telephone: (705) 865-2421'`. This must move to per-org config under tenancy (see `docs/migrations/northops-seed-extraction.md`) — the Miikana rename alone does not address it.

6. **"Waaseyaa Proposals" eyebrow string is rendered into the merged package cover.** `DocumentPreviewService.php:900` writes `<div class="eyebrow">Waaseyaa Proposals</div>` into generated HTML and PDF output. This is a visible product-name string inside user-facing artifacts, not just UI chrome, and must be rebranded to "Miikana" as part of the rename.

7. **PDF regeneration forces HTML regeneration.** `PdfGenerationService::generatePackagePdf` calls `DocumentPreviewService::buildPackageAndPersist` unconditionally (`PdfGenerationService.php:30`). You cannot generate a PDF against a previously-rendered HTML snapshot. Same for ZIP bundles (`ArtifactBundleService::buildAndPersist` at line 22 triggers preview + package build). Idempotent but not cheap; impacts cohort-bundle latency.

8. **Seeded `generated_document_index` paths point outside `storage/`.** `src/Domain/Import/NorthOpsSeedImporter.php:146-149` and again at line 319 write file paths under `$sourceDirectory` (typically `~/NorthOps/...`) directly into the submission index. Generation services tolerate this (they only add keys; they don't rewrite existing ones), but any tenancy refactor that moves to per-org storage roots must preserve this tolerance for seeded rows.

## Addendum from review-spec pass — 2026-04-18

1. **`proposal_review.reviewer_uid` already exists.** `tenancy-migration.md` §4 says "Today `proposal_review` has no user concept" and proposes adding `reviewer_user_id`. The field is already defined at `src/Entity/ProposalReview.php:24` (`'reviewer_uid' => ['type' => 'integer', 'label' => 'Reviewer User ID']`) and is written at every call site in `ProposalReviewService.php` (lines 113, 343, 384, 442, 484, 602). The actual gap is that every `$reviewerUid` parameter defaults to `1` with no session wiring — the tenancy change is to pass the authenticated user id, not to add the column.

2. **`has_revisions_requested` is derived, not stored.** `ProposalSubmission` has no such field (`src/Entity/ProposalSubmission.php:21-43`). It is computed in-flight by `ProposalReviewService::summarizeSubmission` as `$status === 'revisions_requested'` (`ProposalReviewService.php:51`). Same for `is_approved` (line 52). Any migration reasoning about "resetting `has_revisions_requested`" must instead reason about `status` transitions.

3. **`revisions_requested` status has no writer.** `stepForStatus` maps it (`ProposalReviewService.php:673`) and `summarizeSubmission` reads it, but no code path transitions a submission into it. `sendBackToIntake` goes to `'intake_in_progress'`, not `'revisions_requested'` (`ProposalReviewService.php:392`). The only review-authored transitions today are to `approved`, `exported`, `submitted` (status dropdown at `ReviewController.php:357-361`) or `intake_in_progress` (send-back). `revisions_requested` is effectively dead as a current state.

4. **Send-back-to-intake is pure status change.** It resets no fields. `research_log`, `validation_state.reviewed_appendices`, `confidence_state`, `completion_state` all survive a send-back (`ProposalReviewService.php:381-400` delegates solely to `transitionStatus`). Downstream specs cannot assume "sending back" clears intake-side state — it does not.

5. **Approve / submit / export gate requires all six appendices reviewed.** `transitionStatus` hard-rejects moving to `approved | exported | submitted` unless `allAppendicesReviewed` is true (`ProposalReviewService.php:355-361`). The appendix set is hardcoded to `A,B,F,G,H,M` across the controller and service. Per-pipeline appendix schemes (implied by `rename-to-miikana.md` per-org pipelines) are not yet possible.

6. **Comment edit and delete are not implemented.** `ReviewController` exposes only `addComment` (no `editComment`, no `deleteComment`). The `proposal_review` log is strictly append-only; clear/restore of appendix notes are represented via new `action_type='appendix_note_cleared'` / `'appendix_note_restored'` rows (tombstone pattern), never through row mutation.
