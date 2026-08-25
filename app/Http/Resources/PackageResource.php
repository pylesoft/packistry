<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'repository_id' => $this->repository_id,
            'source_id' => $this->source_id,
            'provider_id' => $this->provider_id,
            'name' => $this->name,
            'type' => $this->type,
            'latest_version' => $this->latest_version,
            'versions' => VersionResource::collection($this->whenLoaded('versions')),
            'repository' => new RepositoryResource($this->whenLoaded('repository')),
            'source' => new SourceResource($this->whenLoaded('source')),
            'upstream_checked_at' => array_key_exists('upstream_checked_at', $attributes)
                ? $this->upstream_checked_at
                : null,
            'upstream_synced_at' => array_key_exists('upstream_synced_at', $attributes)
                ? $this->upstream_synced_at
                : null,
            'upstream_last_error' => array_key_exists('upstream_last_error', $attributes)
                ? $this->upstream_last_error
                : null,
            'description' => $this->description,
            'total_downloads' => $this->total_downloads,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
