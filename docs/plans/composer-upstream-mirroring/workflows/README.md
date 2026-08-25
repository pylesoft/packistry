# Workflows

## User Or System Flows

See [Setup And Runtime Flow](setup-and-runtime.md) for the complete UI and Composer sequence.

### Connect and enroll

1. An administrator creates a Composer upstream with URL and authentication.
2. Packistry validates `packages.json` without exposing credentials.
3. The administrator enters `dedoc/scramble-pro` and selects the upstream.
4. Packistry fetches and validates its Composer 2 metadata.
5. A batch downloads and imports the package's version archives through the existing pipeline.
6. Packistry publishes successfully synchronized versions using Packistry distribution URLs.

### Install

1. Composer requests package metadata from Packistry using the existing Packistry token.
2. Packistry returns only Packistry distribution URLs.
3. Packistry serves the synchronized archive normally; no vendor credential or request-time upstream call is required.

### Refresh

1. A scheduled or manual job fetches current metadata for each enrolled package.
2. New or changed versions are downloaded and imported by a queued batch.
3. Removed upstream versions receive an `upstream_removed_at` timestamp and stop appearing in fresh resolution, while their records and archives remain available to old lock files.

## State Transitions

Synchronization uses the existing package-import batch lifecycle. No new persistent state machine is introduced.

## Failure Modes

- Invalid credentials: reject connection validation or mark later refresh unhealthy.
- Metadata timeout/5xx: keep last valid metadata and retry on the next schedule.
- Unknown package: reject enrollment without creating an empty package.
- Archive checksum mismatch: discard the temporary download and leave the version unpublished.
- Disabled upstream: serve cached content only.
