# Decisions

These decisions define the implemented V1 scope.

| Date | Status | Decision | Reason | Alternatives |
| --- | --- | --- | --- | --- |
| 2026-08-25 | Confirmed | Name the new concern `ComposerUpstream`. | It is a Composer registry, not a VCS source. | Add another `SourceProvider`. |
| 2026-08-25 | Confirmed | Explicitly enroll each package once in V1. | Bounds storage, upstream requests, ownership, and abuse without manual artifacts. Ian confirmed this behavior. | Transparent discovery for every unknown name or vendor namespace. |
| 2026-08-25 | Confirmed | Mirror every version returned for an enrolled package. | Provides complete vendor history and predictable lock-file replay without constraint configuration. Ian confirmed this behavior. | Optional per-package version constraints. |
| 2026-08-25 | Confirmed | Show Composer repositories in the existing Sources UI while keeping a separate backend contract. | Matches the user's mental model and current navigation without forcing a Composer registry into VCS branch/tag/webhook interfaces. | New top-level navigation; extend the VCS client contract. |
| 2026-08-25 | Confirmed | Materialize metadata and archives during enrollment and refresh. | Reuses Packistry's import pipeline, preserves checksum-qualified immutable URLs, and removes request-time upstream dependencies. | Lazy archive proxy with cold-cache state and locking. |
| 2026-08-25 | Confirmed | Rewrite paid-package distributions to Packistry-only URLs. | Clients need only the Packistry token and upstream credentials remain private. | Keep upstream fallback URLs. |
| 2026-08-25 | Confirmed | Make HTTP Basic a first-class V1 authentication method. | Flux Pro explicitly uses email plus license key over HTTP Basic; Scramble Pro is also distributed through an authenticated private Composer repository. Ian selected both as the first upstreams. | Vendor-specific credential implementations. |
| 2026-08-25 | Confirmed | Expose None, HTTP Basic, and Bearer token as the complete V1 authentication menu. | They cover the initial paid repositories and standard Composer registry authentication while keeping the contract bounded. Ian confirmed this scope. | Basic-only V1; generic custom headers and client certificates. |
| 2026-08-25 | Confirmed | Use Flux Pro and Scramble Pro as the initial acceptance fixtures. | They are the immediate paid packages Ian wants to centralize. | Synthetic fixtures only. |
| 2026-08-25 | Confirmed | Treat Scramble Pro as HTTP Basic with account email and API token. | Ian supplied the private installation guide and confirmed its Composer `http-basic.satis.dedoc.co` configuration. | Token-only or vendor-specific authentication. |
| 2026-08-25 | Confirmed | Keep Packagist.org proxying out of V1. | Public-scale discovery and cache policy are separable from paid-package mirroring. | Universal proxy from the first release. |
| 2026-08-25 | Confirmed | Refresh enrolled packages hourly plus on demand from the package page, never during Composer requests. | Keeps reads deterministic, bounds upstream work, and limits normal release lag to one hour while allowing immediate operator refresh. Ian confirmed the hourly cadence. | Request-time proxying; shorter or daily polling; webhook integrations. |
| 2026-08-25 | Confirmed | Dispatch scheduled and manual refresh through the same existing queue path. | Keeps the scheduler and UI action thin and lets managed workers scale independently. | Duplicate synchronization implementations; download archives in the scheduler or web request. |
| 2026-08-25 | Confirmed | Defer public Packagist caching until after the paid-package mirror is operational. | Keeps V1 bounded and lets actual synchronization traffic inform a later cache/proxy design. | Build public caching as the immediate second phase. |
