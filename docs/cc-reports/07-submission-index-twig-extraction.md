# CC prompt #7 execution report

## Pre-flight summary

- Minoo reference checkout on `main` at `0a8b5e7` — unchanged since prompt #6, still good.
- i18n live-wire verified by rendering: `GET /` returns the English values (`NorthOps on Waaseyaa`, etc.), zero unresolved `dashboard.*` keys.
- DB already seeded from prompt #6 (submission ID 1 in worktree's `storage/waaseyaa.sqlite`). Preview server on `:55123` responding 200.
- No stash dance needed this time — I captured the `/submissions` baseline directly against the pre-change controller, made the changes, captured after. The prior `/submissions` response survived untouched through prompt #6 (which only touched Cohort routes), so the captured baseline is clean.

## Decision outcome

**One component extracted: `components/domain/submissions/submission-row.html.twig`** (14 LOC). Currently consumed at one call site — the index `{% for item in items %}` loop — but the prompt explicitly authorized this extraction on the basis that prompt #8's `show()` may reuse it.

**No other components extracted.** Status-badge and empty-state still deferred per scope. Badges appear 4× within each row's callout area with 3 different inline-styled variants; the visual system hasn't converged enough to extract cleanly yet. Empty-state would be shared with Dashboard / Intake / Cohorts / Submissions, but prompt #7 scope says wait.

Null-guard pattern from prompt #5 (silent-skip in `boot()`, throw in `routes()`) still inherited unchanged.

## Diffs — files changed with LOC delta

| File | Before | After | Δ | Notes |
|------|-------:|------:|-----:|-------|
| `src/Controller/SubmissionController.php` | 1886 | 1729 | −157 | `index()` heredoc eliminated; `renderListItem()` → `buildListItemView()` returning view-model. Other 7 public methods and 20+ private helpers **untouched**. Added `Twig\Environment $twig` as 4th constructor arg. |
| `src/Provider/AppServiceProvider.php` | 619 | 620 | +1 | One line: `$twig` passed as 4th arg to `new SubmissionController(…)`. |
| `templates/pages/submissions/index.html.twig` | — | 137 | +137 (new) | Inline `<style>` preserved verbatim in `{% block head %}`; empty-state inline; row rendering delegated to the component via `{% include %}`. |
| `templates/components/domain/submissions/submission-row.html.twig` | — | 14 | +14 (new) | Consumes one `item` prop (view-model from the controller). 3 conditional branches: `revision_note`, `weak_count > 0`, always-`readiness`. |
| `resources/lang/en.php` | 125 | 130 | +5 | One new key (`submissions.index.page.title`) plus 3 lines of comment explaining the scaffold discipline. |
| `docs/migrations/twig-extraction.md` | (updated) | | | SubmissionController row marked `index() done`, `show() + 6 mutations pending`. §SubmissionController section rewritten: what landed, phantom audit results, what's still pending for prompt #8. |

