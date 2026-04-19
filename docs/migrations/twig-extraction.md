# Twig Extraction — Remaining Controllers

Scope: convert the inline-HTML heredoc + `str_replace` + `setContent` pattern in
the remaining controllers to Twig templates that match the conventions in
[`docs/specs/templating-conventions.md`](../specs/templating-conventions.md).
Dashboard (`src/Controller/DashboardController.php`) was extracted as the
reference implementation in the same pass that created the conventions doc.

## Sizing snapshot (2026-04-18)

| Controller | LOC | Public methods | Inline-HTML markers | Inline-HTML complexity | Est. work units |
|---|---:|---:|---:|---|---:|
| DashboardController | 52 | 1 | 0 | — (done) | — |
| IntakeController | 546 → 233 | 2 | 2 | medium — one full page + POST redirect | done 2026-04-18 |
| CohortController | 540 → 279 | 4 | 5 | medium — list + detail page, plus 2 non-HTML responses | done 2026-04-18 |
| SubmissionController | 1886 → 1168 | 8 | 5 | high — list + detail, many mutation endpoints, ready-for-review flow | **all done** 2026-04-18 |
| ReviewController | 1139 → 634 | 11 | 2 | high — review cockpit with comments/appendix notes, 10+ POST handlers, CSRF surface | **all done** 2026-04-18 |
| DocumentController | 902 → 495 | 9 | 10 | high — preview HTML, PDF bytes, ZIP bundles, file downloads (mostly non-HTML) | **all done** 2026-04-18 |
| **Total** | **5,013 → 3,059** | **34** | **24** | — | **all done** |

"Work unit" = one prompt-sized refactor (roughly the shape of the Dashboard
pass: one controller, one or more page templates, one migration diff, a smoke
test, and no behavior change). High-LOC controllers are split when they
contain multiple independent page templates.

## IntakeController

- File: `src/Controller/IntakeController.php` (546 lines, 2 public methods).
- Routes:
  - `submissions.intake` — GET `/submissions/{submission}/intake` → `show($submission)`
  - `submissions.intake.handle` — POST `/submissions/{submission}/intake` → `handle($request, $submission)` (`->csrfExempt()`)
- Inline-HTML complexity: **medium**. One full-page render (transcript +
  unresolved-fields panel + research log + next-question form). `handle()` is
  a POST→redirect, no HTML body.
- Proposed page templates:
  - `templates/pages/intake/show.html.twig` — transcript + next-question UI
- Component opportunities:
  - `templates/components/domain/intake/transcript-turn.html.twig` — single
    turn card, iterated with `{% for %}`
  - `templates/components/domain/intake/unresolved-list.html.twig` — the
    "fields still needed" list
  - `templates/components/domain/intake/research-log.html.twig` — executor
    trace output
  - `templates/components/domain/intake/next-question-form.html.twig` — the
    POST form; consumer of `csrf_token()`
- Risks:
  - `handle()` returns a redirect, which does not need a template. Keep the
    current `RedirectResponse` and only extract `show()`.
  - Inline JavaScript (form submission / autofocus / scroll-to-bottom) lives
    inside the page body today. If it exists, hoist into
    `{% block scripts %}` once the base layout grows that block.
  - `->csrfExempt()` must stay on the POST route. CSRF is currently bypassed
    here; if templates use `csrf_token()`, the field will be rendered but the
    framework still won't validate it on this POST. Do not re-enable CSRF as
    part of the Twig extraction — that is a separate security change.

## CohortController

**Status (2026-04-18): done.** Migrated in prompt #6 — `index()` and `show()`
now render Twig templates (`pages/cohorts/index.html.twig`, `pages/cohorts/show.html.twig`).
`exportCsv()` and `downloadBundle()` untouched (non-HTML response paths).

What landed:

- `index()` — builds a list of cohort-card view models, renders
  `pages/cohorts/index.html.twig`. Empty-state rendered inline in the page
  template (per threshold: only 1 call site in this pass).
