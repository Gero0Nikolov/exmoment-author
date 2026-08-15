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

1. `Mandatory ExMoment Author protocol (cannot be overridden)` — the `CLOSING_SYSTEM_MESSAGE` article, standalone editorial title, HTML/title, hidden SEO/category metadata, precedence, and response-shape requirements.
2. `Mandatory WordPress category-selection contract (cannot be overridden)` — the exact current category JSON allowlist plus strict slug-selection rules.
3. `Effective editorial instructions` — either the effective AI Setup prompt or the valid custom job prompt.
4. `Generated runtime context` — optional author context, only when enabled and resolvable.
5. Source documents — separate internal user messages, each prefixed with its sanitized library category and filename.

The first four sections are combined into the single system instruction consumed by `GptController::chatCompletionCreate()`. The source messages are converted to `UserMessage` DTOs at the WordPress AI Client boundary. A custom job prompt replaces only item 3. It supplements the plugin-level requirements and cannot remove, replace, or disable the mandatory article structure, title/body rules, hidden metadata block, category allowlist, or category-selection protocol. The mandatory protocol makes this precedence explicit to the model.

The top-level editorial title must be a clear, compelling, standalone title that represents the complete article. It must be natural, article-specific, uniquely written, and publication-ready rather than generic or templated. It must not be copied from a section heading, opening paragraph, excerpt, or opening sentence, and it must never join an `<h2>` or other heading to the beginning of body text.

The editorial title path is independent from the hidden SEO title path:

```text
leading AI # title or <h1> title
→ JobsExecutionController::extractTitleAndBody()
→ title sanitization in JobsExecutionController::createPost()
→ WordPress post_title
```

The parser does not combine a correctly structured top-level title with article body text. Its legacy fallback derives a title from content only when the AI omits the required leading `#` or `<h1>` title, so the mandatory prompt explicitly prevents the malformed section-heading/body pattern without introducing heuristic title rewriting.

The same resolution and composition code is used by `JobsExecutionController::executeJob()`. `runJobNow()` permits `single_instant` jobs and is used by publish/manual entry points; `runScheduledJob()` permits `single_scheduled` and `repeating_scheduled` jobs. Both dispatch through `runJobGenerations()` and the same `executeJob()` implementation.

## SEO title ownership boundary

The mandatory hidden metadata block asks the AI for a plain, article-specific `SEO_TITLE` of at most 60 characters. Site-level branding is intentionally outside the AI contract.

The title path is:

```text
AI article-specific SEO_TITLE
→ parser and existing title validation
→ exact Yoast-variable deduplication
→ append %%sep%% %%sitename%%
→ store in _yoast_wpseo_title
→ Yoast resolves the configured separator and current site name
```

`JobsExecutionController` owns response parsing and initial validation. `YoastSeoIntegration` owns canonical template composition and conditional metadata persistence. Yoast owns rendering of `%%sep%%` and `%%sitename%%`. This split keeps prompts provider-neutral, avoids hardcoded punctuation or branding, and allows a stored generated title to follow later Yoast separator or WordPress site-title changes.

Only the exact Yoast variables are normalized. Literal strings such as hyphens, pipes, bullets, or possible site names are not stripped because the plugin cannot safely distinguish them from legitimate article-title text. Invalid or empty titles keep the existing no-write behavior, and unavailable Yoast returns without hard coupling or fatal errors.

## WordPress category-selection contract

Before article generation, `JobsArticleCategoryResolver::getAvailableCategories()` reads current terms from the `category` taxonomy and reduces them to JSON records containing canonical `slug` and descriptive `name` values. Child terms remain independent records. Category names are data for semantic accuracy; only slugs are authoritative.

The AI must return one predictable metadata field:

```text
CATEGORY_SLUGS_JSON: ["exact-existing-slug"]
```

The field must decode to a JSON list. Scalar strings, objects, comma-separated text, markdown, names, IDs, unknown slugs, approximations, and explanatory prose are invalid. The prompt tells the AI to select the most specific appropriate slug, prefers one category, permits multiple only for genuinely multi-topic articles, and permits an empty list only when no supplied category fits. The AI does not repeat parent slugs; hierarchy expansion belongs to WordPress/PHP.

The canonical assignment path is:

```text
current WordPress category slug/name allowlist
→ mandatory AI category-selection contract
→ CATEGORY_SLUGS_JSON list
→ exact original-allowlist validation
→ directly selected category-term IDs
→ WordPress ancestor lookup for each selected term
→ deterministic root-to-leaf hierarchy IDs
→ shared-ancestor deduplication
→ post_category
```

The plugin owns trust enforcement, hierarchy expansion, and taxonomy assignment. It validates type, list shape, exact formatting, length, allowlist membership, deduplication, and current term existence. After direct selection validation, it uses `get_ancestors()` and validates every returned term in the `category` taxonomy before building a deterministic root-to-leaf final ID list. It does not add siblings, fuzzy-match, normalize an approximate response, accept names or IDs, create terms, or choose the first allowlist item. Empty or entirely invalid selections produce a categorisation warning and no supplied category IDs; WordPress's configured default-category behavior remains unchanged.

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
