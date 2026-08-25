<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Package;
use App\Models\Version;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/** @mixin Package */
class ComposerPackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $versions = $this->versions
            ->filter(fn (Version $version): bool => $version->upstream_removed_at === null)
            ->map(fn (Version $version) => [
                ...collect($version->metadata)->except('upstream_dist_identity')->all(),
                'name' => $this->name,
                'version' => $version->name,
                'type' => $this->type,
                'time' => $version->created_at,
                'dist' => [
                    'type' => 'zip',
                    'url' => $this->repository->archiveUrl($this->name, $version->name, $version->shasum),
                    'shasum' => $version->shasum,
                ],
            ])
            ->all();

        return [
            'minified' => 'composer/2.0',
            'packages' => [
                $this->name => MetadataMinifier::minify($versions),
            ],
        ];
    }
}
