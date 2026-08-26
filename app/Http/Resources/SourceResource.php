<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SourceProvider;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin Source
 */
class SourceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $composer = $this->provider === SourceProvider::COMPOSER
            ? [
                'auth_type' => $this->auth_type,
                'has_credentials' => $this->hasCredentials(),
                'enabled' => $this->enabled,
                'last_checked_at' => $this->last_checked_at,
            ]
            : [];

        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'name' => $this->name,
            'url' => $this->url,
            'metadata' => (object) $this->metadata,
            ...$composer,
            'packages_count' => $this->whenCounted('packages'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
