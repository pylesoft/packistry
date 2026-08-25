# API

## Routes Or Endpoints

| Method | Path | Request | Response | Authorization |
| --- | --- | --- | --- | --- |
| POST | `/api/composer-upstreams` | connection and auth fields | upstream resource | Admin/source management permission |
| POST | `/api/composer-upstreams/{upstream}/packages` | target repository and package name | package resource or accepted batch | Admin/package management permission |
| POST | `/api/composer-upstreams/{upstream}/refresh` | optional `package_id` | `202` with accepted batch IDs and any skipped package IDs | Admin/package management permission |
| PATCH | `/api/composer-upstreams/{upstream}` | connection, auth, enabled | upstream resource | Admin/source management permission |

## Payloads

## Error Responses

Use the existing validation-error shape. Do not relay raw upstream bodies when they may contain credentials or vendor-sensitive details.

The upstream-wide refresh endpoint is intentionally partial when a package already has an active refresh lock. It returns `accepted`, `batch_ids`, `skipped_package_ids`, and `skipped_count`; scheduled duplicate dispatches remain silent skips. The package-level manual refresh and enrollment endpoints return `409` when their package is already synchronizing.
