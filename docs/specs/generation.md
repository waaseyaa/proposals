# Generation — Operational Spec

Descriptive. Documents how artifact generation behaves today, what strings/keys are behavior-load-bearing under the Miikana rename, and where future connector/tenancy/seed-extraction refactors must plug in. Behavior-change proposals go to `docs/specs/_proposals/generation.md`.

Sibling specs: `docs/specs/AUDIT.md`, `docs/specs/connectors.md`, `docs/specs/intake.md`.

---

## 1. Overview

"Generation" covers producing user-facing artifacts from a `proposal_submission`: preview HTML (per-appendix and merged package), a printable merged-package PDF, a per-submission ZIP bundle, and a cohort-level ZIP bundle that composes per-submission bundles. All generation services persist a row in `proposal_document` (`src/Entity/ProposalDocument.php:21-31`) and update the submission's `generated_document_index` (`src/Entity/ProposalSubmission.php:38`).

The only currently-produced proposal type is the NorthOps ISET application package. Russell's product direction adds a second, distinct artifact type: the **business plan**. That artifact does not exist yet — it will require a new template family (analogous to the Appendix A/B/F/G/H/M renderers in `DocumentPreviewService`), new submission fields for plan-specific content (vision, 3-year plan, financial projections; some overlap with existing intake fields like `career_plan.three_year_plan`), and a decision on whether it lives on `proposal_submission` or as its own entity type. See §6.

This spec is descriptive. The rename (`northops/waaseyaa-proposals` → `waaseyaa/miikana`, see `docs/migrations/rename-to-miikana.md`) and tenancy migration (`docs/migrations/tenancy-migration.md`) proceed after operational specs are settled.

---

## 2. Current contract

### 2.1 Artifact types produced today

| Kind key | Producer | Entity row (`document_type`) | Output path |
|---|---|---|---|
| HTML appendix previews (A/B/F/G/H/M) | `DocumentPreviewService::buildAndPersist` (`src/Domain/Generation/DocumentPreviewService.php:22`) | `appendix_*_preview` | `storage/proposals/generated/{id}/*.html` (via `ensureHtmlArtifact`, line 349) |
| Merged package HTML | `DocumentPreviewService::buildPackageAndPersist` (line 73) | `merged_package_preview` | same directory |
| Merged package PDF | `PdfGenerationService::generatePackagePdf` (`src/Domain/Generation/PdfGenerationService.php:24`) | `merged_package_pdf` | `storage/proposals/generated/{id}/…pdf` (rendered via headless Chrome at `$chromeBinary`, constructor default `/usr/bin/google-chrome`, line 18) |
| Per-submission ZIP bundle | `ArtifactBundleService::buildAndPersist` (`src/Domain/Generation/ArtifactBundleService.php:22`) | `artifact_bundle_zip` | `storage/proposals/generated/{id}/…zip` |
| Cohort ZIP bundle | `CohortBundleService::build` (`src/Domain/Cohort/CohortBundleService.php:25`) | not a `proposal_document` row — file only | `storage/proposals/cohorts/{cohortId}/…-bundle.zip` |

Artifact-kind keys written into `generated_document_index` by the seed importer are `html_form`, `html_submission`, `pdf_package` (`src/Domain/Import/NorthOpsSeedImporter.php:146-149,319`). These keys are **stored data** and behavior-load-bearing — see §3.

PDF rendering: headless-Chrome via shell exec (`PdfGenerationService` line 17-18). Not Dompdf/mpdf/wkhtmltopdf. Chrome is expected on the host.

### 2.2 Future artifact: business plan

Not implemented. Placeholder contract for planning:

