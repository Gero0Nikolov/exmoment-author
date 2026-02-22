# Exo Author Schedule Sync Update Audit

## Goal

Audit whether saved schedule metadata is consistently synchronized with runtime scheduling state.

## Audit Steps

1. Edit schedule rows for a job.
2. Save the job and confirm meta persistence.
3. Confirm next-run calculation reflects updated rows.
4. Validate worker selection aligns with saved schedule.
5. Review logs for schedule row validation or sync anomalies.
