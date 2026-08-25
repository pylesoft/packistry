<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ComposerUpstream;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/** @mixin ComposerUpstream */
class ComposerUpstreamResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'auth_type' => $this->auth_type,
            'has_credentials' => $this->hasCredentials(),
            'enabled' => $this->enabled,
            'last_checked_at' => $this->last_checked_at,
            'packages_count' => $this->whenCounted('packages'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
