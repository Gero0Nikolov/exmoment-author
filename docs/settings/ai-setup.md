# AI Setup

This document captures AI-related setup concerns exposed through plugin settings.

## Typical Setup Flow

1. Open plugin settings in wp-admin.
2. Open the native **Settings → Connectors** screen.
3. Install and configure a WordPress AI provider adapter.
4. Return to ExMoment Author and verify the connection status.
5. Leave provider/model on automatic selection or choose a discovered option.
6. Select the behavior and image options required for generation.
7. Enable **Include author name in AI context** only when the configured post author's public display name should guide article tone and featured-image subject casting.

## Operational Notes

- Provider credentials belong exclusively to WordPress Connectors; ExMoment Author does not read or store them.
- Revalidate behavior after model or prompt strategy changes.
- Provider and model lists are discovered dynamically and filtered by text or image capability.
- Empty provider/model values mean automatic selection by the WordPress AI Client.
- ExMoment Author accepts the AI Client's inline or remote file DTO for image generation.
- Author context is disabled by default. When enabled, only the effective post author's public WordPress display name is sent; email addresses, usernames, roles, and other user metadata are excluded.
- The author setting applies to both article generation and featured-image prompts. When an image benefits from one primary person, the public display name guides that subject's gender presentation. The prompt does not identify the subject as the author or request a specific likeness, and it prohibits names, bylines, signatures, watermarks, logos, and other visible text.
- A job can replace the global editorial system prompt with its **Custom system prompt** field. A blank field inherits the global prompt, and prompts longer than 10,000 characters are rejected without overwriting the previous value.
- WordPress 7.0 or later is required.

## Provider adapters, providers, and models

A provider adapter is the installed WordPress integration that registers a provider and model metadata with the WordPress AI Client. The provider is usable only after its adapter is configured in WordPress Connectors. ExMoment Author discovers models separately for text and image capability; a model shown for one operation is not assumed to support the other.

The provider and model controls are preferences, not credentials. Empty values request automatic selection from the eligible configured providers and capability-compatible models. An explicit provider or model that is no longer eligible produces a stopped, normalized configuration error rather than silently switching to an unrelated service.

Availability is installation-specific. The suggested adapter names in the UI are setup guidance, not a claim that those providers are installed, configured, compatible with every operation, or bundled with ExMoment Author.

## Editorial prompt precedence

AI Setup resolves the general editorial prompt according to Autonomous, Augmented, or Manual behavior. Every job inherits that prompt unless its **Custom System Prompt** meta box contains a valid non-empty value. A job override replaces only the general editorial section. The mandatory ExMoment Author article/title/HTML/SEO output protocol is always prepended separately and remains active.

See [Prompt and Author Context Pipeline](../architecture/prompt-and-author-context.md) for exact ordering and persistence rules.

## Author context privacy

`exmoau_include_author_name_in_ai_context` defaults to disabled. When enabled, the plugin resolves only the selected effective author's public `display_name`. It does not send email, login, roles, capabilities, biography, credentials, private profile data, or additional profile traits. The image provider may interpret the public name only as the guarded gender-presentation cue described below.

For articles, the display name is tone/voice context and does not request a byline or require the author name in output. Featured-image prompts remain topic-first and avoid generic, repetitively gendered stock imagery. When a person is editorially relevant, the display name guides the primary subject's gender presentation without presenting the subject as the author or inventing a likeness. Ambiguous names use a gender-neutral or person-free composition. If a valid display name cannot be resolved, generation continues without author context.

## Errors and diagnostics

Administrators may see provider-neutral states for unavailable client/provider, unconfigured provider, invalid model, unsupported capability, authentication, permission/billing, rate/quota, invalid request, timeout/outage, or unknown failure. Debug logs may retain sanitized identifiers, HTTP status, exception class, capability, and timing. They must never expose credentials, raw authorization values, or unsanitized provider payloads.
