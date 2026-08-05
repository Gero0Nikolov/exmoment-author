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
- Normalize provider/model preferences and image settings before runtime access; compatible model lists come from WordPress AI Client capability discovery.

Credentials, endpoints, and provider authentication are outside this module and remain owned by WordPress Connectors.
