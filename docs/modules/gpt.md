# GPT Module

## Scope

`modules/gpt/GptController.php` preserves the generation interface used by settings and jobs flows. It delegates every AI request to `AiService`.

## Responsibilities

- Preserve completion/chat response shapes used by existing workflows.
- Convert legacy message arrays into WordPress AI Client message parts.
- Resolve text and image model preferences from dynamic discovery.
- Coordinate structured logging for GPT bypass/error/debug paths.
- Save generated inline or remote image files to WordPress media.

## Integration Points

- Called by job execution workflows.
- Used by settings flows for model handling and cache flush actions.
- Does not own credentials, endpoints, provider payloads, or provider-specific errors.

## Key File

- `modules/gpt/GptController.php`
