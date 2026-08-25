# Product Requirements And System Design

## Summary

Add authenticated Composer upstream mirroring to the Pylesoft Packistry fork. An administrator connects a paid Composer repository and explicitly enrolls package names. Packistry synchronizes their metadata and archives, then publishes Packistry-owned distribution URLs.

This is a selective materialized mirror: package ownership is explicit, while version discovery, archive retrieval, storage, and refresh are automatic.

## Goals

- Let developers, CI, Laravel Cloud, and Forge use only their existing Packistry token.
- Keep paid-vendor credentials encrypted and exclusively server-side.
- Automatically discover versions and updates for enrolled packages.
- Preserve existing lock-file installations after an archive has been cached.
- Reuse Packistry's repository, package, version, token, queue, and archive-storage behavior.
- Support Flux Pro and Scramble Pro as the first production upstreams without embedding vendor-specific code in the synchronization pipeline.

## Non-Goals

- Manual ZIP publication as the primary workflow.
- A transparent or universal Packagist.org proxy in the first release.
- Proxying Git source checkouts or the GitHub API.
- Composer 1 support.
- A generic repository-manager framework or plugin system.
- Combining versions of one package from several upstreams.

## Existing Context

Packistry currently imports VCS projects through `SourceProvider` clients shaped around projects, branches, tags, and webhooks. It already serves Composer 2 metadata and Packistry-owned immutable archives. See [Context](context.md) and the underlying [research note](../../research/composer-upstream-mirroring.md).

## Proposed Design

### Product boundary

Packistry presents Composer upstreams in the existing **Sources** area, while the backend keeps them separate from the VCS-specific `SourceProvider` contract. A `ComposerUpstream` contains its base URL, authentication strategy, encrypted credentials, and enabled state. It can be reused when enrolling packages into one or more Packistry repositories. V1 needs no priority system because the administrator explicitly selects the owning upstream and target repository when enrolling a package.

V1 presents three authentication choices: **None**, **HTTP Basic**, and **Bearer token**. HTTP Basic is the required first-class path for the initial paid Laravel ecosystem: both Flux Pro and Scramble Pro use the purchaser's account email as the username and a license/API key as the password. The form keeps generic `Username` and `Password` labels with helper text explaining that common convention. Bearer remains a small standards-based option for future repositories; arbitrary headers, OAuth flows, and client certificates are deferred.

An administrator enrolls a package name such as `dedoc/scramble-pro` from one upstream. Enrollment fetches `/p2/dedoc/scramble-pro.json`, validates the response, and materializes the package and its version metadata in Packistry. The existing unique repository/package name prevents ambiguous ownership.

### Metadata and archives

- Metadata and every published version are fetched immediately when a package is enrolled.
- An hourly Laravel Scheduler task dispatches refresh jobs for enrolled packages; a **Refresh now** action on the package page dispatches the same job immediately.
- Composer metadata requests never perform upstream discovery or synchronization in V1.
- Composer clients always receive Packistry metadata; they never contact the paid upstream.
- Every upstream `dist.url` is replaced with a Packistry download URL.
- Composer 2 minified metadata is expanded before synchronization, and upstream `source` and notification endpoints are removed from mirrored versions.
- Archives are downloaded during enrollment or refresh, validated, and imported through Packistry's existing package/version/archive pipeline.
- New or changed versions are queued in the existing batch-processing model. Already synchronized versions are skipped.
- There is no cold-download state, request-time upstream call, persistent state machine, or new cache-locking subsystem in V1.

### Refresh scheduling

The scheduler only finds due packages and dispatches one unique refresh job per package. The queued payload contains only the package identifier and lock owner; the worker fetches metadata itself. It does not download archives in the scheduler or serialize vendor metadata into the queue. The scheduled command runs hourly with `onOneServer()` and overlap protection; managed queue workers perform network and archive work. Conditional `ETag` or `Last-Modified` requests are used when supported by the upstream.

The maximum normal discovery delay for a new vendor release is therefore about one hour. **Refresh now** is available on each mirrored package when an administrator needs a release immediately. The action shows the associated batch status and is disabled while that package is already synchronizing. The one-hour cadence is fixed in V1 and becomes configurable only if operational experience requires it.

### Failure behavior

- If metadata refresh fails, the last valid metadata remains available and the package shows its synchronization error. The upstream card records only its last successful connection validation.
- If an archive cannot be fetched during synchronization, that version is not published until a retry succeeds; previously mirrored versions remain usable.
- A refresh validates and downloads every changed archive before publishing the new package snapshot atomically.
- Authenticated upstreams require HTTPS. Every metadata, archive, and redirect destination is checked against private and reserved network ranges, then its validated address is pinned into the transport request while the original hostname remains authoritative for TLS.
- Metadata responses are limited to 16 MiB and 10,000 advertised versions. Archive downloads stream one at a time to unpublished storage paths and are rejected above 256 MiB, so workers do not accumulate package ZIPs in memory.
- Refresh jobs have a one-hour execution budget and a separate runtime overlap lock. Duplicate deliveries cannot run synchronization concurrently, and a terminal failure is retried by the next scheduled or manual refresh.
- Disabling an upstream stops refresh but does not delete packages or cached archives.
- Deletion remains a separate, explicit destructive operation.

### Why not a transparent universal proxy first?

Automatic requests for every unknown Composer package require repository-wide discovery, priority resolution, negative caching, abuse controls, and public-scale cache policies. Explicit enrollment plus eager synchronization delivers the single-token paid-package experience without those concerns. A later Packagist proxy can reuse the same upstream client and stored package format after the bounded implementation is proven.

## Document Map

- [Context](context.md)
- [Decisions](decisions.md)
- [Questions](questions.md)
- [Domain](domain/README.md)
- [Contracts](contracts/README.md)
- [Provider Profiles](providers/README.md)
- [API](api/README.md)
- [Data Models](data-models/README.md)
- [Workflows](workflows/README.md)
- [Setup And Runtime Flow](workflows/setup-and-runtime.md)
- [Implementation Slices](implementation-slices/README.md)

## Implementation Steps

See [Implementation Slices](implementation-slices/README.md). The proposed order is upstream connection, package enrollment with metadata/archive synchronization, scheduled refresh, then operational polish.

## Validation Strategy

- Contract tests against fixture Composer 2 repositories using Basic and Bearer authentication.
- End-to-end test proving a client configured only with a Packistry token can require and install an enrolled paid package.
- Tests for credential isolation, duplicate ownership, checksum mismatch, partial synchronization, stale metadata, disabled upstreams, and existing lock-file replay.
- Regression tests for existing VCS-backed and uploaded packages.

## Risks

- A vendor may forbid credential sharing or redistribution outside the licensed team; administrators remain responsible for license compliance.
- Packistry becomes the sole download endpoint when upstream URLs are removed from metadata.
- Enrollment and refresh depend on upstream availability, but installs do not after synchronization succeeds.
- Vendors may use authentication methods outside the initial Basic/Bearer scope.

## Questions

See [Questions](questions.md). The scoped V1 product decisions are resolved; public Packagist caching and automatic namespace discovery are deferred until the paid-package mirror has proven operational behavior.
