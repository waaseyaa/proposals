# NorthOps Seed Extraction Plan

**Status:** plan, not implementation.
**Goal:** decouple NorthOps-specific assumptions from core Miikana so the product boots cleanly with zero seed on fresh installs, and so future tenants (Sagamok, other OIATC members) slot in via a common `Seeder` interface.

---

## 1. Where NorthOps-specific assumptions live today

### 1a. Hardcoded absolute paths

`src/Provider/AppServiceProvider.php:96-111` — the DI binding for `ResearchExecutorInterface` uses `LocalCorpusResearchExecutor` whose default corpus is seven absolute paths under `/home/fsd42/NorthOps/...`:

- `/home/fsd42/NorthOps/knowledge-base`
- `/home/fsd42/NorthOps/sources/OIATC`
- `/home/fsd42/NorthOps/sources/mefunding-docs`
- `/home/fsd42/NorthOps/PROJECT_STATUS.md`
- `/home/fsd42/NorthOps/README.md`
- `/home/fsd42/NorthOps/apps/iset-application/README.md`
- `/home/fsd42/NorthOps/apps/oiatc-demo/README.md`

`src/Command/SeedNorthOpsCommand.php:35` — seed source `--source` default is `/home/fsd42/NorthOps`.

These block fresh installs on any machine that isn't Russell's WSL workstation.

### 1b. NorthOps-named code

- `src/Command/SeedNorthOpsCommand.php` — Symfony console command `proposals:seed-northops`
- `src/Domain/Import/NorthOpsSeedImporter.php` — reads ISET application package HTML, writes pipeline + cohort + submission + document entities
- Class names encode the tenant; behavior bakes in tenant-specific source shapes

### 1c. Tenant-specific source-layout assumptions

The importer assumes the NorthOps source dir has a particular structure (ISET application package layout, OIATC demo HTML, specific form HTML filename). Rows it writes include:
- `submission.conversation_summary` = "Seeded from the latest NorthOps ISET package in ~/NorthOps..."
- `submission.source_artifacts` = paths into `source_directory`, `form_html`, `submission_html`, `package_pdf`, `demo_html`
- Pilot cohort metadata hard-keyed to NorthOps naming

### 1d. Dependency injection binding baked in

The seeder is registered unconditionally in `AppServiceProvider::register()` with a resolver that grabs entity storages for `proposal_pipeline`, `proposal_cohort`, `proposal_submission`. It runs fine even if NorthOps data isn't present — it just fails the command with "Source directory not found" (`SeedNorthOpsCommand.php:42`).

This is acceptable: the seeder is only invoked via explicit `proposals:seed-northops`. It does **not** auto-run on app boot. Confirmed by reading the command's `execute()` method. **The product already boots cleanly with zero seed.** The concern is mostly code-hygiene: tenant-specific code shouldn't live in `src/` of a shared product.

---

## 2. Fresh-install posture: already correct, keep it that way

A freshly-cloned Miikana install on any machine:

- Runs `composer install`.
- Runs `bin/waaseyaa serve`.
- Dashboard at `/` renders (uses Bimaaji, no tenant data).
- `/submissions` renders an empty list (no submissions yet).
- Logged-in user creates an organization → creates a submission → runs intake → etc.

This works today. It is not broken. The NorthOps seed command is *opt-in*: only fires when someone runs `bin/waaseyaa proposals:seed-northops`. **Do not couple seeding into boot, migrations, or schema ensure.** That is the single most important invariant this doc protects.

What needs to change is: when a fresh install *wants* NorthOps-style demo data, the mechanism should be tenant-agnostic and pluggable.

---

## 3. Proposed `Seeder` interface

### Shape

```php
namespace App\Seed;  // or Miikana\Seed after rename

interface SeederInterface
{
    /** Stable identifier, e.g. 'northops' or 'sagamok-demo'. */
    public function id(): string;

    /** Human-readable label for CLI + UI listing. */
    public function label(): string;

    /**
     * Validate that the source is reachable / the right shape.
     * Returns a list of problems; empty list means ready.
     *
     * @return list<string>
     */
    public function preflight(SeederContext $context): array;

    /**
     * Execute the seed. Idempotent: running twice with the same context
     * must be safe (upsert via deterministic IDs).
     */
    public function seed(SeederContext $context): SeederResult;
}
```

### `SeederContext` value object

```php
final class SeederContext
{
    public function __construct(
        public readonly string $organizationSlug,  // which org gets the data
        public readonly string $sourceRoot,         // path or URI to source material
        public readonly array $options = [],        // seeder-specific knobs
    ) {}
}
```

### `SeederResult` value object

```php
final class SeederResult
{
    public function __construct(
        public readonly int $pipelinesCreated,
        public readonly int $cohortsCreated,
        public readonly int $submissionsCreated,
        public readonly int $documentsCreated,
        public readonly array $warnings = [],
    ) {}
}
```

### Discovery via DI tag

Providers register seeders with the `miikana.seeder` tag. A `SeederRegistry` collects them and exposes `all()`, `byId()`. CLI command `miikana:seed --seeder=northops --source=/path --org=northops` dispatches through the registry.

### Existing code becomes

- `NorthOpsSeedImporter` → `App\Seed\NorthOps\NorthOpsSeeder` (implements `SeederInterface`)
- `SeedNorthOpsCommand` → replaced by a generic `SeedCommand` that dispatches by seeder id

**Where NorthOps lives:** `src/Seed/NorthOps/` per the seeder. Staged as "first tenant." Future tenants add `src/Seed/Sagamok/`, `src/Seed/OIATCDemo/`, etc. Pluggable but in-tree until someone wants to ship them as separate composer packages.

