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
- Register and render `exmoau_ai_image_format` in the **AI Client** tab's featured-image controls, beside Image dimensions, with a WebP default and a strict `jpeg`/`webp`/`png` allowlist. Invalid or unauthorized writes preserve the prior valid value.

Credentials, endpoints, and provider authentication are outside this module and remain owned by WordPress Connectors.
