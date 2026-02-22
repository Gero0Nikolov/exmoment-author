# Jobs Module

## Scope

Jobs module powers the `exmoau_job` lifecycle from metadata and scheduling to execution and notices.

## Components

- `modules/jobs/JobsController.php`
- `modules/jobs/JobsMetaController.php`
- `modules/jobs/JobsSchedulingController.php`
- `modules/jobs/JobsExecutionController.php`
- `modules/jobs/JobsPublicationValidator.php`
- `modules/jobs/JobsErrorController.php`
- `modules/jobs/JobsTimeHelper.php`

## Responsibilities

- Register/manage the Jobs post type.
- Persist schedule and execution metadata.
- Validate publication requirements.
- Execute generation runs and post-processing.
- Emit structured diagnostics and user-facing notices.
