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

## Per-Job System Prompt

The **Custom system prompt** meta box stores `exmoau_job_custom_system_prompt`. Blank values inherit the global AI Setup prompt. Non-empty values override only the editorial instructions; the required article/SEO response contract is always retained.

The field preserves line breaks, rejects non-string input and values over 10,000 characters, and uses the existing job nonce, capability, autosave, revision, and validation-notice flow. It remains present when switching between Instant, Single Scheduled, and Repeating job types.

## Shared execution lifecycle

Publish-triggered and manual Instant runs call `runJobNow()` and permit `single_instant`. Scheduler dispatch calls `runScheduledJob()` and permits `single_scheduled` or `repeating_scheduled`. Both routes call `runJobGenerations()` and the same private `executeJob()` method, so prompt resolution, author context, source collection, AI invocation, response parsing, post creation, SEO metadata, and optional image generation share one implementation.

## Prompt construction

`JobsAiContextResolver::resolveSystemPrompt()` chooses the global AI Setup prompt or a valid job override. `JobsExecutionController::buildMessages()` then creates one system instruction in this exact order: mandatory ExMoment Author output/SEO protocol, effective editorial instructions, optional generated author context. Each sanitized source follows as a separate user message. The job override can replace only the editorial section.

## Generated post categorisation

The AI response does not select a WordPress category. The category context is the set of library directories that supplied actual source articles for the generation.

`JobsArticleCategoryResolver` resolves each source-category reference against existing terms in the `category` taxonomy. Exact valid IDs take precedence when present; other values use normalized exact slug or name matching. Duplicate-name ambiguity and unmatched values are rejected and logged. Valid child terms remain child terms, multiple legitimate source categories may all be assigned, and the resolver never creates terms or substitutes the first term returned by WordPress.

When no reference resolves, `JobsExecutionController` omits `post_category`, records a `jobs.categorisation` warning, and allows WordPress's configured default-category behavior to apply.

## Job Setup tiles

`JobsMetaController::buildMixturePanelMarkup()` renders directory selection tiles for both the initial editor and Mixture AJAX refreshes. Long labels remain inside the tile with single-line ellipsis; short labels remain visible; the complete escaped label is in `title`. The directory stored in `data-exmoau-job-mixture-tile`, `aria-pressed`, selection classes, and persistence behavior are unchanged by truncation styling.

See [Prompt and Author Context Pipeline](../architecture/prompt-and-author-context.md) and [Jobs Admin Workflows](../operations/jobs-admin-workflows.md).
