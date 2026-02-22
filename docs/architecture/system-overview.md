# System Overview

ExMoment Author is a modular WordPress plugin that combines AI-assisted generation, job scheduling, editorial controls, and content-library workflows.

## Objectives

- Provide repeatable AI-assisted article generation for admins.
- Support single-run and repeating schedules through the Exo Jobs post type.
- Keep operational controls in wp-admin with clear logging and diagnostics.
- Use module-level boundaries so features can evolve independently.

## Runtime Shape

- Entry bootstrap: `exmoment-author.php`.
- Core loader: `Core.php` (`ExMomentAuthorCoreSystem`).
- Modules: `modules/<name>/` for controllers/services.
- Admin assets: `resources/src/` and `resources/dist/`.
- Vendor dependencies: `vendor/` via Composer autoload.

## Main Data and Control Flows

1. WordPress loads plugin bootstrap.
2. Core registers class autoloader and module autoload map.
3. Modules register actions/filters for admin pages, scheduler hooks, and AJAX handlers.
4. Jobs pipeline coordinates scheduling, execution, logging, SEO integration, and used-article tracking.

## Related Docs

- [Core Runtime](core-runtime.md)
- [Configuration](configuration.md)
- [Hooks](hooks.md)
- [JavaScript Autoload](javascript-autoload.md)
