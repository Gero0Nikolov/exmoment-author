# Settings Module

## Scope

Settings module provides admin configuration UI and settings registration.

## Components

- `modules/settings/SettingsController.php`
- `modules/settings/controllers/SettingsPageController.php`
- `modules/settings/views/`

## Responsibilities

- Register and persist plugin settings.
- Render settings pages and partials.
- Bridge settings state into GPT/jobs runtime needs.
- Normalize and validate persisted GPT image-model settings against the centralized allowlist before runtime access.
