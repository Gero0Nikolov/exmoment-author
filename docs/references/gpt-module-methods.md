# GPT Module Methods Reference

Reference index for major `GptController` method families.

## Method Groups

- AI Service resolution and capability-filtered model discovery.
- Completion and chat-completion compatibility methods that delegate to `AiService`.
- Internal role/content conversion to `UserMessage`, `ModelMessage`, and `MessagePart` DTOs.
- Provider-neutral chat diagnostics and legacy response-shape adaptation.
- AI image prompt, `File` DTO, media persistence, and featured-image helpers.
- Weight/token helper behavior.
- Debug/error logging helpers.
- Cache and model-refresh utilities used by settings.

Use this document as a quick pointer; inspect source for exact signatures.

`GptController` no longer initializes a direct provider client, owns credentials/endpoints, applies an image-model allowlist, or performs provider-specific fallback requests.
