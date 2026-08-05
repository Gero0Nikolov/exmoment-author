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
