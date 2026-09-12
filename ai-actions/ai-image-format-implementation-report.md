# AI Image Format Implementation Report

Date: 2026-08-16

## Runtime UI correction

The original version of this report incorrectly claimed the control was correctly placed and Browser-verified under **AI Setup**. The control existed, but it was rendered in the separate behavior-oriented `ai-setup.php` panel. The provided settings URL defaults to the **AI Client** panel, which owns AI featured images, Image style prompt, Image dimensions, and Image model, so the field was absent from the real featured-image configuration view. That earlier Browser claim did not validate the default URL/panel described by the user and was therefore incomplete.

The runtime follow-up moved the single control to `ai-client.php`, immediately after Image dimensions. Host/container source hashes matched, WordPress loaded the bind-mounted repository, no duplicate plugin copy existed, and no Docker/PHP restart was required. See `ai-actions/ai-image-format-runtime-ui-fix.md` for the evidence and validation record.

## Outcome

ExMoment Author now exposes **AI Image Format** in **Settings → ExMoment Author → AI Client**, alongside the featured-image controls. The option key is `exmoau_ai_image_format`; allowed values are `jpeg`, `webp`, and `png`; the default is `webp`.

WebP preserves the observable legacy behavior: the former persistence path wrote returned bytes to a temporary `.png`, then used the WordPress image editor to save a WebP whenever that editor supported WebP. The provider was not being asked for WebP.

The new path requests the selected format directly from the WordPress AI Client and does not convert the returned image.

## Installed contract audited

The local integration environment was running WordPress 7.0.4 and AI Provider for OpenAI 1.0.3. The configured image model was `gpt-image-1-mini`.

Primary-source evidence:

- WordPress core exposes the snake-case facade method `as_output_mime_type(string $mimeType)` on `WP_AI_Client_Prompt_Builder`: <https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php>
- PHP AI Client 1.4.0 implements `PromptBuilder::asOutputMimeType()` by setting `ModelConfig::outputMimeType`: <https://github.com/WordPress/php-ai-client/blob/1.4.0/src/Builders/PromptBuilder.php>
- The OpenAI-compatible image implementation maps an `image/*` MIME to the provider `output_format` parameter and uses that requested format as the expected `File` MIME: <https://github.com/WordPress/php-ai-client/blob/1.4.0/src/Providers/OpenAiCompatibleImplementation/AbstractOpenAiCompatibleImageGenerationModel.php>
- AI Provider for OpenAI 1.0.3 advertises `image/png`, `image/jpeg`, and `image/webp` for `gpt-image-*`; DALL·E advertises only `image/png`: <https://github.com/WordPress/ai-provider-for-openai/blob/1.0.3/src/Metadata/OpenAiModelMetadataDirectory.php>
- The OpenAI adapter passes `output_format` only for `gpt-image-*` and removes it for DALL·E: <https://github.com/WordPress/ai-provider-for-openai/blob/1.0.3/src/Models/OpenAiImageGenerationModel.php>

The AI Client includes configured MIME in `ModelRequirements`. `is_supported_for_image_generation()` therefore returns false when the selected model metadata does not allow the requested MIME. ExMoment Author normalizes that result to `unsupported_capability`. If adapter metadata is stale or the upstream provider rejects an otherwise advertised value, the existing WordPress/provider error is normalized through the standard invalid-request/provider failure path. There is no silent format fallback.

## Runtime path

```text
exmoau_ai_image_format
    → SettingsController::getAiImageFormat()
    → GptController::getImageGenerationSettings()
    → AiService::generateImage(format)
    → WP_AI_Client_Prompt_Builder::as_output_mime_type(image/*)
    → provider/model capability check
    → provider adapter output_format request
```

## Returned-file handling

The AI Client `File` MIME is retained as reported metadata but is not trusted as proof of the bytes. Inline base64 and remote downloads converge on the same validation path:

- reject empty or undecodable data;
- reject payloads above 25 MiB;
- inspect image bytes with `getimagesizefromstring()`;
- allow only `image/jpeg`, `image/webp`, and `image/png`;
- derive `.jpg`, `.webp`, or `.png` from the verified MIME;
- write only under the WordPress uploads path and enforce file mode `0644` through the WordPress filesystem abstraction;
- recheck the written path with `wp_check_filetype_and_ext()`;
- create attachment metadata and assign the featured image.

If requested/reported MIME differs from the verified MIME but the actual MIME is allowed, the image is accepted to preserve the existing featured-image success behavior. The verified MIME and matching extension are authoritative, and a sanitized debug diagnostic records the mismatch. No file is mislabeled and no conversion occurs.

## Provider and performance notes

WebP remains the default for website file-size efficiency and backward compatibility. JPEG is available for workflows that prioritize broad social/API compatibility. PNG is available for lossless output. Actual size remains provider-output dependent; this feature does not generate duplicate images or add a conversion pipeline.

## Validation scope

Focused regression coverage verifies setting default/allowlist behavior, invalid-value preservation, format-to-MIME request mapping, the value reaching `as_output_mime_type()`, byte-level MIME detection, MIME mismatch behavior, JPEG/WebP/PNG extension mapping, attachment MIME, and featured-image assignment.

No paid provider-backed image generations are part of this implementation. The settings UI is validated separately in the signed-in local WordPress admin.
