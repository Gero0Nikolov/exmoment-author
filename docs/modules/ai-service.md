# AI Service

## Scope

`modules/ai/AiService.php` is the only ExMoment Author class allowed to call the WordPress AI Client.

## Responsibilities

- Verify WordPress AI Client availability.
- Discover registered providers and capability-compatible models dynamically.
- Distinguish installed, configured, unavailable, unsupported, and invalid-model states.
- Apply optional provider/model preferences to prompt builders.
- Generate text and images through official WordPress AI Client interfaces.
- Normalize successes, public errors, debug diagnostics, and timing.

Credentials and provider authentication remain owned by WordPress Connectors. Callers use `AiService` through the core module container and never construct provider requests.

## Public methods

- `isAvailable()` verifies the WordPress AI Client functions, support flag, and `AiClient` class.
- `discover($capability)` returns registered providers and models matching text or image generation, including configured state.
- `getStatus($providerId, $modelId, $capability)` resolves a stable connection state and eligible selection.
- `generateText($prompt, $options)` applies provider/model selection, system instruction, maximum tokens, and optional temperature.
- `generateImage($prompt, $options)` applies provider/model selection, a normalized aspect ratio, and the optional `jpeg`/`webp`/`png` format through WordPress core's `as_output_mime_type()` prompt-builder method, then requires a WordPress AI Client `File` DTO with an allowed image MIME.

## Selection contract

Only configured providers participate in generation. Explicit provider and model preferences must match the capability-filtered discovery result. Empty preferences select the first compatible model reported for an eligible configured provider. An invalid explicit preference stops generation; it is not silently replaced with an unrelated model.

Image-format support is part of model requirements. The prompt builder checks the requested MIME against provider/model metadata before generation. A model that does not advertise the requested MIME produces `unsupported_capability`; ExMoment Author does not add provider-specific request payloads or silently fall back. The current OpenAI adapter advertises `image/jpeg`, `image/webp`, and `image/png` for `gpt-image-*`, while DALL·E advertises only `image/png`.

## Failure contract

Generation returns a normalized array with `success`, a provider-neutral error code/message, and sanitized diagnostics. Connection errors distinguish unavailable client/provider, unconfigured provider, invalid model, and unsupported capability. Runtime classifications distinguish authentication, permission/billing, rate/quota, invalid request, timeout/outage, empty or invalid response, and unknown failure where applicable.

For successful images, the service returns the requested MIME and the AI Client `File` MIME as metadata for the persistence boundary. The caller still validates the actual returned bytes independently.

Diagnostics may retain safe identifiers, HTTP status, exception class, requested capability, and timing. Raw provider detail is sanitized and logged only with `WP_DEBUG`; credentials and raw payloads are never part of the public result.

See [AI Request Lifecycle](../architecture/ai-request-lifecycle.md) for the complete flow and terminology.
