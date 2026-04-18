# Review Subsystem — Operational Spec

Descriptive. Documents behavior at HEAD (2026-04-18). Behavior-change proposals belong in `docs/specs/_proposals/review.md`.

## 1. Overview

"Review" is the staff-facing side of a submission: Education Department / Economic Development reviewers capture comments, mark appendix artifacts as reviewed, walk the submission through status transitions, and send submissions back to intake when revisions are needed. The review UI is styled as a "Staff Review" cockpit (`src/Controller/ReviewController.php:334-336`). This differs from intake: intake is applicant-driven (the participant answers the deterministic intake conversation), while review is operator-driven — every review action writes to the `proposal_review` audit log keyed to a submission. There is no separate "reviewer" role today; routes are `allowAll()` (`src/Provider/AppServiceProvider.php:320,331,etc.`).

## 2. Current contract

### Comments

- **Add:** POST `/submissions/{submission}/review/comment` → `ReviewController::addComment` (`src/Controller/ReviewController.php:457-471`). Reads `comment`, `section_key`, `field_path` from the request, delegates to `ProposalReviewService::addComment` (`src/Domain/Review/ProposalReviewService.php:91-123`), which creates a `proposal_review` row with `action_type='comment'`.
- **Edit / delete:** **not implemented.** No controller method, no route, no service method. Comments are append-only.
- **Threading:** none. Each comment is a flat `proposal_review` row. No `parent_review_id` field on `ProposalReview` (`src/Entity/ProposalReview.php:21-30`).
- **Author metadata:** `proposal_review` has a `reviewer_uid` integer field (`src/Entity/ProposalReview.php:24`). It is populated by every write site but **hardcoded to `1`** everywhere: `ProposalReviewService::addComment` defaults `$reviewerUid = 1` (`ProposalReviewService.php:96`), as do `transitionStatus`, `sendBackToIntake`, `clearAppendixNote`, `restoreAppendixNote`, `markAppendixReviewed`, `clearAppendixReviewed`, `recordSystemAction` (lines 343, 384, 284, 310, 442, 484, 602). The seed importer also writes `reviewer_uid = 1` (`src/Domain/Import/NorthOpsSeedImporter.php:412`). There is no session-to-`reviewer_uid` wiring.

### Appendix notes

- **Scope:** each appendix note targets one of the six fixed appendix codes `A | B | F | G | H | M` (`ReviewController.php` validation at line 477-479; service validation at `ProposalReviewService.php:293-295`).
- **Storage:** notes are `proposal_review` rows with `section_key='appendix_review'` and `field_path='appendix.<letter>'`. Not a separate table. Fresh notes use `action_type='comment'` (reuses `addComment` with the fixed section/field — `ReviewController.php` addAppendixNote at lines 473-492).
- **Clear (tombstone):** POST `/submissions/{submission}/review/appendix/note/clear` → `ReviewController::clearAppendixNote` → `ProposalReviewService::clearAppendixNote` (`ProposalReviewService.php:281-306`) writes a new row with `action_type='appendix_note_cleared'`. Original notes are **never deleted**; the tombstone is a marker in the append-only log.
- **Restore:** POST `/submissions/{submission}/review/appendix/note/restore` → `ReviewController::restoreAppendixNote` → `ProposalReviewService::restoreAppendixNote` (`ProposalReviewService.php:307-337`). Walks the review history via `recoverableAppendixNotes` (`ProposalReviewService.php:239-279`) to find the most recent `comment` or `appendix_note_restored` that sits **before** the latest `appendix_note_cleared`, then emits a new row with `action_type='appendix_note_restored'`.
- **Latest-visible note:** `ProposalReviewService::latestAppendixNotes` (`ProposalReviewService.php:155-199`) scans newest-first; if the most recent matching row is `appendix_note_cleared` it returns an empty shape, otherwise the latest `comment` / `appendix_note_restored`.
- **Routes:** `submissions.review.appendix.note` (line 452), `.clear` (463), `.restore` (474) in `AppServiceProvider.php`.

### Appendix review state

