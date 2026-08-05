# Author Context and Job System Prompt Implementation

## Outcome

ExMoment Author now supports an optional author-name context and a per-job editorial system prompt override across Instant, Single Scheduled, and Repeating job execution.

## Resolution Rules

1. Resolve the effective directive post author from `exmoau_setup_directive_post_author`.
2. Use that same user ID for the generated post and, when enabled, AI author context.
3. Read `exmoau_job_custom_system_prompt`.
4. Use a non-empty valid job prompt as the editorial instruction; otherwise use the global AI Setup prompt.
5. Compose one provider-neutral system instruction containing the mandatory output/SEO contract, the effective editorial instruction, and optional generated author context.
6. Keep source documents as user messages.

This ordering prevents a provider adapter from discarding an earlier system message and prevents job overrides from removing the required response protocol.

## Privacy and Validation

- Author context is disabled by default.
- Only the public WordPress `display_name` is resolved.
- Email, login, role, capability, and other user fields are not included.
- Custom prompts preserve line breaks and are limited to 10,000 characters.
- Invalid prompt writes preserve the previous valid value and surface an admin notice.
- Debug logging records IDs, booleans, source, prompt length, and a SHA-256 hash rather than full prompt or author text.

## Verification

The implementation was checked with resolver/composition tests, settings sanitizer tests, PHP syntax checks, and browser coverage for settings persistence, job-type switching, prompt persistence, and oversized-value rejection.

Live generation covered author context off/on, global/custom editorial prompts, and Instant/Single Scheduled/Repeating entry points. Each successful article retained its configured WordPress author and generated non-empty content plus Yoast title, description, and focus keyphrase metadata. Image generation was verified both as a disabled early return and as an automatic-model request that attached a WordPress media item.
