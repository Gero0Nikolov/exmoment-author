# Scheduler Lifecycle

## Scope

End-to-end lifecycle of scheduler registration, due-job selection, and execution.

## Lifecycle

1. Worker schedule is registered.
2. Core and scheduler guards ensure the event remains present.
3. Worker scans for due jobs.
4. Execution dispatch runs through jobs execution paths.
5. Repeating jobs advance next-run values.
6. Logs capture debug/error/result state.
