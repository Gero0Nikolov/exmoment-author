# GptController Compatibility Layer

`GptController` preserves the method and response contracts consumed by ExMoment Author's existing jobs and settings workflows. It does not own a provider client, endpoint, or credential.

All text and image generation delegates to `ExMomentAuthor\Modules\Ai\AiService`, the repository's sole WordPress AI Client boundary. Provider and model metadata is discovered dynamically. `chatCompletionCreate()` converts the existing role/content messages to WordPress AI Client message parts and returns the legacy object shape expected by downstream article and metadata parsing.

Primary methods:

- `completionCreate()` generates plain text through the service.
- `chatCompletionCreate()` generates conversational text while preserving the established response shape.
- `getAllGptModels()` returns dynamically discovered compatible text models.
- `generateFeaturedImageForPost()` requests an image and persists the returned file through WordPress media APIs.
- `getLastChatCompletionDiagnostics()` exposes normalized diagnostics for internal logging and settings notices.

Provider credentials must be configured in the native WordPress Connectors screen. They must never be passed to or stored by this controller.