- **State store:** `proposal_submission.validation_state` JSON, key `reviewed_appendices`, shape `{<letter>: {reviewed: bool, reviewed_at: string, reviewer_uid: int}}` (`ProposalReviewService.php:459-469`). Not a dedicated column.
- **Mark reviewed:** POST `/submissions/{submission}/review/appendix/review` → `markAppendixReviewed` (`ProposalReviewService.php:439-479`). Updates `validation_state` and emits a `proposal_review` with `action_type='appendix_reviewed'`.
- **Clear reviewed:** POST `.../appendix/clear` → `clearAppendixReviewed` (`ProposalReviewService.php:481-521`). Removes the key and emits `action_type='appendix_review_cleared'`.
- **Bulk ops:** `markAllAppendicesReviewed` / `clearAllAppendixReviews` routes at `AppServiceProvider.php:529,540`. Iterate the six appendices.
- **Gate:** `allAppendicesReviewed` (`ProposalReviewService.php:647`) — required-true before `transitionStatus` allows moving to `approved`, `exported`, or `submitted` (`ProposalReviewService.php:355-361`).

### Status transitions

- **Status values observed** (string literals):
  - `draft`, `intake_in_progress`, `ready_for_review`, `revisions_requested`, `approved`, `exported`, `submitted` (seen in `stepForStatus` `ProposalReviewService.php:670-680` and the status-dropdown markup in `ReviewController.php:357-361`).
  - The status `<select>` in the review cockpit only offers `approved`, `exported`, `submitted` (`ReviewController.php:357-361`). Other values are set elsewhere: `intake_in_progress` is entered by `sendBackToIntake`; `ready_for_review` is entered by `SubmissionController::markReadyForReview` via route `submissions.ready_for_review` (`AppServiceProvider.php:327-335`).
- **Where read:** `ProposalReviewService::summarizeSubmission` (`ProposalReviewService.php:37,49-52`). The return shape exposes `status`, `current_step`, `has_revisions_requested` (computed: `$status === 'revisions_requested'` — line 51), `is_approved` (computed: `in_array($status, ['approved','exported','submitted'])` — line 52). **Neither flag is a stored field on `proposal_submission`** (`src/Entity/ProposalSubmission.php:21-43`).
- **Where written:** only `ProposalReviewService::transitionStatus` (`ProposalReviewService.php:339-379`) calls `$submission->set('status', ...)` and `$submission->set('current_step', $this->stepForStatus($status))`. Every write also emits a `proposal_review` row with `action_type='status_change'`, `section_key='workflow'`, `field_path='status'`.
- **Transition gate:** moving to `approved | exported | submitted` requires `allAppendicesReviewed` — otherwise `\InvalidArgumentException` (`ProposalReviewService.php:355-361`). No other transitions are gated; any string is otherwise accepted.
- **`current_step` mapping** (`ProposalReviewService.php:670-680`): `intake_in_progress→intake`, `ready_for_review→review`, `revisions_requested→revisions`, `approved→approved`, `exported→exported`, `submitted→submitted`, default `structured_data`.
- **Endpoints:** `submissions.review.status` (POST, `AppServiceProvider.php:485`), `submissions.review.send_back` (POST, line 496), `submissions.ready_for_review` (POST, line 327) — the last one lives in `SubmissionController`, not `ReviewController`.

### Send-back-to-intake

- POST `/submissions/{submission}/review/send-back` → `ReviewController::sendBackToIntake` (`ReviewController.php:563-575`) → `ProposalReviewService::sendBackToIntake` (`ProposalReviewService.php:381-400`).
- **Required note** — empty input throws `InvalidArgumentException` (`ProposalReviewService.php:387-389`). The UI copy stresses this ("A note is required and becomes part of the audit trail." — `ReviewController.php:375`).
- **Mechanics:** delegates to `transitionStatus($submissionId, 'intake_in_progress', $trimmed, $reviewerUid)` (`ProposalReviewService.php:391-397`). That is the **entirety** of the operation.
- **Fields reset:** none. **Fields preserved:** all. `research_log`, `validation_state.reviewed_appendices`, `confidence_state`, `completion_state`, `canonical_data`, `intake_transcript`, and any prior appendix notes are untouched. No `has_revisions_requested` flag is set (there is no such stored field). Re-entry to intake is signalled solely via `status='intake_in_progress'` and `current_step='intake'`.

