=== ExMoment Author ===
Contributors: exmoment
Donate link: https://author.exmoment.com
Tags: ai, automation, content creation, openai, gpt, editorial workflow, scheduling
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted content authoring, scheduling, and editorial automation for WordPress.

== Description ==
ExMoment Author is an intelligent content automation system by **ExMoment Ltd**.

It integrates OpenAI models to help you draft, optimize, and schedule posts, while keeping full control inside the WordPress admin.

Key features:
- AI-assisted article drafting using your prompts
- Behaviour modes: Autonomous, Augmented, Manual
- Job Scheduler via the plugin’s private Jobs custom post type (admin-only)
- Library module for reusable content bundles (import, preview, manage)
- Settings dashboard for API keys, model selection, and diagnostics

Documentation and examples: https://github.com/Gero0Nikolov/exmoment-author

== External Services ==
ExMoment Author uses the OpenAI API to generate or optimize content when an administrator explicitly triggers an AI action inside the plugin.

Service: OpenAI API
Data sent: Only the text and instructions provided in the ExMoment Author UI for the requested AI action.
Data not sent: ExMoment Author does not automatically transmit site content, user data, or unrelated WordPress data.
When sent: Only on explicit admin/user action inside the plugin.

OpenAI Terms: https://openai.com/terms
OpenAI Privacy Policy: https://openai.com/policies/privacy-policy

== Screenshots ==
1. ExMoment Author dashboard
2. AI Setup tab – behaviour modes
3. Jobs list (admin-only scheduler)
4. Library management

== Installation ==
1. Upload the plugin to `/wp-content/plugins/exmoment-author/` or install via the Plugins screen.
2. Activate **ExMoment Author** through “Plugins”.
3. Go to **Settings → ExMoment Author** to add your OpenAI API key and choose a behaviour mode.
4. (Optional) Create jobs via the Jobs screen (admin-only): add a new job, select a mode (Single – Instant, Single – Scheduled, Repeating – Scheduled), configure sources, and publish.

== Changelog ==
= 1.0.0 =
Initial public release with OpenAI integration, Jobs scheduler (admin-only), Library management, and admin settings.