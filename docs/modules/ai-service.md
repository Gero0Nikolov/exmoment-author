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