- **New template** paralleling the Appendix renderers in `DocumentPreviewService` (likely `renderBusinessPlanSection()` methods).
- **New submission fields** to carry plan-specific content. Some already exist as intake fields (`career_plan.three_year_plan` in `source_form_data`). Vision, financial projections (year-1/2/3 revenue/expense), risk register, go-to-market plan would need additions to `ProposalSubmission::$fieldDefinitions`.
- **Open question** (see §6): does the business plan coexist on the same `proposal_submission` as the grant application, or become a distinct entity type (`business_plan_submission`) with its own pipeline?
- **Reuse target**: ZIP bundling, PDF generation, audit summary, and cohort composition should be kind-agnostic once the kind keys are widened. Today they assume ISET-shaped `document_type` values (`ArtifactAuditService::summarize` expected-map at lines 21-31).

### 2.3 Service boundaries

- **`ArtifactAuditService`** (`src/Domain/Generation/ArtifactAuditService.php`). Owns the readiness summary used by the UI. Constructor: `EntityStorageInterface $documentStorage` (line 13). Only public method: `summarize(EntityInterface $submission): array` (line 19) — returns `ready_count`, `total_count`, `missing`, and a per-document `items` list keyed by the expected-document map (lines 21-31). **This service does not record or append new entries** despite the name "audit"; it reads `proposal_document` rows and reports which expected kinds are present. Boundary blur: the expected-kind map duplicates kind strings that the preview/PDF/bundle services write.
- **`DocumentPreviewService`** (`src/Domain/Generation/DocumentPreviewService.php`). Owns HTML rendering for the six ISET appendices plus the merged package. Constructor: `EntityStorageInterface $documentStorage`, `string $projectRoot` (lines 14-17). Key methods: `buildAndPersist` (line 22), `buildPackageAndPersist` (line 73), `ensureHtmlArtifact` (line 349), `persistDocuments` (line 385, writes `storage_path` at line 407), `pageStyles` (line 90). Also emits the master HTML frame around a merged document (line 1016+). Blur: `pageStyles` is shared CSS also consumed by `PdfGenerationService::buildPrintableHtml`.
- **`PdfGenerationService`** (`src/Domain/Generation/PdfGenerationService.php`). Owns PDF rendering. Constructor: `DocumentPreviewService`, `EntityStorageInterface $documentStorage`, `ProposalReviewService`, `string $projectRoot`, `string $chromeBinary = '/usr/bin/google-chrome'` (lines 13-19). Calls `DocumentPreviewService::buildPackageAndPersist` internally (line 30) — so regenerating PDF always re-runs HTML generation. Persists a `merged_package_pdf` `proposal_document` row (lines 81-84). Blur: holds its own copy of the "Merged ISET Package PDF" label string.
- **`ArtifactBundleService`** (`src/Domain/Generation/ArtifactBundleService.php`). Owns per-submission ZIP bundling. Constructor: `EntityStorageInterface $documentStorage`, `DocumentPreviewService`, `PdfGenerationService`, `string $projectRoot` (lines 12-17). `buildAndPersist(EntityInterface $submission, bool $ensurePdf = false)` (line 22) forces preview + package HTML regeneration, optionally the PDF, then zips every `proposal_document` whose `document_type !== 'artifact_bundle_zip'` (line 49). Persists an `artifact_bundle_zip` row. Blur: it triggers regeneration rather than consuming pre-built artifacts — idempotent but not cheap.
- **`CohortBundleService`** (`src/Domain/Cohort/CohortBundleService.php`). Owns cohort-level ZIP composition. Constructor: `CohortOverviewService`, `ArtifactBundleService`, `ArtifactAuditService`, `ProposalReviewService`, `string $projectRoot` (lines 14-20). `build(EntityInterface $cohort)` (line 25) loads submissions via `CohortOverviewService::loadSubmissionsForCohort` (line 27), generates a `cohort-board.csv` plus nested per-submission bundles under `submissions/{slug}-bundle.zip` in the ZIP (lines 45-50,59). **Missed by the original audit** — see `docs/specs/AUDIT.md` §9 and §"Items the audit did not mention but materially affect productization" #3.

### 2.4 Provenance chain

