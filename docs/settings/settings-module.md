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

## Prompt Precedence

The global manual system prompt remains the default editorial instruction. A non-empty, valid `exmoau_job_custom_system_prompt` post-meta value replaces it for that job. Mandatory output and SEO protocol instructions are composed separately and cannot be removed by a job override.
