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
| IntakeController | 546 | 2 | 2 | medium — one full page + POST redirect | 1 |
| CohortController | 540 | 4 | 5 | medium — list + detail page, plus 2 non-HTML responses | 1 |
| SubmissionController | 1886 | 8 | 5 | high — list + detail, many mutation endpoints, ready-for-review flow | 2 |
| ReviewController | 1139 | 11 | 2 | high — review cockpit with comments/appendix notes, 10+ POST handlers, CSRF surface | 2 |
| DocumentController | 902 | 9 | 10 | high — preview HTML, PDF bytes, ZIP bundles, file downloads (mostly non-HTML) | 2 |
| **Total** | **5,013** | **34** | **24** | — | **~8** |

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

- File: `src/Controller/CohortController.php` (540 lines, 4 public methods).
- Routes:
  - `cohorts.index` — GET `/cohorts` → `index()`
  - `cohorts.show` — GET `/cohorts/{cohort}` → `show($cohort)`
  - `cohorts.export.csv` — GET `/cohorts/{cohort}/export.csv` → `exportCsv($cohort)`
  - `cohorts.bundle.download` — GET `/cohorts/{cohort}/bundle/download` → `downloadBundle($cohort)`
- Inline-HTML complexity: **medium**. Two HTML pages (list, detail with
  readiness/approval/weak-field metrics); two non-HTML responses.
- Proposed page templates:
  - `templates/pages/cohorts/index.html.twig`
  - `templates/pages/cohorts/show.html.twig`
- Component opportunities:
  - Extract to `templates/components/shared/` (≥2 call sites once Submissions
    also migrates):
    - `components/shared/status-badge.html.twig` — consumes a `status` prop
      (`draft`, `intake_in_progress`, `ready_for_review`, `approved`)
    - `components/shared/empty-state.html.twig` — no-cohorts / no-submissions panel
  - `templates/components/domain/cohorts/readiness-bar.html.twig` — the
    weak-field / readiness-percentage visualization on the detail page
- Risks:
  - `exportCsv()` returns `text/csv` with `Content-Disposition: attachment`.
    **Do not move it to Twig** — keep the CSV-building code in PHP, return
    `Response` with raw body as today.
  - `downloadBundle()` streams a ZIP. Same: **not a Twig candidate**.
  - Route IDs are numeric (`requirement('cohort', '\d+')`); preserve in the
    migration.

## SubmissionController

- File: `src/Controller/SubmissionController.php` (1886 lines, 8 public methods).
  Largest HTML surface in the app.
- Routes (GET pages only — mutation endpoints return redirects):
  - `submissions.index` — GET `/submissions`
  - `submissions.show` — GET `/submissions/{submission}`
  - mutation endpoints: `markReadyForReview`, `updateCanonical`,
    `createResearchDraft`, `applyResearchDraft`, `restoreResearchDraft`,
    `rejectResearchDraft` (all POST, all redirect)
- Inline-HTML complexity: **high**. Two distinct HTML pages; the detail page
  is dense (canonical fields form, research drafts panel, validation state,
  appendix set). Embedded CSS + inline forms.
- Proposed page templates:
  - `templates/pages/submissions/index.html.twig`
  - `templates/pages/submissions/show.html.twig`
- Component opportunities:
  - `components/shared/status-badge.html.twig` (also used by Cohorts — extract
    in the first of the two passes that touches it)
  - `components/shared/empty-state.html.twig`
  - `components/shared/form-field.html.twig` — label + input + error wrapper
  - `components/domain/submissions/canonical-field.html.twig` — one row of
    the canonical-data form
  - `components/domain/submissions/research-draft-card.html.twig` — one entry
    in the research-drafts panel with apply/reject/restore actions
  - `components/domain/submissions/validation-summary.html.twig` — the
    `validation_state['research_drafts']` rollup
- Risks:
  - Mutation endpoints do not render HTML — they redirect. Leave them alone
    (they stay `RedirectResponse`). Only `index()` and `show()` need templates.
  - Validation-state structure has nested keys (`validation_state['research_drafts']`).
    Template must read them without flattening, since downstream code depends
    on the same nesting.
  - Stored-data literals that appear in UI (status strings, appendix letters
    A/B/F/G/H/M) must render unchanged. Use the raw values from the submission
    entity, do not introduce lookup maps in the template.
  - CSRF tokens on the POST forms: currently inlined as hidden fields by the
    controller (grep the heredoc for `<input type="hidden" name="_token"`).
    After migration, emit via `{{ csrf_token() }}` — this is the existing
    framework function, no change to validation behavior.
  - Split suggestion: do `index()` + shared-component extractions in pass A,
    then `show()` in pass B. 1886 lines in one pass risks a review surface
    too big to catch regressions.

## ReviewController

- File: `src/Controller/ReviewController.php` (1139 lines, 11 public methods).
- Routes:
  - GET `/submissions/{submission}/review` → `show()` (the only HTML render)
  - 10 POST endpoints: `addComment`, `addAppendixNote`, `clearAppendixNote`,
    `restoreAppendixNote`, `updateStatus`, `sendBackToIntake`,
    `markAppendixReviewed`, `clearAppendixReviewed`,
    `markAllAppendicesReviewed`, `clearAllAppendixReviews`
- Inline-HTML complexity: **high**. Single page template, but dense: review
  comments timeline, appendix note editor, status-change controls, send-back
  workflow. Stateful UI with many conditional branches.
