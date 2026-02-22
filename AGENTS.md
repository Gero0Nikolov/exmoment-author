# ExMoment Author Agent Guide

This file is the contributor and agent orientation entrypoint for this repository.

## Primary Documentation Entry

- Start at [`docs/index.md`](docs/index.md).

## Repository Purpose

- WordPress plugin for AI-assisted authoring and scheduling.
- Modular architecture under `modules/`.
- Core runtime loader in `Core.php`.
- Plugin bootstrap in `exmoment-author.php`.

## Quick Navigation

- Bootstrap: `exmoment-author.php`
- Core runtime and module autoload: `Core.php`
- Modules root: `modules/`
- Admin scripts/styles source: `resources/src/`
- Compiled styles: `resources/dist/`
- Screenshots: `screenshots/`
- Third-party dependencies: `vendor/`
- Canonical project docs: `docs/`

## How Runtime Boots

1. WordPress loads `exmoment-author.php`.
2. Plugin includes Composer autoload and `Core.php`.
3. `ExMomentAuthorCoreSystem` configures autoload + hooks.
4. Module controllers from the autoload map are initialized.
5. Module constructors register hooks/AJAX/admin pages.

## Core Runtime Responsibilities

- Register activation/deactivation behavior.
- Register class autoloader for `ExMomentAuthor\\Modules\\*` classes.
- Build and own runtime config structure.
- Initialize module controllers.
- Keep scheduler event present.
- Register and enqueue shared admin resources.
- Expose module instances through `getModule()`.

## Autoload and Controller Rules

- Do not add manual `require` statements for module controllers.
- Add new module controllers by extending `config['autoload']` in `Core.php`.
- Keep namespace and file path aligned:
  - `ExMomentAuthor\\Modules\\Jobs\\JobsController` -> `modules/jobs/JobsController.php`
- If a controller should not auto-instantiate, set `instantiate` to `false`.

## Module Layout

Each first-level folder in `modules/` is a feature boundary.

### `modules/cache/`

- Cache refresh integrations on content save.
- Main files:
  - `FlygRecacheService.php`
  - `SavePostFlygRecache.php`

### `modules/gpt/`

- GPT/OpenAI integration and generation helpers.
- Main files:
  - `GptController.php`
  - nested controller helpers under `Controllers/` (autoload map references)

### `modules/help/`

- Help page and admin bar shortcut integration.
- Main files:
  - `HelpController.php`
  - `HelpAdminBar.php`

### `modules/jobs/`

- Job post type, metadata, scheduling, execution, validation, and worker orchestration.
- Main files:
  - `JobsController.php`
  - `JobsMetaController.php`
  - `JobsSchedulingController.php`
  - `JobsSchedulerWorker.php`
  - `JobsExecutionController.php`
  - `JobsPublicationValidator.php`
  - `JobsErrorController.php`
  - `JobsTimeHelper.php`

### `modules/library/`

- Content library admin UI and used-articles persistence.
- Main files:
  - `LibraryController.php`
  - `UsedArticlesRepository.php`
  - `views/index.php`

### `modules/log/`

- Structured logging service and admin log view.
- Main files:
  - `LogService.php`
  - `LogAdminController.php`
  - `views/admin-log.php`

### `modules/seo/`

- SEO adapter/integration points.
- Main file:
  - `YoastSeoIntegration.php`

### `modules/settings/`

- Settings registration and admin pages.
- Main files:
  - `SettingsController.php`
  - `controllers/SettingsPageController.php`
  - `views/`

## Key Runtime Hooks to Know

Core-level hooks include:

- `init` (module autoload, scheduler guards)
- `admin_init` (scheduler guard)
- `wp_loaded` (scheduler guard)
- `admin_enqueue_scripts` (shared admin assets)
- `login_enqueue_scripts` (login assets)
- `wp_ajax_exmoau_pulse_vibe`
- `wp_ajax_nopriv_exmoau_pulse_vibe`
- `cron_request` filter (local Docker cron behavior)

Scheduler-focused hooks include:

- `exmoau_minutely_worker`
- `cron_schedules`

## Docs Structure (Where to Add Docs)

