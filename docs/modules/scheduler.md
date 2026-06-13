# Scheduler Module

## Scope

Scheduler behavior is implemented by `modules/jobs/JobsSchedulerWorker.php` and supporting jobs controllers.

## Responsibilities

- Register custom cron schedule and worker hook.
- Ensure recurring event remains scheduled.
- Select due jobs and dispatch execution flows.
- Track throttles/locks and emit structured scheduler logs.
- Skip bootstrap cron-spawn attempts during WP-CLI execution to avoid redirect
  warnings and keep command-line verification deterministic.
- Leave the local Docker alternate-cron shim to browser requests so WP-CLI
  commands do not inherit redirect-based cron spawning.

## Key Hook

- `exmoau_minutely_worker`
