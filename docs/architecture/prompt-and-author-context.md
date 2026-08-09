# Prompt and Author Context Pipeline

This document defines the current article prompt ordering, per-job editorial override contract, and author-context privacy boundary.

## Effective editorial prompt

`SettingsController::getEffectiveAiConfiguration()` resolves the current AI Setup behavior mode, model, and global editorial system prompt. `JobsAiContextResolver::resolveSystemPrompt()` then reads `exmoau_job_custom_system_prompt` for the job.

The resolution contract is deterministic:

```text
Blank or invalid custom job prompt
→ use the effective AI Setup editorial prompt

Valid non-empty custom job prompt
→ replace the AI Setup editorial prompt for this job
```

The resolver reports whether the global prompt or job override won, whether an invalid stored value was ignored, and safe prompt length/hash diagnostics. It does not log the prompt text.

## Mandatory article prompt order

`JobsExecutionController::buildMessages()` constructs exactly one internal system message in this order:

1. `Mandatory ExMoment Author protocol (cannot be overridden)` — the `CLOSING_SYSTEM_MESSAGE` article, HTML/title, hidden SEO metadata, and response-shape requirements.
2. `Effective editorial instructions` — either the effective AI Setup prompt or the valid custom job prompt.
3. `Generated runtime context` — optional author context, only when enabled and resolvable.
4. Source documents — separate internal user messages, each prefixed with its sanitized category and filename.

The first three sections are combined into the single system instruction consumed by `GptController::chatCompletionCreate()`. The source messages are converted to `UserMessage` DTOs at the WordPress AI Client boundary. A custom job prompt replaces only item 2. It cannot remove, replace, or disable the mandatory article structure, title/body rules, hidden SEO block, or metadata protocol in item 1.

The same resolution and composition code is used by `JobsExecutionController::executeJob()`. `runJobNow()` permits `single_instant` jobs and is used by publish/manual entry points; `runScheduledJob()` permits `single_scheduled` and `repeating_scheduled` jobs. Both dispatch through `runJobGenerations()` and the same `executeJob()` implementation.

WordPress category assignment remains outside the AI response contract. Source messages retain their library-category labels, and the post-insertion path resolves those labels deterministically against existing WordPress category IDs, names, and slugs. The model is not asked to invent or choose a category.

## Custom job system prompt persistence

The **Custom System Prompt** meta box is registered by `JobsMetaController::registerMetaBoxes()` on the `exmoau_job` editor. Its textarea name and post meta key are `exmoau_job_custom_system_prompt`.

`JobsAiContextResolver::sanitizeCustomSystemPrompt()`:

- accepts strings only;
- normalizes CRLF/CR line endings to LF;
- removes disallowed control characters;
- sanitizes with `sanitize_textarea_field()` so multiline text is retained;
- trims surrounding whitespace;
- rejects values longer than 10,000 characters instead of truncating them.

`JobsMetaController::saveJobMeta()` persists the field only after the shared `exmoau_job_meta_nonce` verifies, autosaves and revisions are rejected, and the user passes `edit_post`. A blank valid submission is stored as blank and restores inheritance from AI Setup. If validation fails, all job-meta writes in that save are aborted, the previous valid prompt remains stored, and the job editor receives a validation notice.

## Author context setting

`exmoau_include_author_name_in_ai_context` is registered through the WordPress Settings API with `manage_options`, sanitized to canonical string values `0` or `1`, and defaults to `0` (disabled). Invalid or unauthorized changes preserve the previous setting.

When enabled, the effective author ID is the same validated `exmoau_setup_directive_post_author` used as the generated post's `post_author`. `JobsAiContextResolver::resolveAuthorDisplayName()` reads only that user's public `display_name`, sanitizes it, and returns blank when the ID or user is invalid.

Article context from `buildArticleAuthorContext()`:

- treats the display name only as tone and voice guidance;
- does not request a visible byline;
- does not require the name to appear in generated content.

Image context from `buildImageAuthorContext()`:

- keeps the article topic primary and includes a person only when the subject benefits from one;
- uses the public display name as a guarded cue for the primary subject's gender presentation when a person is editorially relevant;
- uses a gender-neutral or person-free composition when the public name is ambiguous;
- does not present the subject as the author or invent a specific likeness;
- does not render the author's name, a byline, signature, watermark, logo, or other visible text.

The plugin does not infer or transmit a specific appearance, biography, credentials, personality, personal history, email, login, roles, capabilities, or other private profile data. If no valid display name exists, article and image generation continue without author context and may record only safe boolean diagnostics.

## Featured-image ordering

`GptController::buildImagePromptForPost()` orders image input separately from article messages:

1. optional global image style prompt;
2. article title plus a roughly 30-word plain-text excerpt;
3. truncate that combined base prompt to 500 characters;
4. append mandatory topic-first composition and subject-variation guidance;
5. append the optional generated image author casting context.

No message DTO list is required for image generation because `AiService::generateImage()` receives one prompt string.

## Related documentation

- [AI Request Lifecycle](ai-request-lifecycle.md)
- [Jobs Module](../modules/jobs-module.md)
- [Settings Module](../settings/settings-module.md)
- [Author Context and Job Prompt Implementation Report](../references/author-context-and-job-system-prompt-implementation.md)
