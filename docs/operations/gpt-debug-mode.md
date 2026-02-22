# GPT Debug Mode

## Goal

Provide a repeatable process for diagnosing GPT integration failures and response anomalies.

## Procedure

1. Enable WordPress debug logging in non-production environments.
2. Reproduce the GPT action from settings/job context.
3. Capture plugin log entries associated with GPT methods.
4. Verify configuration values (API key/model) and request payload constraints.
5. Confirm fallback/error handling paths are clean and user-readable.
