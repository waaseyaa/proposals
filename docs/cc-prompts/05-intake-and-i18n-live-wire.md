# Claude Code prompt #5 — Intake migration + i18n live-wire

*Drafted 2026-04-18. Phase 1.5 full-migration pass #1 of 5 (sequence: **Intake** → Cohort → Submission(split) → Review → Document). Companion to prompt #4's Dashboard reference pass. This is a single prompt — not a sequence — and must not pre-queue work for later passes.*

---

## Context (already decided — do not re-open)

- **Null-guard at `src/Provider/AppServiceProvider.php:153-156`** was classified during phase 1.5 review: **(b) defensive throw, inherit unchanged**. `DashboardController::__construct` types `$twig` as non-nullable `Environment`, so DI would TypeError without the guard; the throw just replaces a cryptic error with a clear one. Do not remove it. Do not move it during this prompt (see framework smells log entry at `docs/smells/framework.md` — the *routes()* placement is a smell, but the revisit trigger is "after ≥2 controllers repeat the pattern", not now).
- **i18n wiring** is scope in this prompt. Snippet drafted at `docs/migrations/twig-extraction.md:212-240` — read it before you write wiring code. Note that `AppServiceProvider` currently has `register()` (line 45) and `routes()` (line 151) but **no `boot()` method** — the migration plan assumed one. See §Decision below.
- **Dashboard trans conversion** is scope in this prompt. `resources/lang/en.php` already has the keys; `templates/pages/dashboard/index.html.twig` still hardcodes strings. Convert only Dashboard — do not translate Intake strings in this pass.
- **IntakeController migration** is the main work. File: `src/Controller/IntakeController.php` (546 LOC, 2 public methods: `show()` emits HTML heredoc from line 51; `handle()` is POST→redirect, no template). Route names `submissions.intake` (GET) and `submissions.intake.handle` (POST, `->csrfExempt()`). Template target: `templates/pages/intake/show.html.twig`. Component candidates (from migration plan §IntakeController lines 37-45): transcript-turn, unresolved-list, research-log, next-question-form.
- **Chrome convergence is out of scope this pass.** Per-page `<main>` stays until pass #2 (Cohort). Migration plan §Chrome convergence lines 244-250 confirms.
- **Inline CSS extraction is out of scope.** Inline `<style>` in `{% block head %}` stays (same as Dashboard), for byte-equivalence.
- **Shared component extraction is out of scope** unless Intake + Dashboard demonstrate ≥2 call sites for the same partial. After this pass, if any of the Intake component candidates has no Dashboard counterpart, leave it under `templates/components/domain/intake/…` — do not promote to `templates/components/shared/…`.

## Decision required at the start of this pass

The migration plan's i18n wiring snippet (`docs/migrations/twig-extraction.md:223-232`) calls for `AppServiceProvider::boot()` to register the `TranslationTwigExtension` on the shared Twig environment. `AppServiceProvider` currently has no `boot()` method.

Two routes:

1. **Add `boot()` to `AppServiceProvider`.** This follows the Waaseyaa framework convention (`.claude/rules/waaseyaa-framework.md` §ServiceProvider DI Methods: "Override `register()` for bindings, `boot()` for event wiring."). It makes the i18n extension registration live at the right lifecycle stage, and opens a future home for migrating the Twig null-guard out of `routes()`.
2. **Register the extension inside `routes()` before controllers are constructed.** This avoids touching the provider surface but compounds the per-request-work smell that `docs/smells/framework.md` already flags.

**Pick route 1.** Add a `boot()` method to `AppServiceProvider`. Register the `TranslationTwigExtension` there. Do NOT migrate the Twig null-guard to `boot()` during this prompt — that's a separate change with its own risk surface, tracked in the smells log with a documented revisit trigger.

If framework conventions do not permit `boot()` to read `SsrServiceProvider::getTwigEnvironment()` (e.g., provider ordering is not guaranteed), report back before wiring — do not silently fall back to route 2.

## Pre-flight reading (read-only, in this order)

All paths are in `waaseyaa/miikana/` unless noted. No writes during pre-flight.

1. `.claude/rules/waaseyaa-framework.md` — framework invariants (ServiceProvider DI methods, forbidden patterns, 7-layer architecture). Load-bearing for §Decision above.
2. `docs/specs/templating-conventions.md` — conventions landed in phase 1.5. Match these exactly.
3. `docs/migrations/twig-extraction.md` §IntakeController (lines 26-55) — proposed page template, component candidates, documented risks.
4. `docs/migrations/twig-extraction.md` §i18n wiring (lines 207-242) — the wiring snippet.
5. `src/Provider/AppServiceProvider.php` lines 1-45 (imports + `register()` start) and 145-185 (`routes()` incl. null-guard + `$intake` instantiation at line 176-178).
6. `src/Controller/DashboardController.php` — full 52 lines. This is your pattern reference.
7. `templates/layouts/base.html.twig` — 10 lines. Note block vocabulary (`title`, `head`, `body`). Reserved-but-not-emitted blocks from conventions spec: `meta_description`, `og_*`, `scripts`.
8. `templates/pages/dashboard/index.html.twig` — the reference page template. Note the inline `<style>` preserved in `{% block head %}`.
9. `resources/lang/en.php` — 30 keys, `dashboard.*` + `base.title`. You will ADD intake.* keys in this pass (see §Deliverable 4 — but only wire-up, no translation).
10. `src/Controller/IntakeController.php` — the target. Full file. Note `show()` heredoc starts at line 51; `handle()` is POST→redirect with no HTML body; helper methods `renderTurn()`, `renderResearchLog()`, `renderNextQuestion()`, `renderNextQuestion()` emit component-shaped HTML.
11. `C:/Users/jones/Projects/Minoo/src/Provider/AppServiceProvider.php` (or its equivalent) — **read-only reference**. Confirm how Minoo registers its `TranslationTwigExtension`. Match that pattern unless Waaseyaa framework enforces a different shape.

