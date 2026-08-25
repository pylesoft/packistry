# Domain

## Vocabulary

| Term | Meaning |
| --- | --- |
| Downstream repository | The existing Packistry Composer endpoint used by developers and CI. |
| Composer upstream | An external Composer 2 repository Packistry accesses using server-side credentials. |
| Enrolled package | A package name explicitly assigned to one upstream and synchronized into Packistry. |
| Metadata refresh | Re-fetching versions for an enrolled package. |
| Cached archive | An immutable distribution stored by Packistry and installable without the upstream. |

## Entities

- Existing `Source`: VCS or Composer connection, with Composer-specific encrypted authentication, enabled state, and last successful validation time when applicable.
- Existing `Package`: downstream package plus optional upstream ownership and synchronization health.
- Existing `Version`: normalized metadata backed by a synchronized immutable archive.

## Invariants

- One package name has one owner inside a Packistry repository.
- Upstream credentials never cross the downstream Composer boundary.
- A cached archive is immutable and remains addressable by its checksum-qualified URL.
- Refresh failure never deletes the last valid metadata or a cached archive.
- A package snapshot becomes visible only after every changed archive has been safely downloaded and validated.

## Boundaries

`Source` owns package origin and credentials. Its provider selects either the existing VCS client capability or the Composer-registry client capability; neither client implements operations the provider cannot support. Existing repository authorization remains responsible for downstream access.
