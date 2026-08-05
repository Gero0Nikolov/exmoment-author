# GPT Debug Mode

## Goal

Provide a repeatable process for diagnosing AI Client integration failures and response anomalies.

## Procedure

1. Enable WordPress debug logging in non-production environments.
2. Reproduce the GPT action from settings/job context.
3. Capture plugin log entries associated with `ai.generation` and GPT compatibility methods.
4. Verify that the WordPress AI Client is available and the provider is configured in Connectors.
5. Verify the selected provider/model supports the requested capability.
6. Confirm normalized error handling is user-readable and provider detail appears only in debug logs.

Record the normalized `error_type`, source error code, HTTP status, exception class, provider, attempted model, requested capability, and timing when present. Treat authentication, permission/billing, rate/quota, invalid request, timeout/outage, and configuration/capability failures as distinct categories.

Do not copy raw provider payloads, authorization headers, credentials, tokens, or unsanitized exception data into admin notices or support reports. Public notices should use the provider-neutral service message. Sanitized provider detail is debug-only and is recorded only when `WP_DEBUG` is enabled.

The plugin's AI debug checkbox bypasses provider traffic and returns deterministic content. Use it to validate the downstream article and metadata parsing path independently from provider connectivity.

See [AI Request Lifecycle](../architecture/ai-request-lifecycle.md) for the exact failure contract.
