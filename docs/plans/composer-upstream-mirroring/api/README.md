# API

## Routes Or Endpoints

| Method | Path | Request | Response | Authorization |
| --- | --- | --- | --- | --- |
| POST | `/api/sources` | provider plus connection and provider-specific auth fields | source resource | Source create permission |
| PATCH | `/api/sources/{source}` | connection, auth, enabled | source resource | Source update permission |
| DELETE | `/api/sources/{source}` | none | deleted source resource | Source delete permission |
| POST | `/api/packages` | repository, source, and either VCS projects or one Composer package name | package resource collection | Package create permission |
| POST | `/api/packages/{package}/rebuild` | none | package resource | Package update permission |

## Payloads

## Error Responses

Use the existing validation-error shape. Do not relay raw upstream bodies when they may contain credentials or vendor-sensitive details.

Composer credentials are write-only. Source responses expose only `has_credentials`, authentication type, enabled state, and validation time. Composer package enrollment returns `409` when that package is already synchronizing; scheduled duplicate dispatches remain silent skips.