After pre-flight, write a ≤8-line `##Pre-flight summary` at the top of the report (see §Report format). Then begin deliverables in order.

## Deliverables

### 1. Add `boot()` to `AppServiceProvider`

- New method `public function boot(): void` on `App\Provider\AppServiceProvider`.
- Resolve `TranslatorInterface` + `LanguageManagerInterface` (bindings added in deliverable 2).
- Resolve the shared Twig environment via `\Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment()`. Preserve the same null-safety discipline as `routes()` — if `$twig === null`, throw a clear `RuntimeException`. Do NOT silently skip extension registration.
- Register `\Waaseyaa\I18n\Twig\TranslationTwigExtension` with resolved translator + language manager.

### 2. Wire `TranslatorInterface` + `LanguageManagerInterface` in `register()`

Follow the snippet at `docs/migrations/twig-extraction.md:214-222`. Use `$this->singleton(...)` (framework DI method, not `bind()`). Bind:

- `\Waaseyaa\I18n\LanguageManagerInterface` → `LanguageManager` instance. Read available languages from wherever Waaseyaa config lives (likely `config/waaseyaa.php` or similar — confirm by grep during pre-flight, do not hardcode `['en']` if config has more).
- `\Waaseyaa\I18n\TranslatorInterface` → `Translator` instance constructed with the language manager and a loader that reads `resources/lang/{locale}.php`. Match Minoo's loader shape exactly; `C:/Users/jones/Projects/Minoo` is the reference.

### 3. Flip `base.html.twig` `<html lang>` to the live language manager

Change `<html lang="en">` (line 2) to `<html lang="{{ current_language().id }}">`. This tests that `current_language()` resolves end-to-end.

### 4. Convert Dashboard template strings to `trans('dashboard.*')`

Edit `templates/pages/dashboard/index.html.twig`. Every hardcoded UI string whose text matches a key in `resources/lang/en.php` must be replaced with `{{ trans('dashboard.xxx') }}`. If a string in the template has no matching key, ADD the key to `resources/lang/en.php` and switch to `trans()` — do not leave mixed hardcoded/trans strings.

Byte-equivalence target: `GET /` output identical modulo whitespace.

### 5. Migrate `IntakeController` to Twig

Follow the Dashboard pattern exactly. Key mechanics:

- `IntakeController` currently has constructor `__construct(DeterministicIntakeService $intakeService)` (line 14-16). Add `Twig\Environment $twig` as a second constructor arg (readonly, non-nullable, same pattern as DashboardController). Update `AppServiceProvider::routes()` line 176-178 to pass `$twig` as the second arg.
- `show()` (line 18): extract the entire heredoc HTML starting at line 51 into `templates/pages/intake/show.html.twig` extending `layouts/base.html.twig`. The `<style>` block inside the heredoc moves to `{% block head %}` (preserved verbatim — no restyling).
- Replace controller's `htmlspecialchars($x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` calls with plain data passed into the template. Twig's default `html` autoescape uses the same flags. The controller should only build a plain data array (`transcript`, `unresolved_items`, `research_log`, `next_question`, `provider_mode`, `notice`, `submission_id`, `submission_title`) and call `$this->twig->render('pages/intake/show.html.twig', $context)`.
- Helper methods `renderTurn()`, `renderResearchLog()`, `renderNextQuestion()` should either (a) disappear, with their logic reimplemented as component templates, or (b) stay as plain PHP methods that return view-model arrays consumed by components. Prefer (a) if the HTML is simple; prefer (b) if the helper has control flow that doesn't map cleanly to Twig conditionals. Do NOT leave helpers returning HTML strings.
- Extract at least `templates/components/domain/intake/transcript-turn.html.twig` as a component, consumed via `{% for turn in transcript %}{% include 'components/domain/intake/transcript-turn.html.twig' with { turn } %}{% endfor %}`. Other components per your judgment — if the inline HTML is short and used in one place, don't split it.
- `handle()` (POST): no template change. Still returns `RedirectResponse`. Do not touch the `->csrfExempt()` route declaration.

### 6. Add `intake.*` keys to `resources/lang/en.php` (scaffold only)

