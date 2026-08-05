# Instant Model Rejection Investigation

## Outcome

The rejection was caused by malformed multi-message prompt construction in ExMoment Author. It was not caused by the OpenAI connector, credentials, account access, the selected model, the output-token budget, or featured-image generation.

`Instant` is the ExMoment Author job type (`single_instant`), not an AI model label. The affected settings selected the OpenAI provider and the `gpt-5-nano` model.

## Reproduction

The failure was reproduced on the local WordPress 7.0.2 site with these effective values:

- Job: `Single > Instant #2` (job ID 32)
- Job type: `single_instant`
- Provider label: OpenAI
- Provider identifier: `openai`
- Model identifier: `gpt-5-nano`
- Requested capability: text generation
- Output-token budget: `2aq` (16,000 tokens)
- Featured-image generation: enabled
- AI debug mode: disabled

The request failed during the initial article text-generation call. Metadata parsing, post creation, and featured-image generation were not reached.

The same legacy message payload failed before provider transport with 16,000, 500, 120, 60, and 2 output tokens. A string prompt succeeded with both automatic model selection and explicit `gpt-5-nano`, proving that provider authentication, model access, and the token budget were not the cause.

## Original Sanitized Error

Calling the WordPress AI Client generation method directly exposed the error that ExMoment Author's support check had hidden:

```text
WordPress error code: prompt_invalid_argument
HTTP status: 400
Exception class: WordPress\AiClient\Common\Exception\InvalidArgumentException
Message: Array items must be strings, MessagePart instances, or MessagePartArrayShape.
```

No provider error code or request ID existed because the prompt was rejected while the WordPress AI Client parsed it, before an OpenAI request was sent.

## Confirmed Root Cause

`GptController::chatCompletionCreate()` converted each legacy message into an associative `role`/`parts` array, then passed a list of those arrays to `wp_ai_client_prompt()`.

The installed WordPress AI Client accepts an individual message array shape, but its multi-message prompt contract is `list<Message>`. Its `PromptBuilder::isMessagesList()` requires every list item to be a `WordPress\AiClient\Messages\DTO\Message` object. Because ExMoment Author supplied a list of associative arrays, the builder treated the outer list as message parts and raised `prompt_invalid_argument`.

A control request constructed with `UserMessage` and `MessagePart` DTOs succeeded with `gpt-5-nano` before the plugin was changed, proving the corrective shape.

## Exact Fix

- Convert legacy user and assistant history into official `UserMessage` and `ModelMessage` DTOs containing `MessagePart` objects.
- Preserve the existing system instruction, provider selection, model selection, token budget, response shape, and downstream article parsing.
- When a WordPress AI Client support check is false, call its generation method to retrieve any stored `WP_Error` instead of discarding the original builder error.
- Normalize failures into actionable categories: authentication, permission/billing, rate/quota, unsupported capability, invalid request, timeout/outage, and unknown error.
- Retain sanitized operation, capability, provider, attempted model, source error code, HTTP status, exception class, and timing diagnostics. The sanitized provider message remains debug-only.
- Show the normalized actionable service message in the job result instead of replacing every service failure with a configuration warning.

## Modified Files

- `modules/gpt/GptController.php`
- `modules/ai/AiService.php`
- `modules/jobs/JobsExecutionController.php`
- `docs/references/instant-model-rejection-investigation.md`
- `docs/references/index.md`

## Targeted Validation

| Check | Result |
| --- | --- |
| Original legacy array payload before the fix | Reproduced `prompt_invalid_argument` / HTTP 400 |
| Official DTO control before the fix | Live success with `gpt-5-nano` |
| Patched current settings, 16,000 tokens | Live success; returned `OK` with `gpt-5-nano` |
| Patched 500-token request with featured images disabled | Live success; returned `OK` with `gpt-5-nano` |
| Automatic model selection | Live success; selected `gpt-5.6-luna` and returned `OK` |
| Alternate discovered text model | Live success; `gpt-4.1-mini` returned `OK` |
| Malformed-request diagnostics after the fix | `invalid_request`, source `prompt_invalid_argument`, HTTP 400, sanitized exception class retained |
| Disconnected-provider guard | Stopped with `provider_unavailable` and no selected provider |
| Debug mode | Provider bypassed; legacy `debug-chat-completion` returned `# TEST` / `TEST` |
| PHP syntax checks | Passed for all modified PHP files |
| `git diff --check` | Passed |

All temporary option changes used by validation were restored. The successful live checks exercised the actual plugin controller and configured provider without creating or modifying article posts.

## Remaining Rollout Risks

- A full Instant article job was intentionally not run because it would consume a source pack and create or update content. The corrected provider-backed controller path—the point of the original rejection—was exercised successfully.
- Provider quotas, permissions, and model catalogs can change independently. The new normalized categories and diagnostics make those future failures distinguishable without exposing connector secrets.
- Featured-image provider generation was outside this text-request failure and was not exercised.

## Status

PASS
