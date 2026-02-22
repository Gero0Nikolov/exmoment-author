# Exo Author Cron Testing

## Goal

Validate scheduler execution in local and staging environments.

## Checklist

1. Confirm `WP_CRON` behavior for environment.
2. Ensure `exmoau_minutely_worker` is scheduled.
3. Create a known test job and capture expected trigger window.
4. Observe logs for schedule selection, execution, and result status.
5. Verify generated content and post state transitions.

## Evidence to Collect

- Scheduler log entries with job IDs.
- Job post meta updates.
- Admin notices (if any) during manual run paths.
