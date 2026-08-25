# Questions

| Question | Owner | Status | Recommendation |
| --- | --- | --- | --- |
| Must V1 explicitly enroll each package, or may configured namespace patterns trigger discovery on a Composer miss? | Ian | Resolved | Explicit enrollment confirmed; namespace discovery is deferred. |
| Should V1 mirror every version returned for an enrolled package or only versions matching an optional constraint? | Ian | Resolved | Mirror every version. |
| Is hourly scheduled refresh plus a package-level Refresh now action the right V1 freshness target? | Ian | Resolved | Confirmed. Keep one hour fixed in V1 and make it configurable only if real usage requires it. |
| Should the V1 authentication menu be None, HTTP Basic, and Bearer token? | Ian | Resolved | Confirmed. Add custom headers, OAuth, or client certificates only when a concrete vendor requires them. |
| What exact username value does Scramble Pro expect with its API key? | Ian | Resolved | Scramble account email as username and API token as password over HTTP Basic. |
| Should public Packagist caching be the immediate second phase? | Ian | Resolved | No. Revisit it after observing paid-mirror traffic and operational behavior. |
