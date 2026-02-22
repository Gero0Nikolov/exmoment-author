# Scheduler Module

## Scope

Scheduler behavior is implemented by `modules/jobs/JobsSchedulerWorker.php` and supporting jobs controllers.

## Responsibilities

- Register custom cron schedule and worker hook.
- Ensure recurring event remains scheduled.
- Select due jobs and dispatch execution flows.
- Track throttles/locks and emit structured scheduler logs.

## Key Hook

- `exmoau_minutely_worker`
