# Provider Profiles

Provider profiles are acceptance examples, not vendor-specific adapters. The same Composer upstream client, authentication strategies, enrollment flow, and synchronization job serve every provider.

## Initial Providers

| Provider | Base URL | Package | Authentication | Credential convention | Confidence |
| --- | --- | --- | --- | --- | --- |
| Flux Pro | `https://composer.fluxui.dev` | `livewire/flux-pro` | HTTP Basic | Flux account email / license key | Confirmed in public Flux documentation. |
| Scramble Pro | `https://satis.dedoc.co` | `dedoc/scramble-pro` | HTTP Basic | Scramble account email / API token | Confirmed from the purchaser installation guide. |

## Common Paid Laravel Convention

The practical default across paid Laravel packages is a private Composer/Satis endpoint using HTTP Basic. The username is commonly the purchaser's account email, and the password is a license or API key. Flux Pro and Scramble Pro both use that exact convention. Laravel Nova and paid Spatie packages use the same broad shape.

Packistry should nevertheless model authentication as a small generic enum instead of fields named after licenses:

- `none`
- `basic` with `username` and `password`
- `bearer` with `token`

The UI may explain that Basic commonly means email plus license key, but storage and the HTTP client remain vendor-neutral.

## Acceptance Coverage

- Validate each upstream without persisting exposed credential values.
- Enroll `livewire/flux-pro` and `dedoc/scramble-pro` independently into the existing `Pyle` repository.
- Mirror all advertised versions and replace upstream distribution URLs with Packistry URLs.
- Install each package in a clean Composer project configured only with the Packistry repository and token.
- Refresh after a new upstream version appears without changing downstream credentials.

## Sources

- [Flux installation and private repository authentication](https://fluxui.dev/docs/installation)
- [Scramble Pro purchase and API-key delivery](https://scramble.dedoc.co/pro)
- [Composer private repository authentication methods](https://getcomposer.org/doc/articles/authentication-for-private-packages.md)
- [Laravel Nova installation](https://nova.laravel.com/docs/v5/installation)
