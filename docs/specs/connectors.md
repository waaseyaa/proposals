# Connector Interface — Proposed Spec

**Status:** design, not implementation.
**Scope:** Grant Catalog (seeded, TOTT + ISETP), RFP feed (HTTP to `rfp-ingestor:8095`), FN Directory. Hosted tier. Year-1 publishing authority limited to OIATC.

---

## 1. What a connector is

A **connector** is a strategy for fetching structured, verifiable records from a named upstream source. It must:

- Expose a stable identity (`source_id`) and a self-describing capability set.
- Return records with a **provenance stamp** that travels with any canonical field those records subsequently influence.
- Support a `query(...)` operation (search with filters) and a `fetch(id)` operation (retrieve one record by stable upstream id).
- Be swappable at DI-registration time without touching consumers.

A connector is **not** the same as a research executor (`LocalCorpusResearchExecutor`, `DuckDuckGoResearchExecutor`). Research executors do unstructured RAG-style text recall. Connectors return typed, provenanced records with schemas. Both may feed intake and review, but connector output is first-class data.

## 2. Reuse of existing research-draft lifecycle

The current research-draft lifecycle (`createResearchDraft`, `applyResearchDraft`, `rejectResearchDraft`, `restoreResearchDraft` at `src/Controller/SubmissionController.php`; routes in `src/Provider/AppServiceProvider.php:284-326`) already models: *"external system suggested a change; a human operator approves, rejects, or restores."* This is exactly the lifecycle a connector hit needs.

**Decision:** connectors emit their hits as `ResearchDraft` instances — same entity, same workflow, same UI. Provenance just gets richer. We do not introduce a parallel "connector draft" concept.

---

## 3. Interface

```php
namespace App\Connector;  // or Miikana\Connector after rename

interface ConnectorInterface
{
    /** Stable source identifier, e.g. 'grant_catalog', 'rfp_feed', 'fn_directory'. */
    public function sourceId(): string;

    /** Human label for UI. */
    public function label(): string;

    /**
     * Describe what this connector can answer. Used by the intake planner
     * and by the UI to route a request to the right connector(s).
     */
    public function capabilities(): ConnectorCapabilities;

    /**
     * Query the upstream source. Returns zero or more hits.
     * @return iterable<ConnectorHit>
     */
    public function query(ConnectorQuery $query): iterable;

    /**
     * Retrieve a single record by its upstream id. May return null if
     * the record no longer exists.
     */
    public function fetch(string $upstreamId): ?ConnectorHit;
}
```

### `ConnectorCapabilities`

```php
final class ConnectorCapabilities
{
    public function __construct(
        /** @var list<string> record types this connector emits, e.g. ['grant','rfp','organization'] */
        public readonly array $recordTypes,
        /** @var list<string> searchable facets, e.g. ['region','funding_stream','eligibility'] */
        public readonly array $facets,
        public readonly bool $supportsFullText,
        public readonly bool $supportsStreaming = false,
    ) {}
}
```

### `ConnectorQuery`

```php
final class ConnectorQuery
{
    public function __construct(
        public readonly ?string $text = null,
        /** @var array<string,scalar|list<scalar>> facet filters */
        public readonly array $filters = [],
        public readonly int $limit = 20,
        public readonly int $offset = 0,
    ) {}
}
```

### `ConnectorHit`

```php
final class ConnectorHit
{
    public function __construct(
        public readonly string $upstreamId,
        public readonly string $recordType,
        /** Title / summary for UI */
        public readonly string $title,
        /** Typed payload. Schema depends on recordType. */
        public readonly array $payload,
        public readonly Provenance $provenance,
    ) {}
}
```

---

## 4. Provenance record

The spec prompt requires `source_id`, `source_url`, `fetched_at`, `confidence`. Integrating with what the existing research-draft lifecycle already tracks:

```php
final class Provenance
{
    public function __construct(
        /** Stable identifier of the connector that produced this. Matches ConnectorInterface::sourceId(). */
        public readonly string $sourceId,
        /** Human-readable source label for UI display. */
        public readonly string $sourceLabel,
        /** URL where a human can verify this record. Null for seeded data without a web presence. */
        public readonly ?string $sourceUrl,
        /** ISO 8601 timestamp of when the connector fetched this. */
        public readonly string $fetchedAt,
        /**
         * 0.0–1.0. 1.0 = authoritative (seeded Grant Catalog record, direct match).
         * 0.5 = inferred (search match, name similarity).
         * 0.0 = speculative (LLM-generated suggestion with no upstream source).
         */
        public readonly float $confidence,
        /**
         * Upstream record id, stable across fetches. For `grant_catalog` this
         * is the catalog row id; for `rfp_feed` it's the RFP number; for
         * `fn_directory` it's the Indigenous Services Canada band id.
         */
        public readonly string $upstreamId,
        /**
         * Digital signature if the source supports it (see §Signature story).
         * Null otherwise.
         */
        public readonly ?string $signature = null,
        /**
         * Issuer of the signature (e.g. 'oiatc'). Null when signature is null.
         */
        public readonly ?string $signatureIssuer = null,
    ) {}
}
```

