# Jobs Module

## Scope

Jobs module powers the `exmoau_job` lifecycle from metadata and scheduling to execution and notices.

## Components

- `modules/jobs/JobsController.php`
- `modules/jobs/JobsMetaController.php`
- `modules/jobs/JobsSchedulingController.php`
- `modules/jobs/JobsExecutionController.php`
- `modules/jobs/JobsArticleCategoryResolver.php`
- `modules/jobs/JobsAiContextResolver.php`
- `modules/jobs/JobsPublicationValidator.php`
- `modules/jobs/JobsErrorController.php`
- `modules/jobs/JobsTimeHelper.php`

## Responsibilities

- Register/manage the Jobs post type.
- Persist schedule and execution metadata.
- Validate publication requirements.
- Execute generation runs and post-processing.
- Emit structured diagnostics and user-facing notices.
- Resolve one effective editorial prompt for instant, single-scheduled, and repeating-scheduled execution.
- Resolve optional public author context from the same effective author ID used when creating the generated post.
- Supply the current WordPress category allowlist to article generation and strictly validate AI-selected category slugs.
- Pass validated article-specific SEO title text to the SEO module, which composes the Yoast-native site suffix.

## Per-Job System Prompt

The **Custom system prompt** meta box stores `exmoau_job_custom_system_prompt`. Blank values inherit the global AI Setup prompt. Non-empty values override only the editorial instructions; the required article/SEO response contract is always retained.

The field preserves line breaks, rejects non-string input and values over 10,000 characters, and uses the existing job nonce, capability, autosave, revision, and validation-notice flow. It remains present when switching between Instant, Single Scheduled, and Repeating job types.

## Shared execution lifecycle

Publish-triggered and manual Instant runs call `runJobNow()` and permit `single_instant`. Scheduler dispatch calls `runScheduledJob()` and permits `single_scheduled` or `repeating_scheduled`. Both routes call `runJobGenerations()` and the same private `executeJob()` method, so prompt resolution, author context, source collection, AI invocation, response parsing, post creation, SEO metadata, and optional image generation share one implementation.

## Prompt construction

`JobsAiContextResolver::resolveSystemPrompt()` chooses the global AI Setup prompt or a valid job override. `JobsExecutionController::buildMessages()` then creates one system instruction in this exact order: mandatory ExMoment Author article/SEO/category response protocol, mandatory category-selection contract and current category JSON allowlist, effective editorial instructions, optional generated author context. Each sanitized source follows as a separate user message. The job override can replace only the editorial section, so it cannot remove the category contract.

The hidden `SEO_TITLE` response field contains only the article-specific plain title. After parsing and validation, `JobsExecutionController` passes it to `YoastSeoIntegration`; the AI is not responsible for Yoast separator or site-name variables.

## Generated post categorisation

`JobsArticleCategoryResolver::getAvailableCategories()` loads current terms from the `category` taxonomy, preserves child categories as independently selectable records, removes duplicate/invalid slugs, and returns only prompt-safe `slug` and `name` values. The list is sorted for stable requests, but list position has no semantic meaning.

The mandatory AI contract supplies that list as JSON and requires `CATEGORY_SLUGS_JSON` to be a JSON array containing only exact allowlisted slugs. The AI selects the most specific appropriate slug and does not need to repeat its parent slugs. One category is preferred; multiple categories are allowed only when the article genuinely spans multiple editorial topics. Names, IDs, comma-separated strings, modified slugs, and explanatory prose are not accepted.

After parsing, `JobsArticleCategoryResolver::resolve()` treats the selection as untrusted input. It requires a list, validates string type, format, and length, compares exact values against the original request allowlist, deduplicates selections, and verifies that every selected slug still resolves to an existing term in the `category` taxonomy. It never fuzzy-matches, accepts a name in place of a slug, creates a term, or uses category-array order as a fallback.

After exact selection validation, the resolver calls WordPress's taxonomy ancestor API for each directly selected term. Each branch is ordered from its top-level ancestor to the selected child; shared ancestors are deduplicated, and branches are sorted by canonical selected slug so neither AI response order nor allowlist position controls the final array. Only the resulting validated hierarchy IDs enter `post_category`.

When the selection is empty or entirely invalid, `JobsExecutionController` supplies no category IDs, records a distinct `jobs.categorisation` warning, and leaves WordPress's configured default-category behavior unchanged. Safe diagnostics distinguish selected slugs, directly selected term IDs, automatically added ancestor IDs, rejected values, hierarchy errors, and final assigned category IDs.

## Job Setup tiles

`JobsMetaController::buildMixturePanelMarkup()` renders directory selection tiles for both the initial editor and Mixture AJAX refreshes. Long labels remain inside the tile with single-line ellipsis; short labels remain visible; the complete escaped label is in `title`. The directory stored in `data-exmoau-job-mixture-tile`, `aria-pressed`, selection classes, and persistence behavior are unchanged by truncation styling.

See [Prompt and Author Context Pipeline](../architecture/prompt-and-author-context.md) and [Jobs Admin Workflows](../operations/jobs-admin-workflows.md).
