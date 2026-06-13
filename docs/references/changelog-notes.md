# Changelog Notes Reference

## Scope

Curated historical notes for notable behavioral changes.

## Suggested Entry Template

- Date
- Area/module
- Change summary
- Migration or operational note
- Validation evidence

Keep entries concise and link to related docs where possible.

## 2026-06-13

- Area/module: GPT, settings, Docker local runtime
- Change summary: Released `1.1.0` with GPT Image model support, allowlisted image-model settings, OpenAI client/runtime updates, and WordPress local compatibility fixes.
- Migration or operational note: GPT Image requests no longer send `response_format`; local image-generation verification should use the web runtime when uploads permissions differ from `wpcli`.
- Validation evidence: Real web-runtime featured image generation succeeded after the request payload fix and attachment assignment was confirmed on the generated post.
