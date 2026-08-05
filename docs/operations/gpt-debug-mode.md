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

The plugin's AI debug checkbox bypasses provider traffic and returns deterministic content. Use it to validate the downstream article and metadata parsing path independently from provider connectivity.