**Net code impact:** `−157` controller LOC, `+151` template LOC, `+1` DI wiring, `+5` i18n dictionary. The controller dropped more than the Cohort migration (prompt #6: −261 from 540) in absolute terms but less as a percentage (8% vs 48%) because SubmissionController has 20+ private helpers for `show()` that stay exactly as they were.

## Component decisions (rationale)

| Candidate | Source HTML presence | Decision | Rationale |
|---|---|---|---|
| `submission-row` (domain) | 1 call site (`{% for %}` on index) | **extracted** | Prompt explicitly authorized extraction on anticipation that `show()` might reuse. Threshold becomes met post-#8 if used there; if not, component stays correctly scoped to `domain/submissions/`. No downside to extracting now. |
| `status-badge` (shared) | 4× per row (status + 3 inline-styled callouts) | **inline** | 3 visual variants with inline `style=` overrides suggest the abstraction isn't yet stable. Deferred per prompt scope; revisit when Review/Document migrations land and we have more data points on what a "badge" means in this app. |
| `empty-state` (shared) | 1× on submissions/index (two-paragraph panel) | **inline** | Two paragraphs inside `<div class="empty">` — different shape from the single-paragraph cohorts empty-state. Whatever shared component ships will need a slot/block for arbitrary children, not a single text prop. Deferred per prompt scope; needs design thinking before extraction. |
| `form-field`, `canonical-field`, `research-draft-card`, `validation-summary` | **0 in `index()`** — all live in `show()` | **N/A this pass** | Correctly scoped to prompt #8. |

## Phantom audit

Lesson from prompt #6: check the migration doc's component candidates against actual source HTML before writing anything. Running that check against `index()`'s heredoc:

- `status-badge` — **real**, present as `<span class="badge">…</span>` 4× per row.
- `empty-state` — **real**, present as the `<div class="empty">…</div>` wrapper around the no-submissions panel.
- `form-field`, `canonical-field`, `research-draft-card`, `validation-summary` — **not in `index()` HTML**, but that's expected — they're tagged in the doc as `show()` candidates. Not phantoms; just scoped elsewhere.

**No phantoms found in the `index()` scope.** Migration doc is honest about what's in `index()` vs `show()`.

## Byte-equivalence results

### `GET /submissions`

- **Baseline: 4503 bytes. After: 4375 bytes.** (Δ −128 bytes, entirely whitespace.)
- `diff -w` output: 3-line chunk showing the long inline `<article>…</article>` split into 3 physical lines in the rendered output (Twig newline-preservation around `{% include %}` and `{% for %}` block markers). Semantic content identical — same elements, same attributes, same text, same link targets.
- `tr -d '\n\r\t '` normalized diff: **0 lines**.

### Mutation route wiring — 6 POST-only routes

Probed all 6 mutation endpoints pre and post migration with a `GET`:

| Route | Baseline | After |
|---|---|---|
| `GET /submissions/1/ready-for-review` | 404 | 404 |
| `GET /submissions/1/canonical` | 404 | 404 |
| `GET /submissions/1/research/draft` | 404 | 404 |
| `GET /submissions/1/research/drafts/abc/apply` | 404 | 404 |
| `GET /submissions/1/research/drafts/abc/reject` | 404 | 404 |
| `GET /submissions/1/research/drafts/abc/restore` | 404 | 404 |

The router returns 404 (no route match) rather than 405 (method not allowed) for method mismatches on these paths. Same behavior before and after — proves routing wiring is unchanged and the 6 POST handlers are still registered. No regression.

## Smells surfaced

1. **Badge inline styles are copy-pasted across 3 call sites** (`rgba(186, 90, 45, 0.12); color: #ba5a2d;` for rust-tinted badges; `rgba(49, 88, 69, 0.12); color: #315845;` for moss-tinted). When a `status-badge` component eventually lands, it should take a `variant` prop (`default | warning | success`) that maps to the CSS variants rather than passing raw inline styles. Flag for whoever extracts this.

2. **Review-meta string is built by `sprintf` with a conditional suffix.** In `buildListItemView()`:
   ```php
   sprintf('Reviews: %d · Reviewed appendices: %d/%d%s', …,
       ($reviewSummary['latest_created_at'] ?? null)
           ? ' · Latest: ' . (string) $reviewSummary['latest_created_at']
           : '',
   );
   ```
   This mixes display concern (the separator/prefix text) with data concern. Cleaner model: return the latest-at timestamp as a separate view-model field and let the template do the `… · Latest: {{ item.review_latest_at }}` conditional. Leaving as-is because prompt #8 may want a different review-meta rendering on `show()` — worth revisiting together.

3. **`weak_single` bool is a template-formatting concern leaking into the view-model.** In `buildListItemView()` I'm returning `'weak_single' => $weakCount === 1` so the template can pick "field needs follow-up" vs "fields need follow-up". This is awkward — the controller knows English grammar rules. Twig has a built-in way to handle this (`{% if item.weak_count == 1 %}…{% else %}…{% endif %}`), but that puts a literal `== 1` in the template. Either way works; the right answer when this eventually goes through `trans()` is ICU plural forms (`{count, plural, one {field} other {fields}}`) from the translator, not PHP-side grammar logic. Flag for the dedicated `trans()` pass.

4. **Cohort lookup hits DB on every row.** `buildListItemView()` calls `$this->entityTypeManager->getStorage('proposal_cohort')->load($cohortId)` inside the loop. For N submissions in different cohorts, that's N cohort lookups. Not a problem today (one submission), but a trivial N+1 when more submissions seed. The fix is a batched `loadMultiple` by unique cohort IDs collected in a first pass. Not in scope for Twig extraction, but flag: the controller hasn't been touched for performance since seed data grew to 1 row.

5. **"Canonical proposal state and imported source form data are attached to this submission." sentence is the same for every row.** It's rendered inside every `<article class="item">` as a template literal. That's boilerplate — the user reads it once, then their eyes skip it for every subsequent row. If there are ever more than a handful of submissions, this becomes visual noise. Candidate for removal (or a collapsed "ℹ︎ About these rows" disclosure) in a future UX pass. Flag only because I touched this line.

## Explicit non-goals (restated)

- **Did not touch `show()`, `markReadyForReview()`, `updateCanonical()`, `createResearchDraft()`, `applyResearchDraft()`, `restoreResearchDraft()`, `rejectResearchDraft()`** — all 7 remain byte-identical to pre-migration. Verified via the 6-route HEAD probe (same 404s) and via file size: the controller dropped from 1886 to 1729 LOC; the `−157` accounts for the `index()` heredoc + `renderListItem()` body + imports delta, no other methods modified.
- **Did not touch any private helper other than `renderListItem()`** — `renderReviewPanel`, `renderArtifactAuditPanel`, `renderConfidencePanel`, `summarizeConfidenceState`, `buildReadinessSummary`, `renderReadinessPanel`, `renderReadinessAction`, `renderResearchPanel`, `renderResearchDraftsPanel`, `renderAppliedResearchDraftsPanel`, `renderResearchDraftTargetOptions`, `buildResearchDraftValue`, `bestResearchSnippet`, `assessResearchItem`, `researchDraftTargetOptions`, `getValueAtPath`, `valueToDraftString`, `renderAppendixChecklist`, `renderAppendixNotesPanel`, `renderAppendixNoteActivityMeta`, `renderFieldReviewPanel`, `renderCanonicalEditPanel`, `valueAtPath`, `renderAnnotationValue`, `editNoticeFromUri`, `parseCanonicalValue`, `decodeJsonValue`, `setValueAtPath` — all survive untouched.
- **Did not promote `submission-row` to `components/shared/`** — lives in `components/domain/submissions/` per the prompt. If prompt #8 finds a `show()` reuse, revisit.
- **Did not extract `empty-state` or `status-badge` to `components/shared/`** — still deferred.
- **Did not convert hardcoded strings to `trans()`** beyond the one `<title>` key.
- **Did not change routes, URLs, route names, or mutation behavior** — verified by HEAD probe.
- **Did not commit to git** — all changes staged in working tree.

## Open questions for Russell

Keeping this tight — only things that gate prompt #8 or later.

1. **Does `show()` have an "all submissions in this cohort" widget or similar that would reuse `submission-row`?** If yes, the component earned its extraction this pass and justifies ≥2 call sites. If no, `submission-row` stays correctly scoped to domain/submissions but is used only once — still OK per the conventions, but worth knowing when evaluating "did we over-extract?"
2. **Badge variants — when to design the shared `status-badge` component.** Current visual vocabulary across dashboard/intake/cohorts/submissions/show has at least 3 badge variants (default moss-tint, rust-tint for warnings, some inline-styled others). Want me to do an inventory pass after prompt #8 ships (so we have 5 templates' worth of badge usage) and draft a shared component design, or defer further until the Review/Document migrations also land?
3. **`weak_count === 1` grammar in view-model — defer to ICU plural forms in the eventual `trans()` pass, or adjust now?** Right now the view-model carries `weak_single: bool` so the template can pick between "field needs follow-up" and "fields need follow-up". Leaving as-is unless you want me to move the conditional into the template.
