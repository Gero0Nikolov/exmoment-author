# Architecture Docs

## Purpose

- Describe plugin runtime composition and lifecycle.
- Map bootstrap, autoload, and module registration behavior.
- Provide a single place for hook-level runtime references.
- Explain how admin JS/CSS assets are loaded.
- Support contributor onboarding before module-level work.

## Documents

- [System Overview](system-overview.md) - High-level architecture and flow map.
- [Core Runtime](core-runtime.md) - Responsibilities and lifecycle of `ExMomentAuthorCoreSystem`.
- [Configuration](configuration.md) - Runtime config map and module registration model.
- [Hooks](hooks.md) - Key actions/filters across core and modules.
- [JavaScript Autoload](javascript-autoload.md) - Admin script/style loading and conventions.
- [AI Request Lifecycle](ai-request-lifecycle.md) - Current WordPress AI Client boundary, discovery, DTOs, capabilities, and errors.
- [Prompt and Author Context Pipeline](prompt-and-author-context.md) - Mandatory protocol ordering, job overrides, and author privacy.
- [AI Client Migration Audit](ai-client-migration-audit.md) - Historical evidence inventory of the pre-migration transport.
