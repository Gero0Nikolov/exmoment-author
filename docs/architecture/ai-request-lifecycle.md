# AI Request Lifecycle

This document is the canonical description of ExMoment Author's current AI architecture. The migration audit and implementation reports remain historical evidence; this page describes the maintained runtime.

## Terms and ownership

- **WordPress AI Client** is the WordPress API that builds prompts, selects models, checks capabilities, performs generation, and returns WordPress AI DTOs or `WP_Error` values.
- A **provider adapter** is an installed integration that registers one provider and its models with the WordPress AI Client. WordPress Connectors owns its configuration and credentials.
- A **provider** is the configured AI service exposed by an adapter. Installing an adapter does not mean its provider is configured.
- A **model** is a provider model discovered from the WordPress AI Client registry. Model identifiers and availability are provider-dependent and may change.
- A **capability** is a required operation, currently text generation or image generation. A model discovered for one capability is not assumed to support the other.
- ExMoment Author's **AI Service** is `ExMomentAuthor\Modules\Ai\AiService`, the plugin's only WordPress AI Client boundary.
- Feature controllers and prompt/context resolvers prepare editorial intent and content. They do not own credentials, provider endpoints, or provider-specific request payloads.

ExMoment Author does not claim support for every provider or model. Runtime availability is limited to adapters installed and configured in the current WordPress installation and to models the registry reports as compatible with the requested capability.

## Text generation flow

```text
JobsExecutionController or SettingsController
→ feature prompt and context construction
→ GptController compatibility boundary
→ UserMessage / ModelMessage / MessagePart DTO conversion
→ AiService::generateText()
→ WordPress AI Client prompt builder
→ configured provider adapter and compatible model
→ normalized text result or provider-neutral failure
```

For job execution, `JobsExecutionController::executeJob()` resolves the effective editorial prompt and author context, then `buildMessages()` creates one internal system message followed by one user message per valid source. `GptController::chatCompletionCreate()` separates that system instruction from the source history and converts every non-system entry to the official WordPress AI Client DTO boundary:

- user entries become `WordPress\AiClient\Messages\DTO\UserMessage` objects;
- assistant history becomes `WordPress\AiClient\Messages\DTO\ModelMessage` objects;
- each message contains a `WordPress\AiClient\Messages\DTO\MessagePart`.

Feature code may use the plugin's internal `role`/`content` arrays while composing and sanitizing prompts. A list passed to `wp_ai_client_prompt()` must contain the DTO objects required by the WordPress AI Client; a list of associative message arrays is not a valid multi-message prompt.

`AiService::generateText()` applies the validated provider/model selection, system instruction, token limit, and optional temperature. It checks text-generation support and returns a normalized array. `GptController` adapts a successful result back to the legacy `choices[0].message.content` object shape used by article parsing and settings prompt augmentation.

## Featured-image flow

```text
JobsExecutionController::maybeGenerateFeaturedImage()
→ GptController::generateFeaturedImageForPost()
→ GptController::buildImagePromptForPost()
→ AiService::generateImage()
→ WordPress AI Client prompt builder
→ configured provider adapter and image-capable model
→ File DTO
→ WordPress uploads, media attachment, and post thumbnail
```

The image prompt combines the configured style prompt with a title/content-derived excerpt. That base prompt is limited to 500 characters before optional author context is appended. `AiService` maps the selected dimensions to a provider-neutral aspect ratio, checks image-generation support, and requires a `WordPress\AiClient\Files\DTO\File`. `GptController` supports both inline and remote file DTOs when persisting media.

Image generation stops safely when disabled, in debug mode, when a thumbnail already exists, when no post/prompt is available, or when the provider/model cannot satisfy image generation. Image failure does not invent provider-specific fallback models.

## Discovery and selection

`AiService::discover()` asks `AiClient::defaultRegistry()` for models matching a `ModelRequirements` instance containing either `CapabilityEnum::textGeneration()` or `CapabilityEnum::imageGeneration()`. Results retain provider ID/name, configured state, and compatible models. Registered providers with no matching models remain visible with an empty model list so the UI can distinguish installation from capability support.

`AiService::resolveSelection()` considers only configured providers:

- an explicit provider restricts selection to that provider;
- an explicit model must match a discovered compatible model under the eligible provider;
- with no model preference, the first compatible model reported for an eligible configured provider is selected;
- empty saved provider/model values therefore mean automatic selection within the discovered compatible set.

The resulting connection states are:

- `client_unavailable`: the WordPress AI Client API is unavailable;
- `provider_unavailable`: no compatible provider exists, or the requested provider is absent;
- `provider_not_configured`: a matching provider exists but none is configured;
- `invalid_model`: an explicit model is not available in the eligible compatible set;
- `unsupported_capability`: configuration exists but no model can satisfy the requested capability;
- `connected`: a configured provider and compatible model were resolved.

Provider catalogs, quotas, permissions, and availability can change independently of the plugin. A saved explicit preference that becomes invalid stops with a normalized error; ExMoment Author does not silently substitute an unrelated provider or model.

## Errors and diagnostics

`AiService` returns provider-neutral public messages and a sanitized diagnostic payload. WordPress AI Client errors are classified into stable categories including authentication, permission or billing, rate or quota, invalid request, timeout or outage, and unknown failure. Configuration and capability states use the connection codes above.

Where available, diagnostics retain:

- operation and requested capability;
- sanitized error type and source error code;
- selected provider and attempted model;
- HTTP status;
- sanitized exception class;
- elapsed time.

The provider message is sanitized and retained only when `WP_DEBUG` is enabled. `GptController` further normalizes chat diagnostics for job logging. Administrators receive actionable provider-neutral notices; logs may contain the sanitized diagnostic context. Neither surface may contain credentials, access tokens, raw secrets, authorization headers, or unsanitized provider payloads.

## Related documentation

- [Prompt and Author Context Pipeline](prompt-and-author-context.md)
- [AI Service Module](../modules/ai-service.md)
- [GPT Module](../modules/gpt.md)
- [AI Setup](../settings/ai-setup.md)
- [GPT Debug Mode](../operations/gpt-debug-mode.md)