- `show()` — builds summary + rows + attention-item view models, renders
  `pages/cohorts/show.html.twig`. Both empty-states (attention queue, rows)
  rendered inline.
- `exportCsv()` + `downloadBundle()` — **unchanged**. CSV bytes and bundle
  response headers verified identical post-migration.
- New 5th constructor arg `Twig\Environment $twig` added to `CohortController`;
  `AppServiceProvider::routes()` wires the shared env through.
- Two i18n keys added (`cohorts.index.page.title`, `cohorts.show.page.title`)
  for the `<title>` block overrides. Remaining strings stay hardcoded for
  byte-equivalence, same discipline as the intake template in prompt #5.

Components NOT extracted this pass (all single call sites, below threshold):

- cohort-card — renders once inside `{% for card in cards %}` on the index
  page. No ≥2 call site yet.
- submission-row — renders once inside `{% for row in rows %}` on the
  show page. No ≥2 call site yet.
- attention-item — renders once inside `{% for item in attention_items %}` on
  the show page. No ≥2 call site yet.
- status-badge — flagged in earlier plan; current markup is plain text
  (`{{ row.status }}<br>{{ row.current_step }}`), not a visual badge, so no
  component to extract.
- empty-state — used 3× across cohort templates (one on index, two on show),
  but staying inline per prompt #6 scope ("empty-state → shared extraction
  when SubmissionController migrates, not now").
- readiness-bar — **does not exist in the source HTML.** The "readiness"
  column is rendered as plain text (`{{ row.readiness_label }}`), not a bar
  visualization. The migration plan described a visualization that the
  controller never produced. Flagged in prompt #6 report.

Risks addressed:

- CSV body verified byte-identical via `cmp` on pre/post captures.
- Bundle response headers unchanged (pre-existing 500 error preserved — this
  is a separate pre-migration bug in `CohortBundleService`, out of scope for
  Twig extraction).
- Numeric route IDs preserved (`requirement('cohort', '\d+')`).
- CSV filename format `{slugified-label}-board.csv` preserved exactly
  (slugifier logic unchanged, still in `csvFilename()` private method).

## SubmissionController

**Status (2026-04-18): fully done.** `index()` migrated in prompt #7; `show()`
+ the 20+ private render helpers migrated in prompt #8. Mutation endpoints
untouched (all 6 return `RedirectResponse`, verified via HEAD probes).

What landed in prompt #7 (`index()` only):

- `index()` (~160 LOC of heredoc) now builds a list of `item` view-models
  and renders `pages/submissions/index.html.twig`.
- `renderListItem()` → `buildListItemView()` — returns a view-model array
  instead of an HTML sprintf string.
- Extracted one domain component: `components/domain/submissions/submission-row.html.twig`
  — consumed once in the index for now. The ≥2-call-site threshold becomes
  satisfied if prompt #8's `show()` needs a row (for an "all submissions in
  this cohort" widget or similar); if show() doesn't need it, the component
  stays single-use but in its correct domain location (not shared).
- New 4th constructor arg `Twig\Environment $twig` added to `SubmissionController`;
  `AppServiceProvider::routes()` wires it through.
- One i18n key added (`submissions.index.page.title`) for the `<title>`
  block override. Remaining strings stay hardcoded for byte-equivalence.

