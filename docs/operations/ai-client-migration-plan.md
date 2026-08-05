# AI Client Migration Plan

## Target Architecture

```text
Jobs and Settings
  -> GptController compatibility/orchestration
  -> ExMoment AiService
  -> WordPress AI Client
  -> configured provider adapter
```

Only `AiService` may call `wp_ai_client_prompt()` or the WordPress AI Client registry. Existing editorial, source-packing, parsing, validation, SEO, queue, and publication code stays above that boundary.

## Affected Files

- `modules/ai/AiService.php`: new service and transport boundary.
- `Core.php`: AI service registration and shared AI request timeout.
- `modules/gpt/GptController.php`: delegation, message conversion, normalized result compatibility, and image DTO handling.
- `modules/jobs/JobsExecutionController.php`: remove API-key bootstrap while preserving the generation workflow.
- `modules/settings/SettingsController.php`, settings page controller, and views: remove key ownership; add discovery, selection, and status.
- `composer.json`, `composer.lock`, and `vendor/`: remove the direct OpenAI/HTTP dependency tree.
- `exmoment-author.php` and `readme.txt`: require WordPress 7.0.
- AI, settings, architecture, operations, and reference documentation.

## Phases

1. Freeze behavior and audit all AI entry points, calls, settings, parsing, errors, retries, jobs, AJAX, REST, and dependencies.
2. Add `AiService` using only verified official WordPress AI Client APIs.
3. Route legacy controller methods through the service while preserving caller-facing response shapes.
4. Replace provider-specific settings with dynamic status and optional provider/model preferences.
5. Remove direct client packages and update minimum WordPress metadata.
6. Run static, runtime, disconnected, debug-bypass, and browser regression checks.
7. Record implementation evidence and any environment-limited tests.

## Testing Strategy

- Lint every first-party PHP file and validate Composer metadata/lock consistency.
- Search for direct provider namespaces, endpoints, credential options, and hardcoded runtime model names.
- Resolve `AiService` through the real core container under WordPress 7.0.
- Exercise disconnected/unavailable status and generation guards.
- Exercise debug chat and downstream legacy response extraction.
- With configured test adapters, run text and image generation using automatic and manual provider/model choices; then test an invalid model, an unconfigured provider, provider failure, and timeout.
- Run a full job using unchanged source packing and compare the article, metadata, SEO, validation, and publication stages with the baseline.
- Inspect the AI Client settings tab in wp-admin, including escaping and the native Connectors link.

## Rollback Strategy

Revert the migration commit as one unit, restoring the old Composer files/vendor tree, controller transport, settings registrations/view, and WordPress compatibility metadata. No provider key is copied or deleted during migration: an existing legacy option remains untouched in the database but is no longer registered, rendered, or read. This makes rollback possible without exposing or transforming the secret. Back up the database before rollout as normal.

## Rollout Guardrails

- Configure and verify at least one compatible provider in WordPress Connectors before enabling production jobs.
- Keep debug logging off in production except during a bounded investigation.
- Test text and image capabilities separately; not every provider/model supports both.
- Treat an invalid saved preference as a stopped generation, not as permission to silently switch providers.
