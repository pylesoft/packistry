# Data Models

## Models

| Model | Responsibility | Key Fields | Relationships |
| --- | --- | --- | --- |
| `ComposerUpstream` | Connect to one reusable Composer 2 source. | name, URL, auth type, encrypted credentials, enabled, last successful connection validation | Has many `Package` records across downstream repositories. |
| `Package` extension | Record upstream ownership, refresh cursor, and package-specific synchronization health. | nullable `composer_upstream_id`, checked/synchronized timestamps, last error, optional ETag/Last-Modified values | Existing package belongs to zero or one Composer upstream. |
| `Version` extension | Store synchronized metadata and immutable archive identity while preserving yanked versions for old locks. | existing metadata/checksum/archive fields plus nullable `upstream_removed_at` | Existing version belongs to a package. |

## Persistence Rules

- Reuse the existing unique `(repository_id, name)` package constraint as the ownership guard.
- Encrypt credentials using the same application-level encryption pattern already used for source tokens.
- Do not add a separate cache table, version state machine, or cold-archive fields in V1.
- Exclude versions with `upstream_removed_at` from metadata responses while leaving their archive download route usable.

## Migration Notes

Add one global upstream table, nullable package ownership and synchronization fields, and one nullable version removal timestamp. Reuse existing version and archive storage.
