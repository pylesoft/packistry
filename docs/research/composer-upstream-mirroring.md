# Composer Upstream Mirroring: Reference Behaviors and Constraints

Research date: 2026-08-25

## Question

What established behavior should inform a future Packistry feature that accepts authenticated Composer repositories (for example, paid vendor repositories), exposes their packages through Packistry's existing repository endpoint, and optionally caches Packagist.org and GitHub-backed distributions?

This note records external behavior and protocol constraints. It deliberately does not select a product design.

## Executive synthesis

There are two materially different capabilities hidden behind the word “proxy”:

1. **Distribution caching** keeps a local copy of a package archive and rewrites or supplements the package's `dist` URL. Repman's Packagist proxy follows this model: it exposes Packagist metadata and advertises a preferred local distribution URL, then downloads the upstream archive into storage on the first cache miss. The original distribution/source locations remain available to Composer in Repman's documented setup. [Repman proxy documentation](https://repman.io/docs/proxy/), [Repman repository response](https://github.com/repman-io/repman/blob/master/src/Controller/ProxyController.php#L47-L68), [Repman lazy distribution cache](https://github.com/repman-io/repman/blob/master/src/Service/Proxy.php#L44-L66)
2. **Repository mirroring/aggregation** fetches upstream Composer metadata with upstream credentials, decides which upstream owns a package, persists package/version metadata, rewrites downloads to the mirror, and lets clients authenticate only to the aggregate repository. Private Packagist implements this broader model for Packagist.org and authenticated third-party repositories. [Private Packagist mirrored repositories](https://packagist.com/docs/mirrored-repositories), [Private Packagist project setup](https://packagist.com/docs/setup)

The paid-repository use case requires the second capability if the goal is to remove vendor credentials and vendor repository declarations from every developer and CI environment. A distribution-only cache cannot discover a paid package or fetch its protected metadata on behalf of Composer; repository metadata is what tells Composer that the package/version exists and where its distribution is located. [Composer repository protocol](https://getcomposer.org/doc/05-repositories.md#composer), [Composer authentication](https://getcomposer.org/doc/articles/authentication-for-private-packages.md)

Caching Packagist.org metadata and caching GitHub archives are also separate concerns. Packagist.org is commonly the metadata origin, while GitHub, GitLab, or another VCS host supplies the distribution archive; Private Packagist explicitly documents this distinction. [Private Packagist package lifecycle](https://packagist.com/docs/package-lifecycle#when-a-version-is-republished-with-different-content)

## Packistry ecosystem findings

Packistry has an open enhancement requesting exactly this capability: adding Packagist and authenticated private Composer repositories as sources for CI/CD mirroring. The maintainer has considered mirroring and is open to a pull request, but it is currently low priority. [Packistry issue #192](https://github.com/packistry/packistry/issues/192), [maintainer response](https://github.com/packistry/packistry/issues/192#issuecomment-3053368554)

The closest upstream contribution was PR #328, which proposed repository modes and manual ZIP uploads for CI-built artifacts. It was closed without merge after the maintainer questioned the additional `sync_mode` abstraction because Packistry already supported authenticated package uploads. This addresses artifact publication, not upstream Composer metadata discovery or pull-through caching. [Packistry PR #328](https://github.com/packistry/packistry/pull/328), [maintainer review](https://github.com/packistry/packistry/pull/328#issuecomment-4164828486), [contributor rationale](https://github.com/packistry/packistry/pull/328#issuecomment-4168332643)

An audit performed on 2026-08-25 covered all 41 public forks and their public branch heads. No fork implements a generic Composer upstream, authenticated third-party repository mirror, Packagist cache, or GitHub proxy. Adjacent reusable work is limited to:

- Pylesoft's immutable archive history, which preserves checksum-qualified downloads when a mutable development version is refreshed. [Pylesoft PR #1](https://github.com/pylesoft/packistry/pull/1)
- A more extensive authenticated package-upload API, useful for optional push-based artifact ingestion but not transparent mirroring. [fourstrings77 upload API commit](https://github.com/fourstrings77/packistry/commit/c19c8b3d29cc6a3fa9cab29d5871f888bc30124c)
- An object-storage redirect implementation, useful for serving cached archives through presigned URLs. [revenexx object-storage commit](https://github.com/revenexx/packistry/commit/b5955e6ae93dce20f12ef2539a6cb4225b9650f6)

Packistry's current source abstraction is explicitly VCS-shaped: providers are limited to GitHub, GitLab, Gitea, and Bitbucket, while every source client must expose projects, branches, tags, webhook creation, and token validation. A Composer registry does not naturally satisfy that contract and should be modeled as a separate upstream module rather than another `SourceProvider`. [Packistry source providers](https://github.com/packistry/packistry/blob/356717dac8760e882068fc29dbc6b645e14c106f/app/Enums/SourceProvider.php), [Packistry source client](https://github.com/packistry/packistry/blob/356717dac8760e882068fc29dbc6b645e14c106f/app/Sources/Client.php)

Packistry already owns most of the archive-serving boundary needed by a mirror: it can download a ZIP, parse `composer.json`, persist version metadata, hash and store the archive, and expose a Packistry-owned `dist.url`. Mirroring therefore needs a new discovery, credential, refresh, and upstream-ownership layer, while much of the existing package/version/archive pipeline can be reused. [Packistry import](https://github.com/packistry/packistry/blob/356717dac8760e882068fc29dbc6b645e14c106f/app/Import.php), [Packistry ZIP ingestion](https://github.com/packistry/packistry/blob/356717dac8760e882068fc29dbc6b645e14c106f/app/CreateFromZip.php), [Packistry Composer resource](https://github.com/packistry/packistry/blob/356717dac8760e882068fc29dbc6b645e14c106f/app/Http/Resources/ComposerPackageResource.php)

Issue #262 reinforces the intended security boundary: exposing original private GitHub distribution URLs would make clients require GitHub access and could produce 403 responses. An authenticated mirror should fetch upstream content server-side and publish Packistry URLs without leaking upstream credentials. [Packistry issue #262](https://github.com/packistry/packistry/issues/262), [maintainer response](https://github.com/packistry/packistry/issues/262#issuecomment-3715884427)

## Composer wire contract

### Repository discovery and metadata

A Composer repository is rooted at `packages.json`. For Composer 2, `metadata-url` points to a per-package endpoint such as `/p2/%package%.json`; a development-enabled lookup may additionally request `%package%~dev`. Each response must contain versions for only that package. Composer requires a fast `404` for an unknown package. [Composer repository metadata](https://getcomposer.org/doc/05-repositories.md#metadata-url-available-packages-and-available-package-patterns)

Composer revalidates per-package metadata with `If-Modified-Since`, so a proxy or materialized mirror must emit an accurate `Last-Modified` value. Composer also supports minified version arrays identified by `"minified": "composer/2.0"`, and a repository may advertise `available-packages` or `available-package-patterns` to avoid needless package-miss requests. [Composer repository metadata](https://getcomposer.org/doc/05-repositories.md#metadata-url-available-packages-and-available-package-patterns)

Composer v1 uses `provider-includes` and `providers-url`; Composer 2 prioritizes `metadata-url`. A new implementation may deliberately support Composer 2 only, as Nexus does, but that is an explicit compatibility boundary rather than an implementation detail. [Composer provider metadata](https://getcomposer.org/doc/05-repositories.md#provider-includes-and-providers-url), [Nexus Composer support](https://help.sonatype.com/en/composer-repositories.html)

### Package identity and downloads

Composer treats each version as a separate package record. A usable version requires at least a name, version, and either a `dist` or `source` definition. `dist` is the packaged archive path; `source` is normally the VCS checkout path. [Composer package model](https://getcomposer.org/doc/05-repositories.md#package)

A mirror can advertise its archive in either of two ways:

- replace the upstream `dist.url`, making the mirror the only distribution location; or
- retain the original `dist.url` and add the mirror as a preferred `dist.mirrors` entry.

Private Packagist documents both resulting lock-file forms and notes that Composer can otherwise fall back from the mirror to the original archive and finally to the source checkout. [Private Packagist download fallback behavior](https://packagist.com/docs/security-settings#why-composers-download-fallbacks-are-a-risk)

That choice affects both availability and credential centralization. Keeping upstream fallback URLs improves resilience when public upstreams remain reachable, but a paid repository's protected URL or VCS source URL can make the client require upstream credentials again. Removing upstream `dist` mirrors and `source` makes Packistry the sole path, but also makes Packistry availability authoritative. This is an inference from Composer's documented fallback sequence and Private Packagist's two metadata forms. [Private Packagist security settings](https://packagist.com/docs/security-settings#legacy-insecure-package-download-fallback)

### Repository priority and namespace ownership

Composer 2 repositories are canonical by default: it searches repositories in order and stops when the first repository containing a package is found. Composer documents this as both a performance property and a dependency-confusion defense. It also supports per-repository `only` and `exclude` filters. [Composer repository priorities](https://getcomposer.org/doc/articles/repository-priorities.md)

An aggregate repository therefore needs an equivalent ownership rule when several upstreams expose the same package name. Private Packagist orders mirrors, always places Packagist.org last, pins a mirrored package to one upstream, and does not merge different versions of the same package across upstreams. [Private Packagist repository priority](https://packagist.com/docs/mirrored-repositories#repository-priority)

## Repman reference behavior

Repman describes itself as a Packagist.org proxy/CDN plus a separate host for private packages. Its documented client configuration points Composer at `https://repo.repman.io` and disables Packagist.org. [Repman proxy documentation](https://repman.io/docs/proxy/), [Repman repository](https://github.com/repman-io/repman)

At the repository endpoint, Repman advertises Composer 2 metadata, Composer 1 providers, Packagist.org search, download notifications, and a preferred local `dist` mirror. [Repman `packages.json` controller](https://github.com/repman-io/repman/blob/master/src/Controller/ProxyController.php#L47-L70)

For metadata, Repman lazily fetches `/p2/{package}.json` from an upstream on the first request and stores it in its proxy filesystem. A synchronization command later refreshes stored metadata and regenerates legacy provider hashes. [Repman proxy service](https://github.com/repman-io/repman/blob/master/src/Service/Proxy.php#L34-L42), [Repman lazy metadata cache](https://github.com/repman-io/repman/blob/master/src/Service/Proxy.php#L317-L340), [Repman metadata synchronization](https://github.com/repman-io/repman/blob/master/src/Service/Proxy.php#L160-L219)

For distributions, Repman derives a storage key from upstream host, package, reference, and archive format. On a miss it reads the package metadata, finds the matching `dist.reference`, downloads the upstream `dist.url`, stores the stream, and serves it. Its release-feed command prefetches newly released archives only for package names already present in the cache. [Repman distribution cache](https://github.com/repman-io/repman/blob/master/src/Service/Proxy.php#L44-L66), [Repman distribution path](https://github.com/repman-io/repman/blob/master/src/Service/Proxy.php#L342-L351), [Repman release synchronization](https://github.com/repman-io/repman/blob/master/src/Command/ProxySyncReleasesCommand.php#L70-L114)

Repman's proxy abstraction can register multiple upstream URLs and always adds Packagist.org, but the proxy factory receives only a URL and the proxy's metadata/archive calls do not pass repository-specific headers. The downloader interface can accept headers, so authenticated upstreams would require credential-aware configuration and propagation through this path rather than merely adding another URL. [Repman proxy register](https://github.com/repman-io/repman/blob/master/src/Service/Proxy/ProxyRegister.php#L18-L49), [Repman proxy factory](https://github.com/repman-io/repman/blob/master/src/Service/Proxy/ProxyFactory.php), [Repman downloader interface](https://github.com/repman-io/repman/blob/master/src/Service/Downloader.php)

## Private Packagist reference behavior

Private Packagist accepts authenticated third-party Composer repositories by separating stored credentials from the repository configuration. Once a mirror is configured, projects can remove the third-party repository from `composer.json`; clients use only the Private Packagist endpoint and its organization token. [Private Packagist mirrored repositories](https://packagist.com/docs/mirrored-repositories#adding-a-mirrored-repository), [Private Packagist setup](https://packagist.com/docs/setup#basic-setup)

Its discovery policy has several observable parts:

- enabled upstreams are queried in parallel when the requested package has not yet been mirrored;
- priority chooses the winner if multiple upstreams contain the name;
- once selected, all versions remain attached to that one upstream;
- automatic discovery occurs for `composer update`/`require`, not `install`, and requires an update-capable downstream token;
- manual and admin-only import modes are also available. [Private Packagist finding and permission behavior](https://packagist.com/docs/mirrored-repositories#finding-packages-in-mirrored-repositories)

For freshness, Private Packagist monitors an upstream `metadata-changes-url` when present; otherwise it checks for updates every 12 hours and allows manual refresh. [Private Packagist update behavior](https://packagist.com/docs/mirrored-repositories#troubleshooting)

For lifecycle stability, removal from an upstream removes a version from new dependency resolution but retains already-downloaded archives so existing lock files remain installable. If a VCS tag moves, the new commit receives new metadata while old and new archives remain available to their respective lock files. For third-party repositories that publish a checksum, the initially mirrored file and checksum stay paired even if upstream content later changes. [Private Packagist package lifecycle](https://packagist.com/docs/package-lifecycle#mirrored-packages)

Disabling a mirror stops discovery/refresh requests but preserves existing mirrored packages; deleting a mirror deletes its packages. This distinction is an explicit data-lifecycle operation in Private Packagist. [Private Packagist repository management](https://packagist.com/docs/mirrored-repositories#managing-repositories)

## Established repository-manager patterns

Nexus distinguishes hosted, proxy, and group repositories. Its Composer proxy caches public or private Composer 2 repositories, while a group exposes hosted and proxied repositories through one URL. [Nexus Composer repositories](https://help.sonatype.com/en/composer-repositories.html)

General-purpose repository managers distinguish immutable component storage from mutable metadata. Nexus exposes separate maximum component and metadata ages plus a negative cache for missing artifacts. Artifactory similarly has metadata freshness, stale-response-on-timeout, assumed-offline, negative-404, and unused-artifact retention controls. [Nexus repository types](https://help.sonatype.com/en/repository-types.html), [Nexus configurable repository fields](https://help.sonatype.com/en/configurable-repository-fields.html), [Artifactory remote repository cache settings](https://docs.jfrog.com/artifactory/docs/remote-repositories#cache-settings-for-remote-repositories)

These products also preserve a useful operational distinction between an upstream being offline and deleting its cache. Artifactory's offline mode serves only cached artifacts, and cache invalidation expires metadata without deleting immutable binaries. [Artifactory remote repositories](https://docs.jfrog.com/artifactory/docs/remote-repositories), [Artifactory cache behavior](https://docs.jfrog.com/artifactory/docs/remote-repositories#cache-settings-for-remote-repositories)

## Authentication constraints

Composer upstreams can use HTTP Basic, Bearer tokens, custom headers, GitHub/GitLab/Bitbucket-specific tokens, or client certificates. Composer warns against placing credentials in committed `composer.json` and supports project/global `auth.json` and `COMPOSER_AUTH` instead. A generic upstream-mirror feature must either define a supported subset or model authentication as a strategy rather than assume every vendor uses the same token shape. [Composer authentication methods](https://getcomposer.org/doc/articles/authentication-for-private-packages.md)

Repository credentials must be applied only to the configured upstream and must not be copied into downstream metadata, archive URLs, logs, or client configuration. This is a security inference from Composer's warning about credential exposure and Private Packagist's separation of credentials from mirror configuration. [Composer credential storage warning](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#authentication-in-composerjson-file-itself), [Private Packagist mirrored repository setup](https://packagist.com/docs/mirrored-repositories#adding-a-mirrored-repository)

Redirect handling is part of authenticated archive retrieval. GitHub's archive endpoints return redirects; private-repository archive links expire after five minutes and require only read access to repository contents. [GitHub repository archive endpoints](https://docs.github.com/en/rest/repos/contents#download-a-repository-archive-zip)

## Packagist.org and GitHub caching constraints

For public packages, a warm distribution cache removes repeated archive downloads from GitHub, but metadata freshness still depends on Packagist.org unless the mirror also stores/revalidates metadata. Conversely, a metadata-only cache does not remove GitHub archive traffic. This follows from Composer's separate metadata and `dist` fields and Private Packagist's description of Packagist.org as metadata while VCS hosts serve the archives. [Composer repository protocol](https://getcomposer.org/doc/05-repositories.md), [Private Packagist package lifecycle](https://packagist.com/docs/package-lifecycle#when-a-version-is-republished-with-different-content)

GitHub applies primary and secondary REST API rate limits. As of this research, unauthenticated requests receive 60 requests/hour, authenticated user requests generally receive 5,000 requests/hour, and GitHub App installation limits begin at 5,000/hour and can scale with organization size; GitHub instructs clients to honor `Retry-After` and rate-limit reset headers rather than keep retrying. [GitHub REST API rate limits](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api)

A GitHub archive cache therefore needs deduplication of concurrent misses for the same immutable reference, redirect-following download support, bounded retry/backoff, and visibility into GitHub rate-limit headers. This is an implementation inference from GitHub's redirect and rate-limit contracts. [GitHub archive endpoint](https://docs.github.com/en/rest/repos/contents#download-a-repository-archive-zip), [GitHub rate-limit handling](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api#exceeding-the-rate-limit)

## Capability options to carry into a PRD

These are separable scopes, not a recommended sequence:

| Capability | What is cached/materialized | What remains upstream-dependent |
| --- | --- | --- |
| Preferred distribution mirror | Archives requested through Packistry | Metadata discovery; optional original `dist`/`source` fallback |
| Transparent repository proxy | Upstream `packages.json`, `/p2/*`, and archives on demand | Cold misses and freshness revalidation |
| Materialized package mirror | Selected package/version metadata and immutable archives | Refresh/discovery of new versions |
| Aggregate/virtual repository | Unified metadata across native packages and multiple mirrors | The configured resolution/ownership policy |
| Packagist.org mirror | Public metadata plus optionally public archives | Cold public-package misses and refresh feed/polling |
| GitHub archive cache | Archives addressed by repository and immutable commit/reference | Metadata discovery and source checkouts |

The distinctions in this table correspond to the separate metadata/download protocol in Composer, Repman's lazy metadata-plus-dist cache, Private Packagist's materialized mirrors, and Nexus's proxy/group split. [Composer repository protocol](https://getcomposer.org/doc/05-repositories.md), [Repman proxy service](https://github.com/repman-io/repman/blob/master/src/Service/Proxy.php), [Private Packagist mirrored repositories](https://packagist.com/docs/mirrored-repositories), [Nexus Composer repositories](https://help.sonatype.com/en/composer-repositories.html)

## Questions the PRD must resolve

1. Is the first scope paid Composer repositories only, or also Packagist.org metadata and public GitHub archives?
2. Is discovery automatic on `update`/`require`, manual/admin-approved, or both?
3. Which upstream authentication strategies are required initially: Basic, Bearer, custom header, and/or client certificate?
4. Does one upstream permanently own a package name, and how is ownership changed safely?
5. Are upstream `dist` and `source` fallbacks retained, configurable, or removed?
6. Does a removed upstream version remain installable from an existing lock file?
7. Is the supported client contract Composer 2 only, or must legacy provider metadata be generated?
8. What are the metadata TTL, negative-cache TTL, stale-if-error, manual refresh, and offline-mode semantics?
9. Are archives fetched lazily, prefetched during refresh, or selectable per mirror?
10. How are concurrent cache misses, incomplete downloads, checksums, republished archives, and moved VCS tags handled?
11. Which downstream token permissions may trigger automatic mirroring versus read only?
12. What audit events and metrics are required for credential failures, upstream latency, cache hit ratio, storage growth, and rate limiting?

Each question corresponds to behavior exposed by Composer, Private Packagist, Repman, Nexus, Artifactory, or GitHub in the cited sections above; answering them will turn “proxy” into a testable product contract rather than a single ambiguous feature.
