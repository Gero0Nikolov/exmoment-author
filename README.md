# ExMoment Author

ExMoment Author is a modular WordPress plugin for AI-assisted content authoring, scheduling, and operational editorial workflows.

## Documentation

Project documentation is organized under [`docs/index.md`](docs/index.md).

## What This Plugin Includes

- GPT-powered generation workflows.
- Jobs post type for single and repeating execution.
- Scheduler worker lifecycle and cron integrations.
- Library tooling for reusable content and used-articles tracking.
- Logging and operational diagnostics.
- Settings and admin support pages.

## Repository Layout

- `exmoment-author.php` - plugin bootstrap.
- `Core.php` - core runtime loader and module registration.
- `modules/` - feature modules (`gpt`, `jobs`, `library`, `settings`, and others).
- `resources/` - admin scripts/styles and assets.
- `docs/` - architecture, module guides, settings, operations, references, and archive docs.
- `readme.txt` - WordPress distribution metadata.

## Development Notes

- Runtime modules are autoloaded from `Core.php` configuration.
- Keep docs updated whenever module behavior, hooks, or operational procedures change.

## Related Entrypoint

Contributor/agent orientation is documented in [`AGENTS.md`](AGENTS.md).
