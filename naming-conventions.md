# Naming conventions and layer rules

This file defines the layers in this project, what each one is responsible for, and how files are named. Use it when creating, moving, or renaming files.

## Project settings

- Stack: vanilla PHP 8.3+ (no framework), MySQL 8, Tailwind CSS 3
- Backend source root: `working-php/src/`
- Frontend: server-rendered templates in `working-php/src/Views/<role>/`, scripts in `working-php/public/js/`
- Case style for file names: PSR-4 PascalCase for class files (file name = class name); kebab-case for templates, scripts and JS files
- Pattern: `<Feature><Layer>.php` (e.g. `DocumentController.php`, `HashChainService.php`, `UserModel.php`). Features live in `src/Modules/<Feature>/`; jobs in `Jobs/` and events in `Events/` inside the module.
- `src/Common/` holds framework infrastructure, which keeps its role name (`Router`, `Database`, `Cache`, `Config`, `Validator`, `EventDispatcher`, `Paginator`, `CursorPaginator`); the route-level role check is `Common/Guards/RoleGuard`.
- `src/Research/` (test and evidence tooling) is outside the app's layers.

## Core rules

1. One layer per file. The suffix must name that layer.
2. If a file does the work of two layers, split it. Do not pick one suffix and leave mixed code.
3. Organize by feature first, then by layer: `modules/applicants/applicant.service.ts`, not `services/applicant.service.ts`.
4. Code shared by every feature goes in `common/`, not inside a feature module.
5. Feature names are singular in file names (`applicant.service.ts`) and plural in folder names (`applicants/`).
6. Tests mirror the file they test: `applicant.service.ts` → `applicant.service.spec.ts`.

## Layer reference

### Backend request path (top to bottom)

| Suffix | Layer | Responsibility | Must NOT contain |
|---|---|---|---|
| `.routes` | Routes | Map HTTP method + URL to a controller method | Logic, validation, DB access |
| `.middleware` | Middleware | Per-request concerns before the handler: logging, CORS, authentication (who is the user) | Feature-specific business rules |
| `.guard` | Guard | Route-level allow/deny (e.g. admin-only routes) | Checks that need a specific loaded record |
| `.controller` | Controller | Read params/body, call one service method, return status + response | Business rules, DB queries |
| `.dto` | DTO | Shape of request input or response output | Behavior |
| `.validator` / `.schema` | Validator | Server-side input validation (enforced) | Business rules that need DB state |
| `.policy` | Policy | Record-level authorization: can user X do action Y on record Z. Pure function: `(user, record) => boolean` | DB queries, HTTP objects |
| `.service` | Service | Business rules and orchestration. Callable from controllers, workers, CLI, tests | `req`/`res`, status codes, raw SQL |
| `.repository` | Repository | All database reads and writes for one entity | Business rules |
| `.entity` / `.model` | Model | Table/record definition and its fields/relations | Queries beyond simple relations, business rules |
| `.mapper` / `.serializer` / `.resource` | Mapper | Convert entities to API output; hide internal fields | DB access |

### Data

| Location / suffix | Layer | Responsibility |
|---|---|---|
| `database/migrations/<timestamp>_<action>.ts` | Migration | One versioned schema change |
| `database/seeders/<feature>.seeder.ts` | Seeder | Initial or sample data |
| `config/database.config.ts` | DB config | Connection settings from env |

### Async / background

| Suffix | Layer | Responsibility |
|---|---|---|
| `.event` | Event | Describes something that happened, past tense (`applicant-submitted.event.ts`) |
| `.listener` | Listener | Reacts to one event |
| `.job` | Job | One unit of background work placed on a queue |
| `.worker` | Worker | Process that pulls jobs from a queue and runs them |
| `.cron` | Scheduler | Work triggered by time, not by requests |

Jobs and workers call services. They never duplicate business logic.

### Cross-cutting

| Suffix | Layer | Responsibility |
|---|---|---|
| `.module` | Module | Wires one feature's layers together (only if the framework uses module files) |
| `.config` | Config | Settings read from environment |
| `.adapter` / `.client` | Adapter | Wrapper around an external system (SMTP, S3, payment API) |
| `.util` | Utility | Pure helper with no business meaning (`format-date.util.ts`) |
| `.enum` / `.constants` | Constants | Fixed shared values |
| `.types` | Types | Shared type definitions |
| `.exception` | Exception | Custom error type mapped to an HTTP status |
| `.spec` / `.test` | Test | Tests for the matching file |

### Frontend

| Pattern | Layer | Responsibility |
|---|---|---|
| `<Name>Page.tsx` or framework route file | Page | One full screen tied to a client route |
| `<Name>Layout.tsx` | Layout | Shared shell around pages |
| `<Name>.tsx` (PascalCase) | Component | Render props, emit events. No fetching |
| `use<Name>.ts` | Hook / composable | Reusable stateful logic, data fetching via the API client |
| `<feature>.store.ts` | Store | Shared client state (auth, theme) |
| `<feature>.api.ts` | API client | HTTP calls to the backend only |
| `<feature>.schema.ts` | Client validation | Form feedback for UX. Backend still re-validates |

## Target folder structure

```
src/
  modules/
    <feature>/                  # plural, e.g. applicants/
      <feature>.routes.ts
      <feature>.controller.ts
      <feature>.service.ts
      <feature>.repository.ts
      <feature>.entity.ts
      <feature>.policy.ts
      <feature>.mapper.ts
      dto/
        create-<feature>.dto.ts
        update-<feature>.dto.ts
      jobs/
      events/
      <feature>.service.spec.ts
  common/
    middleware/
    guards/
    exceptions/
    utils/
  config/
  workers/
database/
  migrations/
  seeders/
```

## How to classify an existing file

Ask these in order and stop at the first "yes":

1. Does it touch `req`/`res` or return status codes? → controller (or middleware if it runs for many routes).
2. Does it only map URLs to handlers? → routes.
3. Does it decide whether a user may act on a specific record? → policy.
4. Does it run SQL / ORM queries? → repository (move any business rules out to a service).
5. Does it define table fields? → entity/model.
6. Does it enforce business rules? → service.
7. Is it run from a queue or schedule? → job / worker / cron.
8. Does it wrap a third-party API? → adapter.
9. Is it a pure helper with no domain meaning? → util.

If a file answers "yes" to more than one, flag it for splitting rather than renaming it.

## Rename workflow

1. Before changing anything, produce a plan as a table: `current path | new path | layer | needs split? (y/n)`. Wait for approval.
2. Work one feature module at a time.
3. Use `git mv` for every move so history is kept.
4. Update every import that referenced the old path. Search the whole repo, including tests, config, and dynamic imports/string paths.
5. Do not change behavior during a rename. Splits are separate commits from renames.
6. After each module: run the type checker, linter, and tests. Fix breakages before moving on.
7. One commit per module: `refactor(<feature>): rename files to layer conventions`.