---

## 4. Research / intake corpus after paths become per-tenant config

### Problem

`LocalCorpusResearchExecutor` today takes a *global* array of absolute paths wired into `AppServiceProvider` at app-boot time. In a multi-tenant world, each org needs its *own* corpus, and the path list should not be hardcoded.

### Proposed shape

Corpus paths move to org-scoped config stored on `Organization` or in a companion `organization_research_corpus` table:

```
organization_research_corpus:
  id                int, pk
  organization_id   fk → organization.id
  path              varchar(1024)
  kind              enum('local_file','local_dir','http_url')
  label             varchar(255), nullable
  created_at        timestamptz
```

`AppServiceProvider` binds `ResearchExecutorInterface` as a **factory** rather than a singleton: resolve per-request, passing the current org's corpus entries. Something like:

```php
$this->bind(ResearchExecutorInterface::class, function () {
    $org = $this->resolve(CurrentOrganization::class)->get();
    $corpusPaths = $this->resolve(CorpusRepository::class)->pathsFor($org);
    return new LocalCorpusResearchExecutor(enabled: true, paths: $corpusPaths);
});
```

For dev without an org selected (CLI, tests), fall back to an empty corpus (`LocalCorpusResearchExecutor(false, [])`).

### Migration of existing NorthOps corpus

The `northops` Seeder registers the seven corpus paths as `organization_research_corpus` rows for the NorthOps org (kind=`local_dir` or `local_file`). Paths become *relative to a tenant-configured source root* rather than absolute:

```
source_root = getenv('NORTHOPS_CORPUS_ROOT') ?: '/var/miikana/seeds/northops'
```

The default moves out of `/home/fsd42/...` to something deployable.

### Abstraction boundary

- `LocalCorpusResearchExecutor` continues to accept a `paths` array in its constructor — no change to the executor
- New work: `CorpusRepository` + `CurrentOrganization` + org-scoped DI
- The research executor doesn't know about orgs. It takes paths and searches them. Good.

---

## 5. Intake reach into tenant corpus

`DeterministicIntakeService` currently depends on `ResearchExecutorInterface`. Since the executor is rebinding per-request with the current org's corpus, intake transparently gets tenant-correct research without knowing about orgs. Clean.

Caveat: the `AnthropicStructuredIntakeClient` has a fixed question plan (9 fields, wired in `AppServiceProvider`). Tenant-specific question plans are a later spec. For MVP, all orgs share one plan. Record this assumption; defer.

---

## 6. Zero-seed fresh-install checklist

For a fresh Miikana instance, verify:

1. `composer install` succeeds with no `../waaseyaa/*` path references required (it does today; `composer.local.json` is opt-in).
2. `bin/waaseyaa serve` boots without referencing `/home/fsd42/...`. **Today this fails** at first intake attempt because `LocalCorpusResearchExecutor` is bound with absolute paths. **Fix:** the org-scoped factory above, which returns an empty corpus when no org is selected.
3. No `proposal_pipeline`, `proposal_cohort`, or `proposal_submission` rows exist after fresh DB bootstrap. (True today; `ProposalSchemaBootstrap::ensure()` only creates tables.)
4. Dashboard at `/` renders without runtime error. (True today; dashboard uses Bimaaji + static template; not data-dependent.)
5. `/submissions` renders empty list. (True today; SubmissionController reads from empty storage cleanly.)

**The only production-blocking fix in this section is the absolute-path binding at `AppServiceProvider.php:96-111`.** Everything else is organizational hygiene.

---

## 7. Migration ordering

Depends on tenancy-migration.md being land-partial (at minimum: `Organization` entity + `CurrentOrganization` service). Order:

1. Land `Organization` + `CurrentOrganization` (per `tenancy-migration.md`).
2. Land `organization_research_corpus` table + `CorpusRepository`.
3. Rebind `ResearchExecutorInterface` as per-request factory. Fall back to empty corpus when no org selected.
4. Introduce `SeederInterface` + `SeederRegistry` + generic `SeedCommand`.
5. Port `NorthOpsSeedImporter` → `NorthOpsSeeder` under `src/Seed/NorthOps/`. Keep old importer as thin delegator during transition; remove after successful dev-seed run.
6. Remove `SeedNorthOpsCommand`. Remove absolute-path defaults from AppServiceProvider (they should already be dead after step 3).
7. Document via README: "To seed NorthOps demo data: `bin/waaseyaa miikana:seed --seeder=northops --org=northops --source=/path/to/NorthOps`."

---

## 8. Open questions

1. **Tenant-specific question plans.** Defer to post-MVP?
2. **Corpus paths as HTTP URIs.** The new `kind` enum includes `http_url` but today only local files work. Do connectors (see `docs/specs/connectors.md`) replace the need for HTTP corpus entries, or is there still a case for raw HTTP research over PDF URLs?
3. **Seeder packaging.** In-tree under `src/Seed/<TenantName>/` (recommended) or separate composer packages (`miikana/seed-northops`)? In-tree for year 1.
4. **Idempotency keys for seeding.** Deterministic IDs based on org + source-hash? Required so re-running a seed upserts cleanly.
5. **Cleanup on tenant removal.** If an org is deleted, what happens to its corpus entries, seeded submissions, etc.? Separate spec.

---

## 9. Non-goals

- Building a UI to manage corpus paths (CLI-first; UI later).
- Cross-tenant corpus sharing (e.g., "all OIATC members see the OIATC public corpus").
- Seeder versioning / rollback tooling.
- Arbitrary file-upload intake (distinct from research corpus; handled by Ingestion/).
