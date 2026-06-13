# GPT Module

## Scope

`modules/gpt/GptController.php` contains GPT integrations used by settings and jobs flows.

## Responsibilities

- Initialize OpenAI client from configured API credentials.
- Provide completion/chat methods for generation workflows.
- Resolve text and image model selections, including the allowlisted GPT Image registry.
- Coordinate structured logging for GPT bypass/error/debug paths.
- Generate featured images through the OpenAI Images API with a guarded legacy fallback to `dall-e-3` when a selected GPT image model is unavailable.
- Build image-generation requests without the deprecated `response_format` parameter and normalize either base64 or URL image payloads before saving them to WordPress media.

## Integration Points

- Called by job execution workflows.
- Used by settings flows for model handling and cache flush actions.

## Key File

- `modules/gpt/GptController.php`