### Provenance travel

When a `ResearchDraft` is `applied` to the canonical submission, each field it writes must be tagged with the provenance record that authorized the write. Proposed `source_artifacts` schema extension on `proposal_submission`:

```
source_artifacts:
  canonical_field_provenance: {
    "business.identity.business_name": {
      source_id: "grant_catalog",
      upstream_id: "tott-2026-042",
      confidence: 0.95,
      fetched_at: "2026-04-15T14:32:00Z",
      ...
    },
    "funding_request.amount": { ... }
  }
```

This is additive: existing `source_artifacts` already stores arbitrary provenance-shaped data per `src/Domain/Import/NorthOpsSeedImporter.php` (`source_directory`, `form_html`, etc.). Extend rather than replace.

---

## 5. Mapping the three shipping connectors

### 5a. Grant Catalog (seeded)

Source: a curated database of TOTT (Taking Off The Table) + ISETP grant programs. Seeded from a reviewed data file; refreshes periodically via data ops rather than live HTTP.

**Mapping:**
- `sourceId()` = `'grant_catalog'`
- `label()` = `'Grant Catalog'`
- `capabilities()`: `recordTypes=['grant']`, `facets=['funding_stream','region','deadline','eligibility','program']`, `supportsFullText=true`
- `query()` implementation: read from a `grant_catalog_entry` table (separate from proposal_* entities; this is reference data, not tenant data). Likely unaffected by org scoping — the catalog is global. Access policy: `allowRead` to any authenticated user; writes gated to platform operators.
- `fetch()` implementation: direct PK lookup on `grant_catalog_entry.upstream_id`.
- Provenance: `confidence=1.0` (authoritative seeded data), `sourceUrl` populated from the catalog row's `source_url` column (e.g., ISED program page), `signature` populated if the catalog row was imported from a signed bundle (see §7).

### 5b. RFP feed (HTTP to `rfp-ingestor:8095`)

Source: internal microservice at `http://rfp-ingestor:8095` (or equivalent hostname in production) that scrapes / aggregates government RFPs.

**Mapping:**
- `sourceId()` = `'rfp_feed'`
- `label()` = `'RFP Feed'`
- `capabilities()`: `recordTypes=['rfp']`, `facets=['region','issuer','deadline','naics']`, `supportsFullText=true`, `supportsStreaming=false` (sync HTTP for MVP)
- `query()` implementation: HTTP GET to `http://rfp-ingestor:8095/search?q=...&filters=...`. Timeouts and retries handled in the connector. Results decoded into `ConnectorHit` with `recordType='rfp'`.
- `fetch()` implementation: HTTP GET to `http://rfp-ingestor:8095/rfps/{upstreamId}`.
- Configuration: `RFP_INGESTOR_URL` env var, default `http://rfp-ingestor:8095`. Optional `RFP_INGESTOR_TOKEN` for bearer auth if the service is locked down.
- Provenance: `confidence=0.9` for exact filter matches, lower for fuzzy. `sourceUrl` is the external RFP's origin URL (populated by rfp-ingestor).
- Failure mode: if `rfp-ingestor` is down, `query()` returns empty and logs a warning. **Do not raise** — the submission UI stays usable.

### 5c. FN Directory (First Nations directory)

Source: community directory (likely ISC band data or an OIATC-curated layer on top).

