# Waaseyaa Proposals

Schema-first proposal and funding pipeline application built on Waaseyaa for the
Waaseyaa platform and NorthOps.

[**Discord**](https://discord.gg/ZzQNhrBb7U) | [GitHub](https://github.com/waaseyaa/framework) | [Website](https://waaseyaa.org)

## Directory Structure

```
src/
├── Access/        Authorization policies
├── Controller/    HTTP controllers (thin orchestration)
├── Domain/        Domain logic grouped by bounded context
├── Entity/        ORM entities (pure data models)
├── Ingestion/     Inbound data pipelines (files, email, APIs)
├── Provider/      Service providers (bootstrapping, DI, routing)
├── Search/        Search providers, autocomplete, indexing
├── Seed/          Seeders for dev/local bootstrap
└── Support/       Cross-cutting utilities (ValueObjects, helpers)
```

### Domain Rules

Bounded contexts go under `Domain/<ContextName>/` with optional subdirectories:
`Service/`, `ValueObject/`, `Workflow/`, `Assembler/`, `Ranker/`, `Mapper/`.

### Support Rules

Cross-cutting utilities (validators, slug generators, normalizers, distance
calculators) belong in `Support/`.

### Namespace Rules

Namespaces must match PSR-4 directory structure. Update namespaces in files
and all references when moving code.

## Commands

```bash
composer install                    # Install dependencies
./vendor/bin/phpunit                # Run tests
php bin/waaseyaa optimize:manifest  # Rebuild provider manifest
bin/waaseyaa serve                  # Dev server
bin/waaseyaa                        # CLI
```

## Configuration

- `config/waaseyaa.php` — Framework configuration
- `config/entity-types.php` — Custom entity types
- `config/services.php` — Service overrides
- `composer.local.json` — Local path repositories for `../waaseyaa/packages/*`

## Current Scope

- Proposal entity types are registered for pipelines, submissions, documents, reviews, and cohorts.
- `waaseyaa/bimaaji` is wired in from the start for app graph introspection.
- The root dashboard exposes a Bimaaji graph snapshot and sovereignty context.
- `/submissions` is the first live domain route for the proposal workspace.

## License

GPL-2.0-or-later
