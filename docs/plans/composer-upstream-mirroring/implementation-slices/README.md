# Implementation Slices

## Suggested Order

| Step | Scope | Depends On | Validation |
| --- | --- | --- | --- |
| 1 | Composer upstream connection, encrypted Basic/Bearer auth, validation | Existing repository authorization | Connection/auth contract tests |
| 2 | Explicit package enrollment, metadata mapping, and queued archive import | Step 1 and existing import/storage pipeline | Composer fixture and batch tests |
| 3 | Packistry-only URL publication and immutable archive replay | Step 2 | End-to-end install and old-lock replay tests |
| 4 | Hourly one-server scheduler, unique queued refresh jobs, package-level manual refresh, and stale-on-error behavior | Step 2 | Scheduling, deduplication, refresh, and outage tests |
| 5 | UI, health visibility, logs, documentation | Steps 1-4 | Browser/API tests and install guide |

## Rollout Notes

Pilot with one paid repository such as Scramble Pro and one non-production downstream repository. Confirm fresh synchronization, install, credential rotation, upstream outage, and lock replay before enabling additional vendors.