### Access control

Every review route in `AppServiceProvider.php` uses `->allowAll()` (see `AppServiceProvider.php:431-551` and `AUDIT.md` §11). No authentication, no authorization, no per-org or per-role gating. The replacement policy shape is documented in `docs/migrations/tenancy-migration.md` §5 (role-gated entity-level actions: "Submission approve / request revisions: `admin` in the owning org") and §4 (ownership backfill: existing `proposal_review` rows without a real user get the seed user).

## 3. Invariants

- **`action_type` values are stable identifiers.** They are read by UI, analytics, and history walkers: `'comment'`, `'appendix_note_cleared'`, `'appendix_note_restored'`, `'appendix_reviewed'`, `'appendix_review_cleared'`, `'status_change'`, `'system'`. Renaming any breaks `recoverableAppendixNotes` (`ProposalReviewService.php:256,265`), `latestAppendixNotes` (lines 175, 184), the summary pipeline, and the seed importer.
- **`section_key='appendix_review'` + `field_path='appendix.<LETTER>'` is load-bearing.** The regex `/^appendix\.([ABFGHM])$/` (`ProposalReviewService.php:165,250`) is the sole discriminator for appendix-note history. Changing format orphans the note history.
- **Appendix set is fixed to `A,B,F,G,H,M`.** Validated at both controller (`ReviewController.php:478,497,513,etc.`) and service boundaries. Adding an appendix touches >10 sites.
- **Review log is append-only.** Clear + restore are both new rows. No `UPDATE` and no `DELETE` paths exist on `proposal_review`. History walkers assume this.
- **Status → `current_step` is derived, not independently writable.** Only `transitionStatus` sets `current_step`, always via `stepForStatus`. Any code that writes `status` directly without going through `transitionStatus` leaves `current_step` stale.
- **`has_revisions_requested` and `is_approved` are computed, not stored.** Derived in `summarizeSubmission` from `status`. Do not treat them as fields.
- **Appendix-reviewed gate is strict for terminal statuses.** `approved | exported | submitted` require all six appendices reviewed.
- **`reviewer_uid` is always written but always `1` today.** Field exists; values are placeholder.

## 4. UI strings and log tags

| String / tag | preserve | rebrand |
|---|---|---|
| `action_type='comment'` (data) | preserve | — |
| `action_type='appendix_note_cleared'` | preserve | — |
| `action_type='appendix_note_restored'` | preserve | — |
| `action_type='appendix_reviewed'` | preserve | — |
| `action_type='appendix_review_cleared'` | preserve | — |
| `action_type='status_change'` | preserve | — |
| `action_type='system'` | preserve | — |
| Status values `draft | intake_in_progress | ready_for_review | revisions_requested | approved | exported | submitted` | preserve | — |
| `section_key='workflow'`, `field_path='status'`, `section_key='appendix_review'` | preserve | — |
| Route names (`submissions.review`, `submissions.review.comment`, `submissions.review.send_back`, etc.) | preserve | — |
| Page title "Staff Review" eyebrow (`ReviewController.php:333`) | — | rebrand |
| Hero copy "This cockpit is for Education Department and Economic Development review activity…" (`ReviewController.php:335`) | — | rebrand (org-specific) |
| "Return To Intake" heading, "Send Back To Intake" button (`ReviewController.php:374,382`) | — | rebrand |
| Error query strings (`?error=comment`, `?error=appendix-note`, `?error=send-back`, `?error=status`, `?error=appendix`) | preserve | — |
| Auto-generated status note "Submission moved to {status}." (`ProposalReviewService.php:376`) | — | rebrand |
| "Low-confidence fields remain: …" appended note (`ReviewController.php:552-553` approx) | — | rebrand |

