# Framework-level smells

Observations about patterns that deserve revisiting but are not blocking current work. Each entry names a trigger condition for when to re-examine.

---

## Twig null-guard in `AppServiceProvider::routes()`

**Location:** `src/Provider/AppServiceProvider.php:151-162` (as of 2026-04-18).

**Observation:** `routes()` calls `SsrServiceProvider::getTwigEnvironment()`, null-checks, and throws `RuntimeException` if null. The check runs on every request.

**Classification (phase 1.5, 2026-04-18):** defensive throw — not a scaffolding fallback. `DashboardController::__construct` types `$twig` as non-nullable `Environment` (readonly), so DI would TypeError without the guard; the throw just replaces a cryptic TypeError with a clear message ("Twig environment not available; SsrServiceProvider::boot() must run before routes are registered."). Inherit unchanged during phase 1.5 controller migrations.

**Why this is a smell:** per-request DI validation. Boot-ordering enforcement should live at the provider-chain level (guaranteed at kernel boot), or in a `boot()` method on this provider, not in a per-request hook. The current placement implies the framework doesn't enforce `SsrServiceProvider::boot()` running before controllers are instantiated — either that's true (and a framework-level ordering contract is missing), or the guard is dead defensive code.

**Revisit trigger:** after ≥2 controllers repeat the pattern (expected after prompt #6 Cohort migration). If every migrated controller threads `$twig` through `routes()` with the same guard, extract to a single boot-time assertion. If only one or two controllers need it, keep local.

**Related decision:** during phase 1.5, CC may add `boot()` to `AppServiceProvider` for i18n extension registration — if so, the Twig null-guard could migrate there as part of the same pass (cheaper than keeping it in `routes()`).