- Top-level docs hub: [`docs/index.md`](docs/index.md)
- Architecture docs: `docs/architecture/`
- Module docs: `docs/modules/`
- Settings docs: `docs/settings/`
- Operations runbooks: `docs/operations/`
- Historical archive: `docs/archive/`
- References and quick lookup pages: `docs/references/`

## Documentation Rule of Thumb

- Docs for a thing should live near the concern in `docs/`.
- If behavior is feature-oriented, update `docs/modules/`.
- If behavior is runtime-wide, update `docs/architecture/`.
- If behavior is procedural, update `docs/operations/`.
- If behavior is historical context, update `docs/archive/`.
- If behavior is lookup material, update `docs/references/`.

## Contributor Guardrails

- Keep plugin code modular.
- Prefer extending existing module boundaries over cross-module coupling.
- Do not edit `vendor/` directly.
- Keep scheduler-related changes paired with operational docs updates.
- Keep GPT/settings changes paired with docs updates in both modules/settings docs.
- Keep paths and namespaces consistent with existing patterns.

## Security and Validation Notes

When editing runtime code, verify:

- Capability checks for admin actions.
- Nonce verification for AJAX and form submissions.
- Input validation/sanitization before persistence or filesystem operations.
- Context-appropriate output escaping in views.
- Safe file and path checks for library operations.

## Adding a New Module

1. Create folder under `modules/<new-module>/`.
2. Add controller/service classes with matching namespace.
3. Register class(es) in `Core.php` `autoload` map.
4. Add module-specific config in `moduleConfig` if needed.
5. Register hooks inside controller constructors.
6. Add/extend docs under:
   - `docs/modules/<new-module>.md`
   - any related architecture/operations pages
7. Update `docs/modules/index.md` and `docs/index.md` links as needed.

## Editing Existing Modules

Before edits:

- Identify module owner files and downstream dependencies.
- Review related docs pages in `docs/modules/` and `docs/operations/`.
- Confirm existing hooks to avoid duplicate registration.

After edits:

- Re-check module constructor hooks.
- Validate admin UI paths and notices.
- Update docs for behavior changes.

## Working on Scheduler Features

- Review:
  - `modules/jobs/JobsSchedulerWorker.php`
  - `modules/jobs/JobsSchedulingController.php`
  - `modules/jobs/JobsExecutionController.php`
- Keep execution and scheduling concerns separated.
- Document any schedule semantics change in:
  - `docs/modules/scheduler.md`
  - `docs/operations/scheduler-lifecycle.md`

## Working on Library Features

- Review:
  - `modules/library/LibraryController.php`
  - `modules/library/UsedArticlesRepository.php`
  - activation seeding logic in `Core.php`
- Keep filesystem safety checks intact.
- Document operational impacts in:
  - `docs/modules/library-module.md`
  - `docs/operations/library-admin-ui.md`
  - `docs/operations/library-seeding.md`

## Working on GPT Features

- Review:
  - `modules/gpt/GptController.php`
  - settings integration points
- Keep API behavior configurable through settings.
- Update:
  - `docs/modules/gpt.md`
  - `docs/settings/ai-setup.md`
  - `docs/operations/gpt-debug-mode.md` when troubleshooting flow changes.

## Working on Settings Features

- Review:
  - `modules/settings/SettingsController.php`
  - `modules/settings/controllers/SettingsPageController.php`
- Ensure setting defaults and sanitization are coherent.
- Update:
  - `docs/settings/settings-module.md`
  - `docs/settings/ai-setup.md`

## Verification Checklist for Doc Changes

- Every docs folder has an `index.md`.
- Each folder `index.md` links all markdown files in that folder.
- `docs/index.md` links all section indexes.
- Relative links resolve from current file location.
- Root `README.md` links to `docs/index.md`.
- Root `AGENTS.md` links to `docs/index.md`.
- Keep links aligned with the canonical `docs/` tree.

## Non-Goals for Regular Feature PRs

- Do not introduce static doc-site tooling unless explicitly scoped.
- Do not refactor docs CI paths unless explicitly scoped.
- Do not alter WordPress distribution metadata in `readme.txt` unless release-scoped.

## Source of Truth Reminder

- Runtime truth is in plugin code (`Core.php` + `modules/`).
- Operational truth is in `docs/operations/` runbooks.
- Architecture truth starts at `docs/architecture/` and should reflect current code.