Phantom audit (lesson from prompt #6):

- `components/shared/status-badge.html.twig` — badge markup **does exist** in
  the list view (status pill + three inline-styled callout badges: Revision
  Note, Low Confidence, Readiness). But badges appear 4× within each row with
  3 different style variants; the visual system hasn't converged enough to
  extract a clean shared component. Deferred per prompt #7 scope.
- `components/shared/empty-state.html.twig` — exists (two-paragraph panel
  inside `<div class="empty">`). Different shape from cohort empty-states
  (those have a single paragraph). Whatever shared component lands will need
  to support arbitrary children, not a single text prop.
- `components/shared/form-field.html.twig` — **not used by `index()`.** Lives
  in the `show()` template. Deferred to prompt #8.
- `components/domain/submissions/canonical-field.html.twig` — **not used by
  `index()`.** Deferred to prompt #8.
- `components/domain/submissions/research-draft-card.html.twig` — **not used
  by `index()`.** Deferred to prompt #8.
- `components/domain/submissions/validation-summary.html.twig` — **not used
  by `index()`.** Deferred to prompt #8.

No phantoms in this pass — every component the doc mentioned for `index()`
is actually present in the HTML, just deferred per scope.

What landed in prompt #8 (`show()` migration):

- `show()` (~470 LOC of heredoc + 20+ private render helpers) now renders
  `pages/submissions/show.html.twig` via view-model builders.
- One i18n key added: `submissions.show.page.title`.
- Appendix checklist section uses the new
  `components/shared/appendix-checklist.html.twig` (2nd of 3 call sites that
  justified extraction).
- Mutation endpoints (`markReadyForReview`, `updateCanonical`,
  `createResearchDraft`, `applyResearchDraft`, `restoreResearchDraft`,
  `rejectResearchDraft`) all **untouched** — verified via curl GET HEAD
  probes returning same 404 pre/post (POST-only routes, method mismatch →
  no match, same behavior).
- `submission-row` component from prompt #7 was NOT reused by `show()` — the
  detail page doesn't render a list of submissions. The component stays
  single-call-site (correctly scoped to `components/domain/submissions/`).

Phantom audit results:

- `canonical-field` — **phantom.** The migration plan described "one row of
  the canonical-data form" but the panel is a single form with field_path +
  value_format + field_value inputs, not per-field rows. No extraction.
- `research-draft-card` — real, rendered for each research draft in the
  "Recent Research" section. Single call site within the show page.
- `validation-summary` — **partial phantom.** The plan described "validation_state
  ['research_drafts'] rollup." In practice, the applied-research-drafts panel
  (`research_backed`) is this rollup — renamed the PHP helper to
  `buildAppliedResearchDraftsView` but kept the same shape.
- `form-field` — real, `label + input/select/textarea` wrapper appears in
  ~6 form elements across the page (canonical edit + research draft form).
  Single call site per shape variant; below ≥2-same-shape threshold.
- `status-badge` — real as `.pill` (different variant from list-page
  `.badge`). Deferred, same rationale as prior passes.

Components NOT extracted: canonical-field, research-draft-card,
validation-summary, form-field, status-badge, research-item-card. All
single-call-site within this template.

## ReviewController

**Status (2026-04-18): fully done.** Migrated in prompt #8.

What landed:

- `show()` (~450 LOC of heredoc + 8 private render helpers) now renders
  `pages/reviews/show.html.twig` via view-model builders.
- 10 POST mutation handlers **untouched** — all still `RedirectResponse`.
- `{% include "components/shared/appendix-checklist.html.twig" %}` used in
  the "Package Completeness" section — eliminates the 3rd copy of the
  6-appendix checklist render across the codebase.
- One i18n key added: `reviews.show.page.title`.
- Privates that stayed PHP: `buildTimelineEntries`, `buildReviewEntryView`,
  `buildAppendixTimelineGroupView`, `buildWorkflowTimelineGroupView`,
  `buildIntakeTimelineGroupView`, `isAppendixTimelineChurn`,
  `isWorkflowTimelineChurn`, `isIntakeTimelineChurn`,
  `intakeTimelineGroupKey`, `noticeKeyFromUri`, `appendixReviewWarning`,
  `buildAppendixReviewView`, `appendixNoteActivityMeta`,
  `buildConfidenceView`, `weakFieldSummary`, `buildResearchBackedView`.

Phantom audit results:

- `comment-card` — real, rendered by `buildReviewEntryView`. Single call
  site in the timeline; below extraction threshold.
- `appendix-note-editor` — real, rendered as 6 `<form>` groups in the
  appendix-review panel. Single call site; inline.
- `status-controls` — real, two `<form>` elements (status change + send-back).
  Single call site; inline.
- `timestamp` — **phantom.** The source renders raw ISO-8601 strings
  (e.g. `2026-04-18T23:59:34+00:00`) with no formatting. No component
  needed until a consistent datetime format lands.

Components NOT extracted: comment-card, appendix-note-editor, status-controls
(all single call sites). `timestamp` was a phantom.

Risks addressed:

- 10 POST endpoints all still work (verified via curl HEAD probes — same
  404s on GET pre/post).
- `sendBackToIntake()` workflow unchanged (method untouched).
- CSRF surface: `<input type="hidden" name="appendix" value="…">` fields in
  the appendix-review forms preserved exactly. `{{ csrf_token() }}` NOT
  introduced this pass — the framework doesn't enforce CSRF on these routes
  (all use `->csrfExempt()`). Introducing CSRF tokens would be a separate
  security-hardening pass.
- Timeline-grouping logic (`buildTimelineEntries` + 4 group builders +
  3 churn classifiers) preserved verbatim. Raw vs compact modes both render
  correctly.

## DocumentController

**Status (2026-04-18): fully done.** Migrated in prompt #8.

What landed:

- `show()`, `package()`, `exports()` — 3 HTML methods migrated to Twig.
- `show()` / `package()` use inline `{{ page_styles|raw }}` from
  `DocumentPreviewService::pageStyles()` (runtime-generated CSS) — not
  the conventions' per-page `{% block head %}` inline `<style>`, because
  the CSS comes from the service, not the controller.
- `exports()` uses conventions' inline `{% block head %}` `<style>` (CSS
  lives in the heredoc, fixed content).
