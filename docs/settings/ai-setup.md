# AI Setup

This document captures AI-related setup concerns exposed through plugin settings.

## Typical Setup Flow

1. Open plugin settings in wp-admin.
2. Open the native **Settings → Connectors** screen.
3. Install and configure a WordPress AI provider adapter.
4. Return to ExMoment Author and verify the connection status.
5. Leave provider/model on automatic selection or choose a discovered option.
6. Select the behavior and image options required for generation.

## Operational Notes

- Provider credentials belong exclusively to WordPress Connectors; ExMoment Author does not read or store them.
- Revalidate behavior after model or prompt strategy changes.
- Provider and model lists are discovered dynamically and filtered by text or image capability.
- Empty provider/model values mean automatic selection by the WordPress AI Client.
- ExMoment Author accepts the AI Client's inline or remote file DTO for image generation.
- WordPress 7.0 or later is required.
