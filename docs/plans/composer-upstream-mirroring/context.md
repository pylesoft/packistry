# Context

## Existing System

- A `Repository` is the downstream Composer endpoint and owns packages.
- A `Package` is unique by repository and name and owns versions.
- VCS `Source` clients import projects, branches, tags, and archives.
- `CreateFromZip` parses package metadata and stores archives.
- Composer responses already rewrite `dist.url` to Packistry.
- The Pylesoft fork preserves immutable archive history through checksum-qualified URLs.

## Constraints

- Paid upstream credentials must never appear in downstream metadata, URLs, logs, or client configuration.
- Existing VCS imports and upload APIs must remain unchanged.
- Composer 2 metadata and fast unknown-package responses are the initial compatibility target.
- The design must work with queued refresh jobs, S3-compatible storage, and multiple web replicas.

## Related Files And Modules

- `app/Enums/SourceProvider.php`
- `app/Sources/Client.php`
- `app/Models/Repository.php`
- `app/Models/Package.php`
- `app/Models/Version.php`
- `app/CreateFromZip.php`
- `app/Http/Controllers/Composer/RepositoryController.php`
- `app/Http/Resources/ComposerPackageResource.php`

## External References

- [Research: Composer upstream mirroring](../../research/composer-upstream-mirroring.md)
- [Packistry issue #192](https://github.com/packistry/packistry/issues/192)
- [Composer repository protocol](https://getcomposer.org/doc/05-repositories.md#composer)
- [Private Packagist mirrored repositories](https://packagist.com/docs/mirrored-repositories)
