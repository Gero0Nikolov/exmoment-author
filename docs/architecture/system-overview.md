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

## AI Runtime Boundary

Feature controllers construct editorial intent and source context. `GptController` converts the plugin's internal message representation to official WordPress AI Client message DTOs, while `AiService` is the only class that calls the WordPress AI Client. Installed provider adapters register providers and models; WordPress Connectors owns their configuration and credentials.

Provider and model availability is installation-specific and capability-specific. ExMoment Author discovers compatible text and image models at runtime and does not assume one provider or a universal model catalog.

## Related Docs

- [Core Runtime](core-runtime.md)
- [Configuration](configuration.md)
- [Hooks](hooks.md)
- [JavaScript Autoload](javascript-autoload.md)
- [AI Request Lifecycle](ai-request-lifecycle.md)
- [Prompt and Author Context Pipeline](prompt-and-author-context.md)
