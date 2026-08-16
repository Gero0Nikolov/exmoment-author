# GPT Module

## Scope

`modules/gpt/GptController.php` preserves the generation interface used by settings and jobs flows. It delegates every AI request to `AiService`.

## Responsibilities

- Preserve completion/chat response shapes used by existing workflows.
- Convert internal role/content entries into `UserMessage` or `ModelMessage` objects containing `MessagePart` objects.
- Resolve text and image model preferences from dynamic discovery.
- Coordinate structured logging for GPT bypass/error/debug paths.
- Save generated inline or remote image files to WordPress media.
- Append optional public-author context to article and image requests without exposing private user fields or requesting a specific likeness.

## Integration Points

- Called by job execution workflows.
- Used by settings flows for model handling and cache flush actions.
- Does not own credentials, endpoints, provider payloads, or provider-specific errors.
- Receives one composed article system instruction: mandatory output protocol, the resolved editorial prompt, and optional generated author context.
- Appends topic-first composition and subject-variation guidance after the existing 500-character article/style prompt. When author context is enabled, guarded author casting context follows that guidance.

## Text boundary

`chatCompletionCreate()` extracts the most recent internal system entry as the AI Client system instruction. It converts non-system source/history entries to official message DTOs before calling `AiService::generateText()`. A successful service result is adapted to the legacy `choices[0].message.content` shape consumed by job article/SEO parsing. A failure returns the public service message and stores normalized diagnostics for the caller.

## Image boundary

`generateFeaturedImageForPost()` resolves plugin image settings, derives a concise topic-first prompt, discourages generic or repetitively gendered stock imagery, optionally appends public-author casting context, and calls `AiService::generateImage()`. The configured image format crosses this service boundary as `jpeg`, `webp`, or `png`; provider-specific request details remain inside the WordPress AI Client and adapter. If the article benefits from a primary human subject, the author context uses the public display name as a guarded gender-presentation cue; ambiguous names fall back to a gender-neutral or person-free composition.

For inline and remote `File` DTOs, persistence validates the decoded/downloaded bytes with image inspection, permits only JPEG/WebP/PNG, enforces a 25 MiB limit, derives the filename extension and attachment MIME from the verified bytes, writes with restricted permissions, generates WordPress attachment metadata, and assigns the thumbnail. A mismatch between requested/reported and actual MIME is accepted only when the actual type is allowlisted; the actual type is used and the mismatch is logged safely. No post-generation format conversion occurs.

## Related docs

- [AI Request Lifecycle](../architecture/ai-request-lifecycle.md)
- [Prompt and Author Context Pipeline](../architecture/prompt-and-author-context.md)

## Key File

- `modules/gpt/GptController.php`
