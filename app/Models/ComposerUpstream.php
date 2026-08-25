<?php

declare(strict_types=1);

namespace App\Models;

use App\Composer\ComposerUpstreamClient;
use App\Enums\ComposerUpstreamAuthType;
use Database\Factories\ComposerUpstreamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property ComposerUpstreamAuthType $auth_type
 * @property string|null $username
 * @property string|null $password
 * @property string|null $token
 * @property bool $enabled
 * @property string $health_status
 * @property string|null $last_error
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_synced_at
 */
class ComposerUpstream extends Model
{
    /** @use HasFactory<ComposerUpstreamFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['username', 'password', 'token'];

    protected function casts(): array
    {
        return [
            'auth_type' => ComposerUpstreamAuthType::class,
            'username' => 'encrypted',
            'password' => 'encrypted',
            'token' => 'encrypted',
            'enabled' => 'bool',
            'last_checked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<Package, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function client(): ComposerUpstreamClient
    {
        return new ComposerUpstreamClient($this);
    }

    public function hasCredentials(): bool
    {
        return match ($this->auth_type) {
            ComposerUpstreamAuthType::NONE => false,
            ComposerUpstreamAuthType::BASIC => filled($this->username) && filled($this->password),
            ComposerUpstreamAuthType::BEARER => filled($this->token),
        };
    }
}
