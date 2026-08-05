# AI Client Migration Implementation Report

## Completed Changes

- Added `AiService` as the sole WordPress AI Client integration point.
- Registered the service in the core autoload map and set the AI Client request timeout to 120 seconds through its official filter.
- Implemented dynamic provider/model discovery with text/image capability filtering.
- Added explicit states for unavailable client, unavailable provider, unconfigured provider, unsupported capability, and invalid model.
- Added automatic or optional manual provider/model selection without hardcoded runtime model identifiers.
- Routed text, chat, model discovery, and image generation through `AiService`.
- Preserved the legacy chat response shape consumed by jobs and prompt augmentation.
- Preserved prompt wording, job source packing, article/metadata parsing, SEO handling, validation, scheduling, publication, and media persistence.
- Replaced provider image payload parsing with the WordPress AI Client `File` DTO, supporting inline and remote files.
- Removed API-key reads, writes, registration, UI, client construction, endpoint configuration, provider payloads, provider-specific retry/error parsing, and automatic key migration.
- Added provider-aware status, native Connectors navigation, adapter suggestions, optional provider preference, token budget, debug controls, and dynamically discovered image models.
- Removed `openai-php/client` and its transitive HTTP stack from Composer/vendor.
- Raised the minimum supported WordPress version to 7.0 and updated public/project documentation.

## Security Result

ExMoment Author no longer reads or stores provider credentials. WordPress Connectors owns configuration and authentication. Inputs remain validated/sanitized, settings remain capability-protected, and views escape dynamic output. Provider exception detail is normalized for users and retained only in debug diagnostics.

## Verification

- All first-party PHP files passed syntax lint.
- Composer dependency removal completed and the generated autoloader was rebuilt.
- The plugin booted active under the local WordPress 7.0.2 Docker runtime.
- Core resolved `AiService`; discovery returned a stable disconnected state when no adapter was installed.
- A disconnected generation stopped before transport with a provider-neutral error.
- The invalid-model guard returned `invalid_model` for a configured-provider fixture.
- Debug chat returned the deterministic `# TEST` response in the legacy response shape, confirming the downstream contract.
- The settings page rendered without a fatal error and exposed the AI Client tab, connection status, provider/model controls, and `/wp-admin/options-connectors.php` navigation.

## Environment-Limited Coverage

The local test site had no configured provider credentials. Consequently, real provider switching, successful live article/image generation, provider-side timeout, and provider-side failure could not be executed without adding external credentials. Those cases remain explicit rollout tests in the migration plan; the unavailable, unconfigured, invalid-selection logic and debug-compatible downstream contract are implemented without inventing a provider response.
