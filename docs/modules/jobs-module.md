# Jobs Module

## Scope

Jobs module powers the `exmoau_job` lifecycle from metadata and scheduling to execution and notices.

## Components

- `modules/jobs/JobsController.php`
- `modules/jobs/JobsMetaController.php`
- `modules/jobs/JobsSchedulingController.php`
- `modules/jobs/JobsExecutionController.php`
- `modules/jobs/JobsAiContextResolver.php`
- `modules/jobs/JobsPublicationValidator.php`
- `modules/jobs/JobsErrorController.php`
- `modules/jobs/JobsTimeHelper.php`

## Responsibilities

- Register/manage the Jobs post type.
- Persist schedule and execution metadata.
- Validate publication requirements.
- Execute generation runs and post-processing.
- Emit structured diagnostics and user-facing notices.
- Resolve one effective editorial prompt for instant, single-scheduled, and repeating-scheduled execution.
- Resolve optional public author context from the same effective author ID used when creating the generated post.

## Per-Job System Prompt

The **Custom system prompt** meta box stores `exmoau_job_custom_system_prompt`. Blank values inherit the global AI Setup prompt. Non-empty values override only the editorial instructions; the required article/SEO response contract is always retained.

The field preserves line breaks, rejects non-string input and values over 10,000 characters, and uses the existing job nonce, capability, autosave, revision, and validation-notice flow. It remains present when switching between Instant, Single Scheduled, and Repeating job types.
