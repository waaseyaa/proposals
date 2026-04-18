# Multi-tenant Data Model — Proposed Design

**Status:** design, not implementation. No code in this pass.
**Target:** Miikana SaaS at `miikana.waaseyaa.org`. User → Organization → Submission.
**Framework:** Waaseyaa entity system + access-policy interfaces.

---

## Premise

Today every route uses `->allowAll()` (`src/Provider/AppServiceProvider.php` lines 188-320, confirmed in `docs/specs/AUDIT.md`). No `User`, no `Organization`, no membership anywhere in `src/`. Existing submissions are effectively owned by nobody. The hosted tier requires: (1) authenticated users, (2) submissions that belong to an organization, (3) access rules that prevent org A from seeing org B's data, (4) existing NorthOps submissions continuing to work so demo flows don't break.

Billing is **not in scope**. Tier is a read-only attribute on `Organization` — `oss` / `pro` / `mission`. No Stripe. No quota enforcement in MVP.

---

## 1. `User` entity

### Shape

```
entity type id:  user
table:           user

fields:
  id            int, pk
  email         varchar(254), unique, not null, citext semantics
  display_name  varchar(255), nullable
  password_hash bcrypt/argon2id, nullable (for SSO-only users)
  status        enum('active','invited','suspended')
  created_at    timestamptz
  last_seen_at  timestamptz, nullable
```

### Authentication

**Recommendation:** use the Waaseyaa framework's existing auth surface rather than hand-rolling Symfony Security.
- `.env.example` contains a "Dev-only: auto-authenticate as admin on the built-in PHP server" stanza → framework already has an auth concept
- `config/waaseyaa.php` already reads `WAASEYAA_JWT_SECRET` and an `api_keys` map → framework has bearer + JWT support
- CLAUDE.md references `Waaseyaa\Auth\AuthManager` (session-based) as the canonical path

**Concrete plan:** bind `Waaseyaa\Auth\AuthManager` in `AppServiceProvider::register()`, implement a `UserIdentityProvider` that adapts our `user` entity to the framework's identity contract. Do **not** introduce Symfony Security directly. If the framework's auth surface is insufficient (e.g., no password-reset flow, no OAuth), raise that as a framework-repo change rather than forking it here.

**Dev shortcut:** retain the framework's dev-only auto-admin login for `bin/waaseyaa serve` local development. Gate it hard on `APP_ENV in ('local','dev','development')`.

### Session → request scope

Framework populates a current-user service per request (resolvable via DI). Controllers must never read `$_SESSION` directly.

---

## 2. `Organization` entity

### Shape

```
entity type id:  organization
table:           organization

fields:
  id            int, pk
  slug          varchar(64), unique, url-safe (e.g. 'northops', 'sagamok')
  name          varchar(255), not null (display name, e.g. "NorthOps")
  tier          enum('oss','pro','mission'), not null, default 'oss'
  sovereignty   varchar(64), nullable (reserves slot for First Nation affiliation, OIATC membership, etc — this is Miikana-specific)
  created_at    timestamptz
```

Note `sovereignty` is pre-declared because the dashboard already renders sovereignty context via Bimaaji. Keeping it as a field on `Organization` keeps the data model aligned with an already-surfaced concept.

### Tier semantics (read-only, no enforcement in MVP)

| Tier | Intent | MVP enforcement |
|---|---|---|
| `oss` | self-host, no hosted features | none |
| `pro` | hosted tier, connectors on | none |
| `mission` | North Way partners, OIATC publishing signature | none |

Tier is surfaced in UI (badge) and logged with submissions for future analytics, but **no runtime gate in MVP**. This is deliberate — plumbing billing later is a clean additive change; plumbing it now and ripping it out is waste.

---

## 3. Membership (User ↔ Organization)

### Shape

```
entity type id:  organization_membership
table:           organization_membership

fields:
  id               int, pk
  user_id          fk → user.id, not null
  organization_id  fk → organization.id, not null
  role             enum('member','admin','operator'), not null, default 'member'
  created_at       timestamptz

unique constraint: (user_id, organization_id)
```

### Roles

- `member` — can read + write submissions within the org
- `admin` — member + can invite/remove users, edit org profile
- `operator` — Waaseyaa platform staff; cross-org access for support + pipeline/cohort management. Identified by membership to a special "platform" org OR by a boolean on `user`. **Recommendation:** a boolean `user.is_platform_operator` — cleaner than a pseudo-org, matches how `admin/operator` separation is usually modeled.

Users may belong to multiple orgs (a consultant serving Sagamok + NorthOps). A "current organization" concept is request-scoped (see §5).

---

## 4. Submission ownership

### Shape change to `proposal_submission`

Add field:
```
organization_id  fk → organization.id, not null
```

And a matching field on `proposal_cohort` (cohorts belong to an org), `proposal_pipeline` (a pipeline is per-org — e.g., "NorthOps ISETP 2026"), `proposal_document` (inherited via submission), `proposal_review` (inherited via submission but also needs `reviewer_user_id` so we track which human reviewed).

**`proposal_review` additions:**
```
reviewer_user_id  fk → user.id, not null  (who performed the review action)
```

Today `proposal_review` has no user concept — `action_type` is tracked but not who did it. Closing this gap is a small, safe add.

### Backfill strategy (existing NorthOps submissions)