- Proposed page templates:
  - `templates/pages/reviews/show.html.twig`
- Component opportunities:
  - `components/domain/reviews/comment-card.html.twig` — single comment with
    author + timestamp + body
  - `components/domain/reviews/appendix-note-editor.html.twig` — textarea +
    save/clear/restore buttons, one per appendix (A/B/F/G/H/M)
  - `components/domain/reviews/status-controls.html.twig` — approve /
    request-changes / send-back actions
  - `components/shared/timestamp.html.twig` — consistent datetime rendering
- Risks:
  - Many POST endpoints means many hidden-input CSRF token fields. Audit each
    form during migration; use `{{ csrf_token() }}`.
  - `sendBackToIntake()` changes submission workflow state
    (`ready_for_review` → `intake_in_progress`). Template must not accidentally
    pre-select or default the wrong state. Pure-template migration should be
    safe, but verify with a smoke test after migration.
  - 10 POST routes × 10 form elements on the page — a migration is large.
    Consider a single pass focused on the `show()` template + hidden CSRF
    fields, deferring the per-action components to a follow-up.

## DocumentController

- File: `src/Controller/DocumentController.php` (902 lines, 9 public methods).
- Routes:
  - GET pages:
    - `/submissions/{submission}/documents` → `show()` — preview panel
    - `/submissions/{submission}/package` → `package()` — package list / regenerate UI
    - `/submissions/{submission}/exports` → `exports()` — exports index
    - `/submissions/{submission}/package/pdf` → `pdf()` — inline PDF preview
    - `/submissions/{submission}/exports/file/{document_type}` → `exportFile()`
  - Non-HTML / download routes:
    - `/submissions/{submission}/package/pdf/download` → `downloadPdf()` (PDF bytes)
    - `/submissions/{submission}/exports/file/{document_type}/download` → `downloadExportFile()`
    - `/submissions/{submission}/exports/bundle/download` → `downloadBundle()` (ZIP)
    - `/submissions/{submission}/exports/pdf/regenerate` → `regeneratePdf()` (redirect on POST)
- Inline-HTML complexity: **high**, but a lot of the 10 inline-HTML markers
  are PDF/ZIP response wrappers, not Twig candidates.
- Proposed page templates:
  - `templates/pages/documents/show.html.twig`
  - `templates/pages/documents/package.html.twig`
  - `templates/pages/documents/exports.html.twig`
  - `templates/pages/documents/pdf-preview.html.twig` (optional — the
    inline PDF iframe/object wrapper, if it has one)
- Component opportunities:
  - `components/domain/documents/export-row.html.twig` — one export entry
    with download + regenerate actions
  - `components/domain/documents/package-status.html.twig` — package state
    indicator driven by `artifact_bundle_zip` document presence on disk
- Risks:
  - **Do not migrate** `downloadPdf`, `downloadExportFile`, `downloadBundle`
    to Twig. They stream bytes with specific `Content-Type` (`application/pdf`,
    `application/zip`) and `Content-Disposition: attachment` headers. They
    must keep returning raw `Response` with binary bodies.
  - `pdf()` — if it renders an HTML wrapper around an `<iframe src>` to a
    download URL, the wrapper itself is Twig-friendly. If it streams the PDF
    bytes directly, leave it.
  - `document_type` is a stored literal (e.g. `artifact_bundle_zip`,
    specific export types). Preserve the exact literal strings when rendering.
  - Document generation is backed by `ArtifactBundleService` +
    `ArtifactAuditService` + `DocumentPreviewService`. Templates consume
    read-only DTOs; do not call generation services from inside a template.

## Open follow-ups (not in any single-controller pass)

### i18n wiring

`resources/lang/en.php` exists as a scaffold only. Before any template can
switch from hardcoded strings to `{{ trans('...') }}`:

1. Register `TranslatorInterface` and `LanguageManagerInterface` bindings in
   `AppServiceProvider::register()`:
   ```php
   $this->singleton(\Waaseyaa\I18n\LanguageManagerInterface::class, fn () =>
       new \Waaseyaa\I18n\LanguageManager(/* languages from config/waaseyaa.php */));
   $this->singleton(\Waaseyaa\I18n\TranslatorInterface::class, fn () =>
       new \Waaseyaa\I18n\Translator(
           $this->resolve(\Waaseyaa\I18n\LanguageManagerInterface::class),
           /* loader that reads resources/lang/{locale}.php */
       ));
   ```
2. In `AppServiceProvider::boot()`, register the Twig extension on the shared
   environment:
   ```php
   $twig = \Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment();
   if ($twig !== null) {
       $twig->addExtension(new \Waaseyaa\I18n\Twig\TranslationTwigExtension(
           $this->resolve(\Waaseyaa\I18n\TranslatorInterface::class),
           $this->resolve(\Waaseyaa\I18n\LanguageManagerInterface::class),
       ));
   }
   ```
3. Switch `templates/layouts/base.html.twig` from `<html lang="en">` to
   `<html lang="{{ current_language().id }}">`.
4. Migrate Dashboard template strings to `{{ trans('dashboard.*') }}` calls,
   using the keys already in `resources/lang/en.php`.
5. Add a smoke test for the translator's fallback behavior (unknown key
   returns key verbatim, as Minoo's `Translator` does).

Do this **before** the next page template is extracted, so subsequent migrations
can use `trans()` from the start.

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
