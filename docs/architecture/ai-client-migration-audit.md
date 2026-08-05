# AI Client Migration Audit

> Historical document: this audit describes the pre-1.3.0 direct OpenAI transport. For the maintained architecture, use [AI Request Lifecycle](ai-request-lifecycle.md).

## Scope and Method

This is the read-only audit completed before the transport migration. Findings came from `Core.php`, `composer.json`, `modules/gpt/`, `modules/jobs/`, `modules/settings/`, module views, and repository-wide searches for AI calls, HTTP endpoints, AJAX hooks, and REST registration.

## Pre-Migration Architecture

`GptController` was the only class that constructed the direct OpenAI client. It created an `openai-php/client` instance with the API key owned by `SettingsController`, an explicit `https://api.openai.com/v1` base URI, and Guzzle transport. It also constructed provider payloads and parsed provider responses.

The call graph was:

```text
Scheduler / admin job execution
  -> JobsExecutionController
  -> GptController::chatCompletionCreate()
  -> OpenAI Chat API
  -> choices[0].message.content
  -> existing article, metadata, SEO, validation, and publication flow

Settings save in augmented mode
  -> SettingsController::performPromptAugmentation()
  -> GptController::chatCompletionCreate()
  -> decoded optimized prompt persisted by SettingsController

Post publication image step
  -> GptController::generateFeaturedImageForPost()
  -> OpenAI Images API
  -> base64 or URL response
  -> WordPress media attachment and featured image
```

## AI Components and Calls

- `modules/gpt/GptController.php`: client construction; text completion, chat, model-list, and image requests; response normalization; debug bypass; model caching; image media persistence.
- `modules/jobs/JobsExecutionController.php`: main article-generation caller, response extraction, article/SEO parsing, post creation, and featured-image invocation.
- `modules/settings/SettingsController.php`: AI settings ownership, model lists, augmented-prompt generation, response decoding, notices, and diagnostics.
- `modules/settings/views/`: API-key, model, behavior, prompt, debug, and image controls.
- `modules/gpt/controllers/`: legacy function-controller manifests and helpers; no independent HTTP client.

The direct calls were `completions()->create()` with `text-davinci-003`, `chat()->create()`, `models()->list()`, and `images()->create()`. Image generation retried once with `dall-e-3` when a selected newer image model failed. No chat retry loop was found.

`completionCreate()` had no repository caller. It was retained as an internal compatibility method. `chatCompletionCreate()` was called by jobs and prompt augmentation. `generateFeaturedImageForPost()` was called after job post creation.

## Prompt Entry Points

- Autonomous system prompt selected by the settings behavior engine.
- Augmented and manual system prompts stored in plugin settings.
- `AUGMENTATION_SYSTEM_MESSAGE`, used only to improve an administrator's prompt.
- Job source packing in `JobsExecutionController::buildMessages()`, combining instructions and selected library content.
- Featured-image prompt assembled from image style configuration and generated article content/excerpt.

The migration boundary is below all of these entry points; their wording and packing behavior are preserved.

## Settings Inventory

Pre-migration provider-specific settings were `exmoau_openai_api_key` and `exmoau_openai_weight_key`. Other AI settings were behavior mode, autonomous/augmented/manual model and prompt fields, GPT debug mode, image-generation enabled state, image model, style prompt, dimensions, and legacy width/height values.

The key was read during controller bootstrap and job execution. It was stored as a WordPress option and sanitized in `SettingsController`. Model fields used OpenAI-specific discovery/fallback assumptions. The image model UI used a fixed allowlist.

## Responses, Errors, Retries, and Timeouts

- Chat callers expected `choices[0]->message->content`; job article/SEO parsing occurred after that extraction.
- Augmentation decoded the same content and persisted either the improved prompt or the original fallback.
- Image parsing accepted base64 and remote URL payloads before media persistence.
- Chat errors caught OpenAI/transport exceptions and `Throwable`, capturing HTTP/request diagnostics when available.
- Image generation had one provider/model-specific retry; chat generation had no retry.
- The old chat client did not set an explicit plugin timeout. Remote image download used a 30-second WordPress request timeout. The scheduler's short loopback timeout was unrelated to AI transport.

## Background, AJAX, and REST

AI generation runs synchronously inside the scheduled job worker and during augmented-prompt processing on settings save. Jobs AJAX endpoints update mixture/directive UI state but do not independently generate AI output. Library AJAX endpoints and the core pulse endpoint do not call AI. No AI REST endpoint or `register_rest_route()` AI flow was found.

## Dependencies

Composer directly required `openai-php/client`, which pulled Guzzle, PSR HTTP packages, Symfony HTTP packages, and related discovery/transport dependencies. These packages existed solely to support the direct provider client.

## Risks Identified

- Changing the response shape would break article and metadata parsing.
- Rewriting prompts or source packing would change output quality and violate migration scope.
- Treating an installed but unconfigured adapter as connected would defer failure until generation.
- A saved provider/model may become invalid when adapters or capabilities change.
- Leaking raw provider exceptions into wp-admin could disclose operational details.
- Migrating the old key would duplicate credentials and undermine WordPress Connectors ownership.
- Image output must continue to support inline and remote files without provider-specific fields.

## Compatibility Decision

The plugin now requires WordPress 7.0. A pre-7.0 bridge would need to recreate the AI Client, provider registry, capability/model discovery, credential storage, request transport, and error contract. That would retain two architectures, double provider/version test coverage, and reintroduce the credential ownership this migration removes. The complexity and ongoing maintenance cost are not justified, so no legacy bridge is implemented.