Today: essentially none reaches the renderer. `DocumentPreviewService`, `PdfGenerationService`, `ArtifactBundleService`, and `CohortBundleService` never reference `provenance`, `citation`, `source_url`, or `source_artifacts` in their render paths (verified via grep across `src/Domain/Generation` and `src/Domain/Cohort`). The connectors spec defines a `Provenance` value type and a proposed `source_artifacts.canonical_field_provenance` map keyed by dotted field path (`docs/specs/connectors.md` §4 "Provenance travel"), but that data terminates at submission write time and is not surfaced in generated HTML/PDF. What survives into rendered output is canonical field content only (applicant name, business name, narrative fields). No citation footnotes, no source badges. Any provenance UX is a future integration (§5).

### 2.5 Audit trail

`ArtifactAuditService::summarize` (lines 19-60+) is the entire audit surface. It:

- Enumerates an `expected` map of document-type → human label (lines 21-31): `appendix_a_preview`, `appendix_b_preview`, `appendix_f_preview`, `appendix_g_preview`, `appendix_h_preview`, `appendix_m_preview`, `merged_package_preview`, `merged_package_pdf`, `artifact_bundle_zip`.
- Loads every `proposal_document` row (`documentStorage->getQuery()->execute()` line 33) and filters by `submission_id` (line 36).
- Returns `ready_count`, `total_count`, `missing`, per-item ready-flags.

Queryable later via: controllers calling `$artifactAuditService->summarize($submission)` (e.g. `SubmissionController` line 207, `CohortController`, `DocumentController`). There is **no append-only log** — the source of truth is `proposal_document` rows and their `generated_at` / `version` fields (`src/Entity/ProposalDocument.php:26,30`). Regeneration overwrites or versions rows depending on `persistDocuments` behavior (see §6 open question).

---

## 3. Invariants

- **Artifact-kind keys are stored data.** `generated_document_index` is keyed by stable strings (`html_form`, `html_submission`, `pdf_package` per seed importer `src/Domain/Import/NorthOpsSeedImporter.php:146-149`). `proposal_document.document_type` uses a parallel set (`appendix_*_preview`, `merged_package_preview`, `merged_package_pdf`, `artifact_bundle_zip`). Renaming any of these is a data-migration event, not a string replacement.
- **Expected-kind map in `ArtifactAuditService`** (lines 21-31) must stay in sync with the set of kinds `DocumentPreviewService` / `PdfGenerationService` / `ArtifactBundleService` actually produce. No test enforces this; drift silently misreports readiness.
- **Output root is `{projectRoot}/storage/proposals/`.** All four generation services construct paths by prepending `projectRoot` (constructor strings `ArtifactBundleService.php:16`, `PdfGenerationService.php:17`, `DocumentPreviewService.php:16`, `CohortBundleService.php:19`). Per-submission artifacts land under `storage/proposals/generated/{submissionId}/`; cohort artifacts under `storage/proposals/cohorts/{cohortId}/`.
- **PDF generation forces HTML regeneration.** `PdfGenerationService::generatePackagePdf` calls `buildPackageAndPersist` (line 30). You cannot generate a PDF against a snapshot of previously-rendered HTML.
- **ZIP bundles regenerate source documents too.** `ArtifactBundleService::buildAndPersist` (line 22) always calls preview + package build. Idempotent but re-computes.
- **Cohort bundles compose, never regenerate per-submission artifacts from scratch within the cohort pass** — they invoke `ArtifactBundleService::buildAndPersist` per submission, which is the per-submission regeneration point. Cohort-level generation is therefore idempotent iff submissions haven't changed.
- **Seeded submissions' `generated_document_index` values point outside `storage/`** (to `$sourceDirectory` under `~/NorthOps`; see `src/Domain/Import/NorthOpsSeedImporter.php:146-149`). Generators must tolerate these foreign paths. Seeded artifacts are not re-generated unless the user triggers regeneration.
- **Chrome is a hard runtime dependency** for PDF generation (`PdfGenerationService.php:26-28`). Hosts without Chrome 5xx on `/submissions/{id}/package/pdf*`.

