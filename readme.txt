=== ExMoment Author ===
Contributors: exmoment
Donate link: https://author.exmoment.com
Tags: ai-content, editorial-workflow, content-cheduling, publishing-automation, seo-content
Requires at least: 7.0
Requires PHP: 8.3
Tested up to: 7.0
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted content authoring, scheduling, and editorial automation for WordPress.

== Description ==
ExMoment Author is an intelligent content automation system by **ExMoment Ltd**.

It uses the native WordPress AI Client to help you draft, optimize, and schedule posts with any compatible configured provider.

Key features:
- AI-assisted article drafting using your prompts
- Behaviour modes: Autonomous, Augmented, Manual
- Job Scheduler via the plugin’s private Jobs custom post type (admin-only)
- Library module for reusable content bundles (import, preview, manage)
- Provider-aware settings dashboard for connection status, model selection, and diagnostics

Documentation and examples: <a href="https://github.com/Gero0Nikolov/exmoment-author">https://github.com/Gero0Nikolov/exmoment-author</a>

== External Services ==
ExMoment Author uses the WordPress AI Client to generate or optimize content. The AI Client sends a request to the provider an administrator has installed and configured through WordPress Connectors.

Service: Administrator-configured WordPress AI provider
Data sent: Only the text and instructions provided in the ExMoment Author UI for the requested AI action.
Data not sent: ExMoment Author does not automatically transmit site content, user data, or unrelated WordPress data.
When sent: Only on explicit admin/user action inside the plugin.

The applicable terms and privacy policy depend on the provider selected in WordPress Connectors. ExMoment Author does not store provider credentials.

== Screenshots ==
1. ExMoment Author dashboard
2. AI Setup tab – behaviour modes
3. Jobs list (admin-only scheduler)
4. Library management
5. ExMoment Help? - Internal links
6. Single > Instant - Direct workflow
7. Single > Scheduled - Scheduled workflow that runs once
8. Repeating > Scheduled - Scheduled workflow that runs once at the given day and time

== Installation ==
1. Upload the plugin to `/wp-content/plugins/exmoment-author/` or install via the Plugins screen.
2. Activate **ExMoment Author** through “Plugins”.
3. Install and configure a compatible provider in **Settings → Connectors**.
4. Go to **Settings → ExMoment Author** to verify the connection and choose a behaviour mode.
5. (Optional) Create jobs via the Jobs screen (admin-only): add a new job, select a mode (Single – Instant, Single – Scheduled, Repeating – Scheduled), configure sources, and publish.

== Changelog ==
= 1.3.4 =
Reworked generated article categorisation so the AI selects exact existing WordPress category slugs from a request-specific allowlist. Returned slugs are strictly validated before deterministic term assignment, and selected child categories now automatically include their complete parent hierarchy. Removed the previous source-label and ambiguous ID/name resolution paths, with expanded regression coverage for hierarchy assignment, invalid selections, and fallback prevention.

= 1.3.3 =
Improved AI featured-image relevance and author alignment. Image prompts now prioritize article-specific concepts over generic lifestyle imagery, avoid repeatedly defaulting to women, use the public author display name as a guarded gender-presentation cue when a person is relevant, and fall back to neutral or person-free compositions for ambiguous names.

= 1.3.2 =
Fixed generated article categorisation by deterministically matching actual library source categories to existing WordPress category IDs, names, or slugs. Parent and child categories are preserved, invalid or ambiguous matches are logged explicitly, and failed resolution no longer selects an unrelated first category.

= 1.3.1 =
Expanded and corrected technical documentation for the WordPress AI Client lifecycle, author context, custom job prompts, Job Setup tile behavior, testing, and WordPress.org release preparation.

= 1.3.0 =
Migrated AI generation to the WordPress AI Client, added author context and per-job system prompt overrides, and fixed Job Setup tile text overflow.

= 1.2.0 =
Added .MD into the supported stack.

= 1.1.0 =
Minor release adding GPT Image model support and local runtime compatibility improvements.

- Updated OpenAI image generation to support GPT Image models.
- Added an allowlisted image model setting/dropdown with `gpt-image-2` as the default.
- Added support for GPT Image base64 responses and legacy URL responses.
- Removed unsupported `response_format` from current image model requests.
- Kept `dall-e-3` as a legacy fallback path.
- Updated Composer dependencies, including `openai-php/client` to `v0.19.2`.
- Improved local Docker and WP-CLI compatibility.
- Updated WordPress and PHP compatibility metadata.

= 1.0.2 =
Maintenance release updated screenshots, runtime compatibility metadata, Docker WP-CLI support, and GPT Image model defaults.

= 1.0.1 =
Maintenance release with the library welcome popup fix for the Library admin page.

= 1.0.0 =
Initial public release with OpenAI integration, Jobs scheduler (admin-only), Library management, and admin settings.
