# GPT Module

## Scope

`modules/gpt/GptController.php` preserves the generation interface used by settings and jobs flows. It delegates every AI request to `AiService`.

## Responsibilities

- Preserve completion/chat response shapes used by existing workflows.
- Convert internal role/content entries into `UserMessage` or `ModelMessage` objects containing `MessagePart` objects.
- Resolve text and image model preferences from dynamic discovery.
- Coordinate structured logging for GPT bypass/error/debug paths.
- Save generated inline or remote image files to WordPress media.
- Append optional public-author context to article and image requests without exposing private user fields.

## Integration Points

- Called by job execution workflows.
- Used by settings flows for model handling and cache flush actions.
- Does not own credentials, endpoints, provider payloads, or provider-specific errors.
- Receives one composed article system instruction: mandatory output protocol, the resolved editorial prompt, and optional generated author context.
- Keeps the original image prompt unchanged when author context is disabled. When enabled, author context is appended after the existing 500-character article/style prompt.

## Text boundary

`chatCompletionCreate()` extracts the most recent internal system entry as the AI Client system instruction. It converts non-system source/history entries to official message DTOs before calling `AiService::generateText()`. A successful service result is adapted to the legacy `choices[0].message.content` shape consumed by job article/SEO parsing. A failure returns the public service message and stores normalized diagnostics for the caller.

## Image boundary

`generateFeaturedImageForPost()` resolves plugin image settings, derives a concise prompt, optionally appends public-author context, and calls `AiService::generateImage()`. It accepts the returned inline or remote `File` DTO, writes a WordPress media attachment, and assigns the thumbnail. It does not build provider-specific payloads or choose provider-specific fallbacks.

## Related docs

- [AI Request Lifecycle](../architecture/ai-request-lifecycle.md)
- [Prompt and Author Context Pipeline](../architecture/prompt-and-author-context.md)

## Key File

- `modules/gpt/GptController.php`
