# Contracts

## Public Interfaces

| Name | Input | Output | Errors | Notes |
| --- | --- | --- | --- | --- |
| Validate upstream | URL and auth strategy | Valid Composer 2 capability summary | auth, TLS, protocol | Server-side only. |
| Enroll package | upstream and package name | synchronized package/version metadata and archives | unknown package, conflict, invalid metadata/archive | Admin operation dispatched as a batch. |
| Refresh package | enrolled package | updated metadata | auth, upstream unavailable, invalid metadata | Keeps last valid snapshot on failure. |

## Authentication Contract

| Strategy | Fields | Outbound request | V1 status |
| --- | --- | --- | --- |
| None | None | No authorization header. | Supported. |
| HTTP Basic | Username and password | Standard HTTP Basic authorization for the configured origin. | Required by the initial providers. |
| Bearer | Token | `Authorization: Bearer <token>` for the configured origin. | Recommended. |

Credentials are encrypted at rest and write-only through the application API. Editing an upstream without submitting a replacement secret preserves the existing credential. Authenticated upstreams require HTTPS. Validation and synchronization may use credentials only for the exact configured origin. Redirects to another origin must not receive the upstream authorization header; archive downloads follow the destination's own authentication requirements instead.

Every outbound metadata, archive, and redirect URL must use HTTP(S), omit embedded credentials, resolve only to public addresses, and pass the same safety guard immediately before its request. The validated address is pinned through cURL while the original hostname remains in the URL for host and TLS verification. Metadata requires identity encoding and is bounded to 16 MiB and 10,000 versions; each archive is bounded to 256 MiB. Terminal connection failures are sanitized before entering application or queue logs. Composer 2 minified responses are expanded before validation. Mirrored metadata excludes upstream `source`, `dist`, and notification endpoints so clients cannot bypass Packistry.

V1 deliberately excludes arbitrary headers, credentials embedded in URLs, GitHub/GitLab OAuth, and client TLS certificates. These are added only when a licensed package provides a concrete requirement.

## Events And Messages

## Compatibility Notes

V1 targets Composer 2 `metadata-url` repositories. Existing Packistry repository URLs and authentication remain unchanged.
