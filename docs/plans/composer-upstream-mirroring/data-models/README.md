# Data Models

## Models

| Model | Responsibility | Key Fields | Relationships |
| --- | --- | --- | --- |
| `Source` extension | Connect to either a VCS provider or Composer 2 repository. | provider, name, URL, Composer auth type, encrypted credentials, enabled, last successful connection validation | Has many `Package` records across downstream repositories. |
| `Package` extension | Record source ownership, refresh cursor, and package-specific synchronization health. | existing nullable `source_id`, checked/synchronized timestamps, last error, optional ETag/Last-Modified values | Existing package belongs to zero or one source. |
| `Version` extension | Store synchronized metadata and immutable archive identity while preserving yanked versions for old locks. | existing metadata/checksum/archive fields plus nullable `upstream_removed_at` | Existing version belongs to a package. |

## Persistence Rules

- Reuse the existing unique `(repository_id, name)` package constraint as the ownership guard.
- Encrypt credentials using the same application-level encryption pattern already used for source tokens.
- Do not add a separate cache table, version state machine, or cold-archive fields in V1.
- Exclude versions with `upstream_removed_at` from metadata responses while leaving their archive download route usable.

## Migration Notes

The consolidation migration adds Composer connection fields to `sources`, changes source URLs to text, copies every deployed `composer_upstreams` row to a new source, and maps each package to the new `source_id`. It intentionally retains `composer_upstreams`, `packages.composer_upstream_id`, and `sources.legacy_composer_upstream_id` for deployment compatibility.

A later explicitly authorized cleanup migration may drop those legacy structures after production backfill and package synchronization have been verified. New Composer source writes are not dual-written to the legacy table, so rolling back application code after creating or editing a unified Composer source is not supported.
