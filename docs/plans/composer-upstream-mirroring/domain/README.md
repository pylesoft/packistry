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

- `ComposerUpstream`: connection, encrypted authentication, enabled state, and health.
- Existing `Package`: downstream package plus optional upstream ownership.
- Existing `Version`: normalized metadata backed by a synchronized immutable archive.

## Invariants

- One package name has one owner inside a Packistry repository.
- Upstream credentials never cross the downstream Composer boundary.
- A cached archive is immutable and remains addressable by its checksum-qualified URL.
- Refresh failure never deletes the last valid metadata or a cached archive.

## Boundaries

`ComposerUpstream` owns Composer-registry communication. Existing VCS `Source` clients remain responsible for Git hosting integrations. Existing repository authorization remains responsible for downstream access.