---

## 4. UI strings and log tags

| String / tag | preserve | rebrand |
|---|---|---|
| `generated_document_index` keys: `html_form`, `html_submission`, `pdf_package` | preserve (stored data; seeded `ProposalSubmission` rows depend on these) | — |
| `proposal_document.document_type` values: `appendix_*_preview`, `merged_package_preview`, `merged_package_pdf`, `artifact_bundle_zip` | preserve (stored data) | — |
| Filename slug source: `business_name` → submission label → literal `"submission"` (`PdfGenerationService.php:31`; `CohortBundleService.php:33` slugs cohort label) | preserve (user-agent bookmarks; no product branding in filename today) | — |
| "Waaseyaa Proposals" cover eyebrow in merged package HTML (`DocumentPreviewService.php:900`) | — | rebrand to "Miikana" |
| `'<title>ISET Package PDF</title>'` (`PdfGenerationService.php:117`) | preserve title substance ("ISET Package PDF") — ISET is the program, not the product | — |
| `'Merged ISET Package'` label (`DocumentPreviewService.php:80`); `'Merged ISET Package PDF #%s'` label (`PdfGenerationService.php:81,84`) | preserve (ISET is the upstream program name, not the product) | — |
| `FOOTER` constant: `'Education Department, 717 Sagamok Road…'` (`DocumentPreviewService.php:12`) | **tenant data, not product branding** — will move to per-org config under tenancy (§5) | — |
| Route names `submissions.package`, `submissions.package.pdf`, `submissions.package.pdf.download`, `submissions.exports`, `submissions.exports.file`, `submissions.exports.file.download`, `submissions.exports.pdf.regenerate`, `submissions.exports.bundle.download`, `cohorts.export.csv`, `cohorts.bundle.download` (`src/Provider/AppServiceProvider.php:349,359,369,379,389,400,411,421,213,223`) | preserve (internal names; URLs already `/submissions/…`, `/cohorts/…`) | will prefix `/org/{slug}/` under path-based tenancy |
| No log prefixes / no CLI descriptions in the generation services today | n/a | n/a |

---

## 5. Integration seams

### 5.1 Connector seam

Future: rendered artifacts should annotate which fields came from which connector. The touchpoint is the `Provenance` record proposed in `docs/specs/connectors.md` §4 and the `source_artifacts.canonical_field_provenance` map (same file, "Provenance travel" subsection). To reach the renderer, one of:

- `DocumentPreviewService` gains a `withProvenance(array $canonicalFieldProvenance): self` method (or accepts a provenance map in `buildAndPersist`), so templates can render inline citation footers per field.
- Or a new `ProvenanceAnnotator` post-processes the canonical-data map before it hits the appendix renderers (`extractData` at `DocumentPreviewService.php:24`).

Today provenance terminates at submission write time (connector writes `source_artifacts`, renderer ignores it). This is the single largest behavioral gap between the "research → canonical → generation" pipeline and a defensible audit story.

### 5.2 Tenancy seam

Today: all artifacts are written under `{projectRoot}/storage/proposals/generated/{submissionId}/…` or `{projectRoot}/storage/proposals/cohorts/{cohortId}/…`. Four services inject `string $projectRoot` via constructor (`ArtifactBundleService.php:16`, `PdfGenerationService.php:17`, `DocumentPreviewService.php:16`, `CohortBundleService.php:19`) — bound in `AppServiceProvider` via `$this->projectRoot`.