For any UI string in the new `pages/intake/show.html.twig` template that is visible to the user (headings, labels, empty-state copy, button text), add a flat key under `intake.*` with the current English text as the value. **But do not call `trans()` in the intake template yet** — intake template keeps hardcoded strings for byte-equivalence, same as Dashboard did during the reference pass. The translator conversion for intake happens in a later prompt once we see two templates.

Exception: the `<title>` in the layout block, if the intake template overrides it, should use `trans('intake.page.title')` from day one, because (a) it's a single-line easy conversion, (b) browser tabs are a user-facing surface, (c) it validates that `trans()` works in block content, not just body text.

### 7. Smoke tests — byte-equivalence

Capture before/after HTML output for:

- `GET /` (Dashboard, after trans conversion)
- `GET /submissions/{submission}/intake` for a submission ID that exists in the dev seed data (e.g., the NorthOps seeded submission). If no seed submission exists in the fresh clone, run `bin/console northops:seed` first and capture the submission ID.

For each route: `curl -s http://localhost:8765/... > /tmp/route-before.html` before your changes (tag by route name), same after. Diff whitespace-tolerant (`diff -b` or `diff -w`). Report outcome.

Whitespace-only difference is the target. Any semantic HTML difference (tag nesting, attribute values, text content) is a regression — stop, investigate, do not ship.

### 8. Update `docs/migrations/twig-extraction.md`

- Mark IntakeController row in the sizing table (line 14) as done (add a "done 2026-04-18" marker, don't delete the row).
- Update §Open follow-ups §i18n wiring (line 207+) to reflect what's now live vs what remains.

## Report format

Write the report to `docs/cc-reports/05-intake-and-i18n-live-wire.md` inside this repo — NOT to stdout-only, NOT to stale handoff paths. The planner (me) will read this file directly next session.

Structure:

```markdown
# CC prompt #5 execution report

## Pre-flight summary
≤8 lines. Anything that diverged from the prompt's stated state of the codebase (e.g. if Minoo's AppServiceProvider registered the translator differently than the migration plan snippet assumed).

## Decision outcome
`boot()` added, or route 2 (with reason). If route 2, explain why route 1 wasn't feasible.

## Diffs — files changed with LOC delta
Table, one row per file.

## i18n wiring proof
Show that `TranslationTwigExtension` boots cleanly. One way: `bin/console debug:twig` output trimmed to confirm the extension is registered. Or a unit-shaped test hitting `Translator::trans('dashboard.hero.title')` and asserting on the returned string.

## Dashboard trans conversion
List every string converted, with the key it now uses. Byte-equivalence result for `GET /`.

## Intake extraction
- Controller LOC delta
- Template files created (paths + LOC)
- Component splits taken (which component templates, where consumed)
- AJAX / non-HTML carve-outs (should be just `handle()` + its redirect)
- Byte-equivalence result for `GET /submissions/{id}/intake`

## Shared components extracted this pass
Should be 0 or 1. If 1, justify with ≥2 call sites. If more than 1, you over-extracted — justify each.

## Template-layer smells surfaced
Anything observed during migration that belongs in `docs/smells/` (do not write to smells/ this pass — just list here and I'll decide what to log).

## Updated view on chrome convergence
With Dashboard + Intake templates both extracted, is the shared chrome shape visible now, or does it need a third data point? One-paragraph answer, not a full design.

## Open questions for Russell
Questions that require a decision before the next prompt (prompt #6 Cohort migration).
```

## Constraints (restated so CC re-reads them)

- Minoo at `C:/Users/jones/Projects/Minoo` is **read-only**. Read, never edit.
- No restyling. Byte-equivalent HTML output modulo whitespace for Dashboard and every Intake HTML route.
- No new Composer dependencies.
- No git commits. Stage changes for Russell's review.
- Preserve route names, URLs, CSRF tokens, env var references. In particular: `submissions.intake` (GET), `submissions.intake.handle` (POST, `csrfExempt()` stays).
- Preserve stored-data literals: status strings (`intake_in_progress` / `draft` / `ready_for_review` / `approved`), `action_type` values, `document_type` values, `validation_state['research_drafts']` nesting, appendix set `A,B,F,G,H,M`, route names, env var names.
- Do NOT start chrome convergence.
- Do NOT extract inline CSS.
- Do NOT translate Intake strings in this pass (scaffold keys only; template stays hardcoded).
- Do NOT migrate Cohort / Submission / Review / Document.
- Follow `.claude/rules/waaseyaa-framework.md` — no Laravel/Illuminate patterns, no ActiveRecord. Use `$this->singleton(...)` for DI, not `bind()` unless you have a reason (document it if so).

## What this prompt explicitly does not do

- Does not migrate Cohort / Submission / Review / Document controllers.
- Does not converge chrome into `base.html.twig`.
- Does not extract inline CSS to `/public/css/miikana.css`.
- Does not convert Intake template strings to `trans()`.
- Does not re-open the null-guard classification.
- Does not migrate the null-guard from `routes()` to `boot()` (tracked in `docs/smells/framework.md` with revisit trigger).
- Does not change routes, URLs, form field names, CSRF token names, env var names.
- Does not commit to git.