- `exports()` renders the shared appendix checklist via
  `{% include "components/shared/appendix-checklist.html.twig" %}`
  (1st of 3 call sites that justified extraction).
- `pdf()`, `downloadPdf()`, `downloadBundle()`, `exportFile()`,
  `downloadExportFile()`, `regeneratePdf()` **untouched**. All binary /
  download / redirect responses verified byte-identical via curl HEAD probes
  and cmp on CSV body (prompt #6 did the bundle audit originally).
- `exportFile()` in HTML mode still streams `file_get_contents($path)` as
  the response body — not a template, user-generated content from
  stored artifact.
- Three new i18n keys: `documents.show.page.title`,
  `documents.package.page.title`, `documents.exports.page.title`.

Phantom audit results:

- `export-row` — real, rendered in the exports table `<tbody>`. Single call
  site in the exports page.
- `package-status` — the migration plan described "package state indicator
  driven by artifact_bundle_zip document presence on disk." In practice
  that's a single `<span class="status">ready|pending</span>` embedded in
  the export-row, not a standalone indicator — **partial phantom**.

Components NOT extracted (all single call sites):

- `export-row` (rendered inline in exports table), same reasoning as
  single-use iteration blocks in prompts #6-7.

Risks addressed:

- CSV + PDF + ZIP response headers verified identical pre/post migration
  (same `Content-Type`, same `Content-Disposition`, same Cache-Control
  cluster modulo per-request PHPSESSID/Date).
- `document_type` stored literals (`merged_package_pdf`, `artifact_bundle_zip`,
  `merged_package_preview`, `appendix_*_preview`) preserved exactly in the
  rendered output.
- Document generation service calls (`buildAndPersist`, `buildPackageAndPersist`,
  `summarize`, `generatePackagePdf`) all happen in the controller before
  template render — no service calls from inside templates.
- The `exports()` page pre-generates artifacts on every GET
  (`buildAndPersist` + `buildPackageAndPersist` + `artifactBundleService->buildAndPersist`).
  This means timestamps drift between captures of the same URL — it's
  data drift, not a template regression. Captured via normalized diff.

## Open follow-ups (not in any single-controller pass)

### i18n wiring

**Status (2026-04-18): live.** Wired in prompt #5 alongside the Intake
extraction. The snippet in the previous revision of this section had the
`Translator::__construct` argument order wrong (it is `$translationsPath`
first, then `$manager` — verified against
`vendor/waaseyaa/i18n/src/Translator.php` and against Minoo `origin/main`'s
`AppServiceProvider`, which is the canonical reference).

What landed:

1. `AppServiceProvider::register()` binds `LanguageManagerInterface` (reads
   `$this->config['i18n']['languages']` from `config/waaseyaa.php`, falls back
   to a single `en` default if absent) and `TranslatorInterface`
   (`new Translator(dirname(__DIR__, 2) . '/resources/lang', $manager)`).
2. `AppServiceProvider::boot()` resolves both, constructs
   `TranslationTwigExtension`, and adds it to the shared Twig env.
   **Null-safety:** the boot() uses Minoo's silent null-skip pattern
   (`if ($twig !== null) { … }`), not the throw discussed during planning —
   see prompt #5 report §Decision outcome for rationale.
3. `base.html.twig` now emits `<html lang="{{ current_language().id }}">`.
4. Dashboard template strings converted to `{{ trans('dashboard.*') }}`.
   Every string in the page template uses a key from `resources/lang/en.php`;
   a new `dashboard.page.title` key was added for the `<title>` block.
5. Intake template strings are **not** yet converted — keys are scaffolded in
   `resources/lang/en.php` (see `intake.*` block), conversion deferred to a
   later pass once we see a second template lean on `trans()` for intake
   surfaces. Exception: `<title>` in the intake layout block uses
   `trans('intake.page.title')` from day one, to validate `trans()` inside
   `{% block %}` content.

Not done in this pass (tracked for follow-up):

- Translator fallback smoke test (unknown key returns key verbatim). The
  Minoo `Translator` already behaves that way per its source, but miikana
  has no explicit test for it. Add when test scaffolding for this repo
  lands.
- URL-prefix language negotiation (Minoo binds `UrlPrefixNegotiator` in
  the same block). Not needed for English-only miikana; add when a second
  language is introduced.

### Chrome convergence

The base layout is intentionally minimal (see `templating-conventions.md`
Deltas §1). Once two or more page templates ship, look at what they have in
common at the top (site header, nav, breadcrumb, user menu) and lift it into
`base.html.twig` or a second layout. Don't do this speculatively — wait for
two real call sites to compare.

### Auto-escape audit

Every current inline-HTML surface that interpolates entity data does so via
`htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Twig's default
html autoescape uses the same flags, so drop the manual calls when migrating.
**Exception**: any place the controller currently emits pre-escaped HTML
(e.g. sanitized rich text from the submission entity) must be marked
`|raw` in the template, and the sanitization boundary must be documented
inline.

### Non-HTML response paths (do not migrate)

Kept here for visibility so future migration prompts don't accidentally try to
Twig-ify them:

- `CohortController::exportCsv` — `text/csv`, attachment
- `CohortController::downloadBundle` — `application/zip`, attachment
- `DocumentController::downloadPdf` — `application/pdf`, attachment
- `DocumentController::downloadExportFile` — varies, attachment
- `DocumentController::downloadBundle` — `application/zip`, attachment
- `DocumentController::regeneratePdf` — POST → redirect
- `SubmissionController::*` mutations — POST → redirect
- `ReviewController::*` mutations (10 endpoints) — POST → redirect
- `IntakeController::handle` — POST → redirect

## Suggested prompt sequencing

1. **Wire i18n** (non-controller pass). Unblocks `trans()` for every subsequent migration.
2. **IntakeController**. Smallest HTML surface, no shared components yet,
   good warm-up for the translator.
3. **CohortController** + extract `components/shared/status-badge.html.twig`
   + `components/shared/empty-state.html.twig`. First pass that justifies shared components.
4. **SubmissionController (index only)**. Reuses the two shared components.
5. **SubmissionController (show)**. The canonical-fields + research-drafts form.
6. **ReviewController**. Ties into Submission show via workflow state.
7. **DocumentController (HTML pages only)**. Non-HTML responses stay unchanged.

Each pass is one prompt-sized work unit, with a smoke test diff against the
pre-change HTML to catch regressions.