Post-tenancy (path-based `/org/{slug}/…` per `docs/migrations/tenancy-migration.md` and Russell's 2026-04-18 lock): artifacts must live under `storage/org/{slug}/submissions/{id}/…` and `storage/org/{slug}/cohorts/{id}/…`. **This does not change the generators' contracts** — they still take a single root string. What changes: the binding resolves root from `CurrentOrganization` (see tenancy spec §5) rather than a static project root. One-line DI swap per service.

The `FOOTER` constant (`DocumentPreviewService.php:12`) is Sagamok/NorthOps-specific and must move to per-org config once tenancy lands — noted in `docs/migrations/northops-seed-extraction.md`.

### 5.3 Seed extraction seam

`NorthOpsSeedImporter` writes `generated_document_index` entries pointing at files **outside** `storage/` — under `$sourceDirectory` (typically `~/NorthOps/…`; see `src/Domain/Import/NorthOpsSeedImporter.php:146-149`, and again at line 319 for subsequent imports). These paths flow into `ProposalSubmission.generated_document_index` as-is.

Consequence for generation: the services must tolerate foreign (non-`storage/`) paths. They already do — `generated_document_index` is read by the UI/controllers, not rewritten by the generators. Generators only **add** new kinds (`appendix_*_preview`, `merged_package_*`, `artifact_bundle_zip`) alongside the seeded `html_form` / `html_submission` / `pdf_package` entries. Seeded artifacts are never re-generated unless the user invokes a regenerate route (e.g. `submissions.exports.pdf.regenerate`, `AppServiceProvider.php:411`).

Per the seed-extraction plan, seeded paths become per-tenant config rather than hard-coded `~/NorthOps`. Generation services are unaffected — they read whatever path is already in the index.

### 5.4 Cohort seam

`CohortBundleService` composes `CohortOverviewService + ArtifactBundleService + ArtifactAuditService + ProposalReviewService` (`src/Domain/Cohort/CohortBundleService.php:14-20`). It was **missed by the original audit** — `AUDIT.md` §9 and the "Items the audit did not mention but materially affect productization" section #3 record this. Productizing cohort-level publishing (mission-tier grant consortium exports) depends on this service being solid. The service currently emits a `cohort-board.csv` plus nested `submissions/{slug}-bundle.zip` entries (lines 45-50), tracks `included_files` (line 59), but does **not** persist a `proposal_document` row for the cohort ZIP — the file is on disk only. That asymmetry with per-submission bundles is a seam to flag.

---

## 6. Open questions

1. **Regeneration semantics: overwrite or version?** `ProposalDocument` has a `version` field (`src/Entity/ProposalDocument.php:26`) but nothing increments it. Does regenerating an artifact create a new row or overwrite the existing one? `persistDocuments` (`DocumentPreviewService.php:385`) behavior needs verification before the mission tier depends on it.
2. **Cohort bundles on demand or scheduled?** Today `cohorts.bundle.download` (`AppServiceProvider.php:223`) triggers `CohortBundleService::build` synchronously per request. For cohorts with dozens of submissions this is a long request. Queue-backed regeneration + cached artifact path is a future design.
3. **Business plan — same `proposal_submission` or separate entity type?** Russell's direction adds a business-plan artifact distinct from the grant application. Option A: extend `ProposalSubmission` with plan-specific fields and a second template family; one submission produces both artifacts. Option B: introduce `business_plan_submission` entity and a second pipeline family; submissions are linked. Option A reuses cohort/pipeline/review machinery; Option B is cleaner domain separation.
4. **Provenance rendering: inline footnotes, side-column badges, or appendix?** Once connector provenance reaches the renderer (§5.1), where does it surface? Affects template design for both ISET and business-plan families.
5. **Cohort ZIP as a `proposal_document`?** Per-submission bundles persist an `artifact_bundle_zip` row; cohort bundles don't. Decide whether cohort artifacts need a peer entity (`proposal_cohort_document`?) for audit-trail symmetry.
6. **`ArtifactAuditService` rename.** The name implies append-only logging; the behavior is point-in-time readiness summary. Consider `ArtifactReadinessService`. Rename is descriptive-spec-internal until a proposal lands.
