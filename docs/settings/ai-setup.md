# AI Setup

This document captures AI-related setup concerns exposed through plugin settings.

## Typical Setup Flow

1. Open plugin settings in wp-admin.
2. Configure OpenAI/API credentials used by GPT flows.
3. Select model/behavior options required for generation.
4. Review the AI image configuration, which now defaults to `gpt-image-2`.
5. Save and validate configuration through available diagnostics.

## Operational Notes

- Keep credentials in WordPress options, never hardcoded in source.
- Revalidate behavior after model or prompt strategy changes.
- AI image model selections are validated against a fixed allowlist: `gpt-image-2`, `gpt-image-1.5`, `gpt-image-1`, `gpt-image-1-mini`, and `dall-e-3`.
- `dall-e-3` is retained only as a legacy-compatible option for image generation, and its availability depends on the current OpenAI API/runtime.
- Current GPT Image requests are sent without `response_format`; the plugin accepts either base64-style or URL-style image responses from the OpenAI Images API.
