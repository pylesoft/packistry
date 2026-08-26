<?php

declare(strict_types=1);

namespace App\Models;

use App\Composer\ComposerUpstreamClient;
use App\Enums\ComposerUpstreamAuthType;
use App\Enums\SourceProvider;
use App\Sources\Client;
use Database\Factories\SourceFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property string $name
 * @property SourceProvider $provider
 * @property string $url
 * @property string $token
 * @property string $secret
 * @property ComposerUpstreamAuthType|null $auth_type
 * @property string|null $username
 * @property string|null $password
 * @property bool $enabled
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<array-key, mixed> $metadata
 * @property-read Collection<int, Package> $packages
 * @property-read int|null $packages_count
 *
 * @method static SourceFactory factory($count = null, $state = [])
 * @method static Builder<static>|Source newModelQuery()
 * @method static Builder<static>|Source newQuery()
 * @method static Builder<static>|Source query()
 *
 * @mixin Eloquent
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $hidden = [
        'token',
        'secret',
        'username',
        'password',
    ];

    protected $casts = [
        'provider' => SourceProvider::class,
        'metadata' => 'array',
        'auth_type' => ComposerUpstreamAuthType::class,
        'enabled' => 'bool',
        'last_checked_at' => 'datetime',
    ];

    protected $attributes = [
        'metadata' => '{}',
    ];

    public function vcsClient(): Client
    {
        return $this->provider->clientWith(
            token: decrypt($this->token),
            url: $this->url,
            metadata: $this->metadata,
        );
    }

    public function composerClient(): ComposerUpstreamClient
    {
        if ($this->provider !== SourceProvider::COMPOSER) {
            throw new RuntimeException("Source {$this->id} is not a Composer repository.");
        }

        return new ComposerUpstreamClient($this);
    }

    public function composerToken(): ?string
    {
        $token = filled($this->token) ? decrypt($this->token) : null;

        return filled($token) ? $token : null;
    }

    public function composerUsername(): ?string
    {
        $username = filled($this->username) ? decrypt($this->username) : null;

        return filled($username) ? $username : null;
    }

    public function composerPassword(): ?string
    {
        $password = filled($this->password) ? decrypt($this->password) : null;

        return filled($password) ? $password : null;
    }

    public function hasCredentials(): bool
    {
        return match ($this->auth_type) {
            ComposerUpstreamAuthType::NONE, null => false,
            ComposerUpstreamAuthType::BASIC => filled($this->composerUsername()) && filled($this->composerPassword()),
            ComposerUpstreamAuthType::BEARER => filled($this->composerToken()),
        };
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
