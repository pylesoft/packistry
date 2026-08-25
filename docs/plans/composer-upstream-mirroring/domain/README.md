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

- `ComposerUpstream`: connection, encrypted authentication, enabled state, and last successful validation time.
- Existing `Package`: downstream package plus optional upstream ownership and synchronization health.
- Existing `Version`: normalized metadata backed by a synchronized immutable archive.

## Invariants

- One package name has one owner inside a Packistry repository.
- Upstream credentials never cross the downstream Composer boundary.
- A cached archive is immutable and remains addressable by its checksum-qualified URL.
- Refresh failure never deletes the last valid metadata or a cached archive.
- A package snapshot becomes visible only after every changed archive has been safely downloaded and validated.

## Boundaries

`ComposerUpstream` owns Composer-registry communication. Existing VCS `Source` clients remain responsible for Git hosting integrations. Existing repository authorization remains responsible for downstream access.