## 5. Integration seams

### Connector seam

Reviewers do **not** apply connector results through `ReviewController`. The research-draft apply path is applicant-side and lives at `SubmissionController::applyResearchDraft` (route `submissions.research.apply`, `AppServiceProvider.php:295-304` per `AUDIT.md` §6; see also `src/Controller/SubmissionController.php` ref at `SubmissionController-research:730-743`). A reviewer who wants to cite a connector hit can today only do so by pasting into a `comment` free-text field — there is no structured connector→review attachment. Boundary: keep connector ingestion applicant-side; review stays a commentary surface.

### Tenancy seam

Today every review route carries `->allowAll()` in `AppServiceProvider.php`. The replacement landing site has two options:

- **Controller-level:** replace each `->allowAll()` with `->authenticated()` on every `submissions.review*` route (route builder call-sites at `AppServiceProvider.php:431, 441, 452, 463, 474, 485, 496, 507, 518, 529, 540`). Cheapest, uniform.
- **Service-level:** add `AccessPolicyInterface` checks inside `ProposalReviewService` write methods (addComment, transitionStatus, sendBackToIntake, markAppendixReviewed, clearAppendixReviewed, clearAppendixNote, restoreAppendixNote). Required additionally for approve-grade transitions — `tenancy-migration.md` §5 says "Submission approve / request revisions: `admin` in the owning org".

Current single-role assumption: there is no "reviewer" role distinct from operator/admin. Per `tenancy-migration.md` §3 / §5, reviewer actions map onto the `admin` role of the owning organization. The `reviewer_uid` column already exists on `proposal_review` (`src/Entity/ProposalReview.php:24`) but is hardcoded to `1` at every write site — the real change is wiring the authenticated user id into each `*_reviewerUid` parameter, not adding the column. `tenancy-migration.md` §4 describes this addition but does not note that the column is already present (see AUDIT addendum below).

### Seed extraction seam

`NorthOpsSeedImporter::seedDemoReviewActions` (`src/Domain/Import/NorthOpsSeedImporter.php:372-421`) seeds **two** demo `proposal_review` rows — one for "Mino-Awiiyaas Catering" and one for "Nookomis Textiles Studio" — both with `action_type='comment'`, `reviewer_uid=1`, and a hardcoded `created_at='2026-04-15T…'`. It is idempotent: early-exit if any review already exists for the submission (lines 374-381). All other reviews (status changes, appendix marks, notes) are created by staff interaction post-seed. The importer's final invocation of `seedDemoReviewActions` is at `NorthOpsSeedImporter.php:333`.

## 6. Open questions

1. **Is comment threading expected in MVP?** Nothing today supports it; the UI presents a flat list. Product decision needed before tenancy rework.
2. **Does send-back-to-intake need to reset `has_revisions_requested` or any state?** Currently it sets no flag. The summary surfaces `has_revisions_requested` only for the literal `revisions_requested` status — but the send-back path transitions to `intake_in_progress`, not `revisions_requested`. So "reviewer requests revisions" and "sent back to intake" are distinct statuses with no shared marker.
3. **Is `revisions_requested` ever actually used?** `stepForStatus` maps it, `summarizeSubmission` reads it, but no code path writes it. Either wire an explicit "Request revisions" action or drop the status.
4. **Should `edit` and `delete` for comments be supported?** Append-only today; if added, the history walkers must remain compatible (tombstone model, not hard delete).
5. **Are appendix notes org-scoped or submission-scoped?** Today strictly per-submission (via `submission_id` on `proposal_review`). Tenancy layer should treat them as submission-scoped and inherit the submission's `organization_id`.
6. **Should the appendix-set (`A,B,F,G,H,M`) be pipeline-configurable?** Today hardcoded in four places. Per-org pipelines (per `rename-to-miikana.md`) will eventually want different appendix schemes.
7. **Should terminal-status gate (all-appendices-reviewed) apply to `submitted`?** Currently yes; `submitted` looks more like an applicant-side state than a reviewer outcome — may be a historical leak.