**Mapping:**
- `sourceId()` = `'fn_directory'`
- `label()` = `'FN Directory'`
- `capabilities()`: `recordTypes=['organization']`, `facets=['region','nation','treaty','population_range']`, `supportsFullText=true`
- `query()` / `fetch()`: depend on whether this is a local seeded table (like grant catalog) or a live HTTP service. **Assumption in this doc:** seeded like grant catalog for MVP, since ISC data changes slowly. Revisit if real-time lookups are required.
- Provenance: `confidence=1.0` for seeded authoritative entries, `sourceUrl` points back to the authoritative band registry.
- Sensitivity: band-registry data has sovereignty concerns. Access policy: readable to any authenticated Miikana user; writes restricted to platform operators + OIATC-designated curators. Flag any PII fields (`FieldAccessPolicyInterface::Forbidden` on contact info unless the caller's org has the relevant membership).

---

## 6. Wiring into the existing research-draft lifecycle

1. When a user clicks "find matching grants" in the submission UI, the UI calls `POST /submissions/{sub}/research/draft` with a hint (`connector=grant_catalog`, query text).
2. `SubmissionController::createResearchDraft()` dispatches to the correct `ConnectorInterface` via a `ConnectorRegistry` (DI-tagged, like seeders).
3. Connector returns hits → controller materializes them as `ResearchDraft` entities, each carrying its `Provenance` record.
4. Human reviews drafts. `applyResearchDraft` copies the hit's `payload` fields into the submission's canonical data, **also persisting the provenance** into `source_artifacts.canonical_field_provenance`.
5. `rejectResearchDraft` tombstones it. `restoreResearchDraft` un-tombstones.

Zero route changes required. One schema add on `proposal_submission.source_artifacts`. One new controller-level dispatcher.

---

## 7. Signature story — year-1 OIATC-only publishing authority

### Premise

Year 1, only OIATC is a trusted publisher. A connector record is "signed" when an OIATC-controlled key has attested to its content. Signature makes the record authoritative (confidence = 1.0) and unlocks mission-tier behaviors (e.g., auto-applying a draft without human review for high-confidence fields).

### Where signature lives

On the `Provenance` record: `signature` + `signatureIssuer` (§4).

### Who checks

A `ProvenanceVerifier` service, resolved via DI:

```php
interface ProvenanceVerifierInterface
{
    public function verify(Provenance $provenance): VerificationResult;
}

final class VerificationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $reason,  // null on success
        public readonly ?string $issuer,
    ) {}
}
```

Implementation calls `openssl_verify()` with the issuer's public key (bundled with the app for year 1; one key, `oiatc`). Key rotation deferred.

### What it gates (year 1)

- **Auto-apply of drafts** in the mission tier: if `provenance.signature` verifies and `signatureIssuer='oiatc'` and the record's recordType is in an auto-apply allowlist (`grant`, `rfp`), the submission controller may skip human approval. **Default:** human approval still required; auto-apply is an explicit per-org setting.
- **Provenance badges in UI.** Signed records show a verified checkmark with issuer name. Unsigned records show "unverified source."
- **Audit log entries** include the verification result.

### What it does *not* gate (year 1)

- Submission creation. Anyone authenticated can create unsigned data.
- Cross-tenant visibility. Signature ≠ authorization.
- Publishing *out of* Miikana. Outbound publishing authority (e.g., pushing a filed submission to an external grant portal) is a separate spec and will likely require a second signature layer. Noted, not designed here.

### Year-2 expansion (not designed)

Multiple issuers, issuer catalog served from a Waaseyaa-hosted registry, cross-issuer trust chains. Out of scope.

---

## 8. DI + registration

```php
// In AppServiceProvider::register():

$this->singleton(ConnectorRegistry::class, ConnectorRegistry::class);

$this->bind(GrantCatalogConnector::class, function () {
    return new GrantCatalogConnector(
        $this->resolve(EntityTypeManagerInterface::class)->getStorage('grant_catalog_entry'),
    );
});
$this->tag(GrantCatalogConnector::class, 'miikana.connector');

$this->bind(RfpFeedConnector::class, function () {
    return new RfpFeedConnector(
        httpClient: $this->resolve(HttpClientInterface::class),
        baseUrl: getenv('RFP_INGESTOR_URL') ?: 'http://rfp-ingestor:8095',
        token: getenv('RFP_INGESTOR_TOKEN') ?: null,
    );
});
$this->tag(RfpFeedConnector::class, 'miikana.connector');

$this->bind(FnDirectoryConnector::class, function () {
    return new FnDirectoryConnector(
        $this->resolve(EntityTypeManagerInterface::class)->getStorage('fn_directory_entry'),
    );
});
$this->tag(FnDirectoryConnector::class, 'miikana.connector');

$this->singleton(ProvenanceVerifierInterface::class, fn () => new OiatcProvenanceVerifier(
    publicKeyPath: __DIR__ . '/../../resources/oiatc-public.pem',
));
```

---

## 9. Open questions

1. **Is FN Directory seeded or live-HTTP?** Doc assumes seeded; confirm.
2. **Does `rfp-ingestor:8095` exist today?** If not, connector ships with HTTP stub behavior (always returns empty) until the service lands.
3. **Should connectors be tenant-scoped?** Today all three are global reference data. Future tenants may need private catalogs. Addable later.
4. **Provenance persistence schema.** Extending `source_artifacts` as JSON vs. a separate `canonical_field_provenance` table. Recommend JSON extension for MVP; promote to a table if query patterns demand it.
5. **Key storage.** One file committed to the repo is fine for year-1 OIATC-only. Rotation + multi-issuer needs a proper KMS story. Defer.
6. **Allow-list for auto-apply.** Which recordTypes + fields qualify? This is editorial/product.

---

## 10. Non-goals for this spec

- Outbound publishing (submitting a Miikana submission *to* an external grant portal).
- Real-time subscriptions / webhooks from connectors.
- Caching layer for connector hits (MVP: no cache).
- Multi-source reconciliation (when grant_catalog and rfp_feed return the same program under different IDs).
- Fuzzy / ML-based record linkage.
