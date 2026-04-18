# Miikana Templating Conventions

Reference: these conventions mirror [Minoo](https://github.com/waaseyaa/minoo)
(same PHP/Symfony/Waaseyaa stack, read-only checkout at `~/dev/minoo`). The
goal is reusability of templates and components across Waaseyaa-framework
consumer apps, so deviation is kept to a minimum and recorded explicitly in
the Deltas section below.

## Stack

- **Engine**: Twig 3.x, provided transitively via the Waaseyaa framework
  (`twig/twig: ^3.0` appears in multiple `waaseyaa/*` packages — see
  `composer.lock`). No additional bundle or composer add is required.
- **Environment wiring**: `Waaseyaa\SSR\ThemeServiceProvider` constructs the
  shared `Twig\Environment` in its `boot()` with a `ChainLoader` that registers
  `<project_root>/templates/` (among other paths). Retrieve via
  `Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment()` at route-registration
  time (after all providers have booted).
- **Defaults** (from
  `vendor/waaseyaa/ssr/src/ThemeServiceProvider.php:58-62`):
  - `autoescape` → `'html'` (Twig default; escapes via
    `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, byte-identical
    to the inline-HTML helpers we are replacing)
  - `strict_variables` → `false` (undefined variables render empty, not fatal)
  - `cache` → disabled under `cli-server`; configured path otherwise
  - `auto_reload` → `true`
- **Extensions registered by the framework**:
  - `WaaseyaaExtension` — asset/config/env helpers
  - `csrf_token` Twig function — wraps
    `Waaseyaa\User\Middleware\CsrfMiddleware::token()`, marked `['is_safe' => ['html']]`

## Layouts

- Path: `templates/layouts/{name}.html.twig`
- Base layout: `templates/layouts/base.html.twig`
- Block vocabulary (names match Minoo; keep these even when the block body is
  minimal so child pages extending either app's layouts use the same overrides):
  - `{% block title %}` — page `<title>`, default `Miikana`
  - `{% block head %}` — per-page `<head>` contributions (inline styles,
    preload links, meta overrides). Emitted inline before `</head>`.
  - `{% block body %}` — page body content. Emitted directly inside `<body>`.
    Each page owns its own outer element (e.g. `<main>`) because Miikana's
    route chrome is not yet consistent across controllers. See Deltas §1.
- Forward-compatible blocks Minoo uses but Miikana does not populate yet
  (add when the need arises, do not remove):
  `meta_description`, `og_title`, `og_description`, `og_image`, `og_type`,
  `scripts`.

## Pages

- Path: `templates/pages/{domain}/{action}.html.twig`
- One template per route (e.g. `pages/dashboard/index.html.twig`).
- Always `{% extends "layouts/base.html.twig" %}` as the first line.
- Override `title`, `head`, `body` only — do not duplicate the `<!doctype>` /
  `<html>` shell.

## Components

- Path: `templates/components/{scope}/{domain}/{component}.html.twig` where
  `{scope}` is one of:
  - `shared/` — reusable across domains (buttons, status badges, empty-state
    panels, feedback banners)
  - `domain/{name}/` — domain-specific partials (e.g.
    `components/domain/dashboard/graph-snapshot.html.twig`)
- Consumption: `{% include "components/..." with { prop1: value1, ... } %}`.
  Prefer `include with {…}` over `embed` unless the consumer needs to override
  sub-blocks (Minoo uses `include` almost exclusively).
- Extraction threshold: a component only lives under `components/shared/` once
  it has ≥2 call sites. A single-use partial stays in the page template.

## i18n

- **Mechanism**: `{{ trans('domain.subkey') }}` — a Twig function provided by
  `Waaseyaa\I18n\Twig\TranslationTwigExtension`. Not the built-in `{% trans %}`
  block tag, not a filter.
- **Dictionary**: `resources/lang/{locale}.php` returns a flat
  `['domain.subkey' => 'Value']` array. Key format is dot-notation nested by
  domain (`dashboard.hero.title`, `base.skip_link`).
- **Helpers also exposed by the extension**: `current_language()`,
  `available_languages()`, `lang_url(path)`, `lang_switch_url()`.
- **Scaffold status (2026-04-18)**: `resources/lang/en.php` has the Dashboard
  keys listed but is **not yet wired**. `TranslationTwigExtension` requires
  `TranslatorInterface` and `LanguageManagerInterface` to be bound in the DI
  container — neither the framework nor `AppServiceProvider::register()`
  provides those bindings today. The first page that actually calls
  `trans()` must also wire those bindings and register the extension in
  `AppServiceProvider::boot()`. See
  [`docs/migrations/twig-extraction.md`](../migrations/twig-extraction.md) §"Open follow-ups".

## Assets

- CSS/JS are inlined in the page template's `{% block head %}` for now,
  matching the current inline-HTML approach. No Webpack Encore, no
  `asset()` helper, no stylesheet bundler.
- When a future pass extracts shared styles into `/public/css/miikana.css`,
  the base layout should add
  `<link rel="stylesheet" href="/css/miikana.css?v=N">` before `{% block head %}`
  so pages append rather than replace the global stylesheet.

## Rendering contract

- Controllers receive `Twig\Environment $twig` via constructor injection
  (see `src/Controller/DashboardController.php:20`).
- Inside the action, build a plain associative render context and return
  `new Response($this->twig->render('pages/...', $context), 200, ['Content-Type' => 'text/html; charset=UTF-8'])`.
- `AppServiceProvider::routes()` resolves the environment via
  `\Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment()` and passes it to
  the controller constructor. It throws if the environment is null (would
  indicate `SsrServiceProvider::boot()` has not run).

## Deltas from Minoo

1. **No global site chrome in `base.html.twig`**: Minoo's base emits a full
   `<header>`, sidebar, skip-link, theme toggle, language switcher, etc.
   Miikana's routes currently emit inconsistent chrome (Dashboard is chromeless,
   Submissions/Cohorts have different inline headers). Until chrome converges,
   the base stays minimal: doctype, `<html lang="en">`, head shell with
   `{% block title %}` + `{% block head %}`, and `<body>{% block body %}`.
   When chrome converges, lift the common elements into the base layout and
   move per-page variance into a second layout or page-level partial.
2. **`lang="en"` is hardcoded** rather than `{{ current_language().id }}`
   as in Minoo, because the i18n runtime (`LanguageManager`) is not yet bound
   (see i18n §). Flip to the dynamic form once the binding lands.
3. **`resources/lang/en.php` is a scaffold, not a live dictionary**. See i18n §.