1. Migration creates an `organization` row with `slug='northops'`, `name='NorthOps'`, `tier='mission'`, `sovereignty='OIATC'`.
2. Migration creates a `user` row with email `seed@northops.local`, status `active`, deterministic id (e.g. 1).
3. Migration creates an `organization_membership` (user=1, org=northops, role=admin).
4. Migration `UPDATE`s all existing `proposal_pipeline`, `proposal_cohort`, `proposal_submission`, `proposal_document` rows to `organization_id = <northops.id>`.
5. For `proposal_review` rows with no `reviewer_user_id`, set to the seed user. Only acceptable because current `.allowAll()` reviews were placeholder anyway.
6. Make the new `organization_id` columns `NOT NULL` only **after** the backfill completes.

This preserves dev flow: `bin/waaseyaa serve`, log in as the seed user, see NorthOps submissions.

### Migration tool

Use the framework's schema mechanism (`SqlSchemaHandler` via `ProposalSchemaBootstrap`) plus a one-shot Symfony console command (`miikana:migrate-to-tenancy` or similar). Do not introduce Doctrine migrations; framework uses its own storage layer.

---

## 5. Access control — replacing `allowAll()`

### Default policy (baseline)

Implement `Waaseyaa\Access\AccessPolicyInterface` for each entity type:
- `proposal_submission` policy: access granted iff current user has a membership in the submission's `organization_id`
- `proposal_cohort`: same
- `proposal_pipeline`: same
- `proposal_document`: inherited from parent submission
- `proposal_review`: inherited from parent submission, but write-create requires role ≥ `member` in same org
- `organization`: user can read an org they belong to; only `admin` members can write
- `user`: users can read + write themselves; admins read other members of their orgs; platform operators read all
- `organization_membership`: readable by anyone in the org; writable only by `admin` in that org or platform operators

### Role-gated entity-level actions

- Cohort + pipeline management: `admin` role (or platform operator)
- Submission create: any `member+`
- Submission approve / request revisions: `admin` in the owning org (matches `proposal_review` action model; today there is no role gate)

### Field-level policies

Keep these minimal in MVP. One clear case:
- `user.password_hash` — `FieldAccessPolicyInterface` that `Forbidden`s all read + all write except through the auth-manager setter path.

Do not pre-emptively lock down other fields. Field-access is "open by default; Forbidden restricts."

### Replacing `->allowAll()` in routes

Routes declare *authentication* requirements, entities declare *authorization*. Proposed route-builder shape:
- `->authenticated()` — user must be logged in (replaces `allowAll()` everywhere)
- `->role('operator')` — platform-only routes (audit logs, tenant listing)
- CSRF remains as-is (`->csrfExempt()` on POSTs that already carry it); tighten separately.

The dashboard route `/` may stay public-readable *only* for the marketing splash; everything under `/submissions`, `/cohorts`, and friends moves to `->authenticated()`.

### Request-scoped current-organization resolver

Introduce `App\Access\CurrentOrganization` service, request-scoped:
- Resolves the "active" organization for the current user
- Source of truth: a session/cookie value OR `?org=slug` query param OR a sub-domain strategy (`<slug>.miikana.waaseyaa.org`) — **pick one and commit**. Recommendation: **session-selected with slug-in-URL override**, defer sub-domains until year 2.
- Validates that the selected org is one the current user has membership in; otherwise falls back to their first membership.
- Controllers inject `CurrentOrganization`; they never read session directly.

---

## 6. Migration order

1. Land entities `user`, `organization`, `organization_membership` (no fields on proposal_* yet). Framework register + `ProposalSchemaBootstrap` extension (or new `TenancySchemaBootstrap`).
2. Land `AuthManager` wiring + a first `/login`, `/logout` route. Dev-auto-admin gated on `APP_ENV`.
3. Land `organization_id` nullable columns on proposal_* entities. Deploy.
4. Run backfill migration (`miikana:migrate-to-tenancy`). Verify.
5. Mark `organization_id` `NOT NULL`.
6. Replace `->allowAll()` with `->authenticated()` one controller at a time. Each swap tests live against the seed NorthOps org.
7. Introduce `AccessPolicyInterface` per entity type in tandem.
8. Wire `CurrentOrganization` + UI org-switcher.

Each step is independently revertable.

---

## 7. Open questions

1. **Sub-domain tenancy?** `northops.miikana.waaseyaa.org` vs path-based `miikana.waaseyaa.org/org/northops`. Has DNS, cookie-scoping, and cert implications. Recommend path-based for year 1.
2. **SSO providers?** Google, Microsoft, OIDC — which first? Affects how much of `AuthManager` we need to extend.
3. **Platform-operator identification:** boolean on `user` (recommended) or pseudo-org membership?
4. **Invite flow model:** email-link with token? Admin-created membership with temp password? This affects what `organization_membership.status` needs (may need to split from `user.status`).
5. **Is `proposal_pipeline` per-org or global-with-offerings?** E.g., is "ISETP 2026" a pipeline *template* shared across all OIATC tenants, or does each org have its own copy? The audit doesn't disambiguate. **Assumption in this doc: per-org copy.** Flag for Russell.
6. **Data residency / export on close?** If a tenant leaves, what happens to their submissions and documents? Design for it now if relevant for Mission tier compliance.
7. **Quota/tier enforcement — when?** MVP says never. Confirm that's still the position before connectors ship, since Grant Catalog + RFP feed are tier-gated by product intent.

---

## Non-goals in this doc

- Billing integration (Stripe, etc.)
- OAuth provider catalog
- Password-reset flow detail
- Audit log schema (belongs with operator spec)
- Rate limiting per tier
- Data export / tenant-offboarding tooling

Each of the above is a discrete downstream spec.
