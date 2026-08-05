# GPT Module

## Scope

`modules/gpt/GptController.php` preserves the generation interface used by settings and jobs flows. It delegates every AI request to `AiService`.

## Responsibilities

- Preserve completion/chat response shapes used by existing workflows.
- Convert legacy message arrays into WordPress AI Client message parts.
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

## Key File

- `modules/gpt/GptController.php`
