# Settings Module Guide

This guide focuses on settings behavior and admin configuration surfaces.

## Source Files

- `modules/settings/SettingsController.php`
- `modules/settings/controllers/SettingsPageController.php`
- `modules/settings/views/`

## Coverage

- Option registration and defaults.
- Admin page/menu registration.
- Settings-side integration hooks used by GPT/jobs behavior.
- Provider-aware AI Client status and selection.
- Navigation to the native WordPress Connectors screen.

Provider credentials are intentionally absent from this module. The settings owned by ExMoment Author are provider preference, output token budget, behavior/prompt settings, debug mode, and image preferences.

## Author Context

`exmoau_include_author_name_in_ai_context` is a strict boolean-like option with a disabled default. The settings sanitizer accepts only canonical enabled/disabled values and preserves the prior value for invalid or unauthorized writes.

When enabled, generation resolves the selected directive post author and supplies only that user's public `display_name`. The same rule is used for article and featured-image generation.

The option is registered as `exmoau_include_author_name_in_ai_context`, defaults to string `0`, and accepts only canonical `0`/`1` values (plus equivalent boolean/integer inputs handled by its sanitizer). `manage_options` is required. Invalid or unauthorized writes preserve the previous value and add a settings error.

## Prompt Precedence

The global manual system prompt remains the default editorial instruction. A non-empty, valid `exmoau_job_custom_system_prompt` post-meta value replaces it for that job. Mandatory output and SEO protocol instructions are composed separately and cannot be removed by a job override.

The effective global prompt is mode-dependent: Autonomous uses the built-in template, Augmented uses the saved optimized prompt or safe fallback, and Manual uses the administrator's sanitized prompt. `JobsAiContextResolver` applies job precedence after `SettingsController::getEffectiveAiConfiguration()` resolves that mode-level value.

## Provider and model preferences

Text and image model choices are built from the WordPress AI Client registry and filtered by requested capability. Empty identifiers represent automatic selection. The settings module does not maintain a hardcoded provider catalog and does not own provider endpoints or credentials.

See [AI Request Lifecycle](../architecture/ai-request-lifecycle.md) and [AI Setup](ai-setup.md).
