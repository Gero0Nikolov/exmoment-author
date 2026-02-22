# GPT Module

## Scope

`modules/gpt/GptController.php` contains GPT integrations used by settings and jobs flows.

## Responsibilities

- Initialize OpenAI client from configured API credentials.
- Provide completion/chat methods for generation workflows.
- Resolve model selections and cache-related helpers.
- Coordinate structured logging for GPT bypass/error/debug paths.

## Integration Points

- Called by job execution workflows.
- Used by settings flows for model handling and cache flush actions.

## Key File

- `modules/gpt/GptController.php`
