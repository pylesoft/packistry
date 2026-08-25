<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ComposerUpstreamAuthType;
use App\Enums\PackageType;
use App\Enums\Permission;
use App\Http\Resources\ComposerUpstreamResource;
use App\Http\Resources\PackageResource;
use App\Jobs\RefreshComposerPackage;
use App\Models\ComposerUpstream;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

readonly class ComposerUpstreamController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize(Permission::COMPOSER_UPSTREAM_READ);

        return response()->json(ComposerUpstreamResource::collection(
            ComposerUpstream::query()->withCount('packages')->get()
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize(Permission::COMPOSER_UPSTREAM_CREATE);
        $data = $this->validatedConnection($request, false);
        $upstream = new ComposerUpstream($data);

        $upstream->forceFill($this->validatedHealth($upstream));
        $upstream->save();

        return response()->json(new ComposerUpstreamResource($upstream), 201);
    }

    public function update(Request $request, ComposerUpstream $composerUpstream): JsonResponse
    {
        $this->authorize(Permission::COMPOSER_UPSTREAM_UPDATE);
        $data = $this->validatedConnection($request, true, $composerUpstream);
        $candidate = $composerUpstream->replicate();
        $candidate->fill($data);

        if (($data['enabled'] ?? $composerUpstream->enabled) !== false) {
            $data = [...$data, ...$this->validatedHealth($candidate)];
        }
        $composerUpstream->fill($data)->save();

        return response()->json(new ComposerUpstreamResource($composerUpstream->refresh()));
    }

    public function destroy(ComposerUpstream $composerUpstream): JsonResponse
    {
        $this->authorize(Permission::COMPOSER_UPSTREAM_DELETE);

        if ($composerUpstream->packages()->exists()) {
            throw ValidationException::withMessages([
                'upstream' => 'Remove enrolled packages before deleting this Composer upstream.',
            ]);
        }

        $composerUpstream->delete();

        return response()->json(new ComposerUpstreamResource($composerUpstream));
    }

    public function storePackage(Request $request, ComposerUpstream $composerUpstream): JsonResponse
    {
        $this->authorize(Permission::PACKAGE_CREATE);
        $data = $request->validate([
            'repository_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'regex:/^[^\/\s]+\/[^\/\s]+$/'],
        ]);

        if (! $composerUpstream->enabled) {
            throw ValidationException::withMessages(['upstream' => 'The Composer upstream is disabled.']);
        }

        /** @var Repository $repository */
        $repository = Repository::query()->userScoped()->findOrFail($data['repository_id']);
        $name = $data['name'];
        $existing = $repository->packageByName($name);

        if ($existing !== null && $existing->composer_upstream_id !== $composerUpstream->id) {
            throw ValidationException::withMessages(['name' => 'This package is already owned by another source.']);
        }

        try {
            $metadata = $composerUpstream->client()->package($name);
        } catch (Throwable) {
            throw ValidationException::withMessages(['name' => 'The package could not be found upstream.']);
        }

        if ($metadata['versions'] === []) {
            throw ValidationException::withMessages(['name' => 'The upstream returned no package versions.']);
        }

        $package = $existing ?? $repository->packages()->make();
        $package->forceFill([
            'name' => $name,
            'type' => PackageType::LIBRARY->value,
            'composer_upstream_id' => $composerUpstream->id,
        ])->save();

        $batch = RefreshComposerPackage::dispatchFor($package, $metadata);
        if ($batch === null) {
            return response()->json(['message' => 'A refresh for this package is already in progress.'], 409);
        }

        return response()->json([
            'package' => new PackageResource($package->fresh(['repository', 'composerUpstream'])),
            'batch_id' => $batch->id,
        ], 202);
    }

    public function refresh(Request $request, ComposerUpstream $composerUpstream): JsonResponse
    {
        $this->authorize(Permission::PACKAGE_UPDATE);
        $data = $request->validate(['package_id' => ['nullable', 'integer']]);
        $packagesQuery = Package::query()
            ->userScoped()
            ->where('composer_upstream_id', $composerUpstream->id);

        if (isset($data['package_id'])) {
            /** @var Package $package */
            $package = $packagesQuery->whereKey($data['package_id'])->firstOrFail();
            $packages = collect([$package]);
        } else {
            $packages = $packagesQuery->get();
        }

        $batches = collect();
        $skippedPackageIds = collect();
        foreach ($packages as $package) {
            $batch = RefreshComposerPackage::dispatchFor($package);
            if ($batch === null) {
                $skippedPackageIds->push($package->id);

                continue;
            }

            $batches->push($batch);
        }

        return response()->json([
            'accepted' => $batches->count(),
            'batch_ids' => $batches->pluck('id')->values(),
            'skipped_package_ids' => $skippedPackageIds->values(),
            'skipped_count' => $skippedPackageIds->count(),
        ], 202);
    }

    public function refreshPackage(string $packageId): JsonResponse
    {
        $this->authorize(Permission::PACKAGE_UPDATE);
        /** @var Package $package */
        $package = Package::query()
            ->userScoped()
            ->whereNotNull('composer_upstream_id')
            ->whereHas('composerUpstream', fn ($query) => $query->where('enabled', true))
            ->findOrFail($packageId);
        $batch = RefreshComposerPackage::dispatchFor($package);
        if ($batch === null) {
            return response()->json(['message' => 'A refresh for this package is already in progress.'], 409);
        }

        return response()->json(['accepted' => true, 'batch_id' => $batch->id]);
    }

    /** @return array<string, mixed> */
    private function validatedConnection(Request $request, bool $updating, ?ComposerUpstream $existing = null): array
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'required', 'url:http,https', 'max:2048'],
            'auth_type' => ['sometimes', 'required', Rule::enum(ComposerUpstreamAuthType::class)],
            'username' => ['nullable', 'string', 'max:1000'],
            'password' => ['nullable', 'string', 'max:1000'],
            'token' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $data['url'] = rtrim((string) ($data['url'] ?? $existing?->url), '/');
        $parts = parse_url($data['url']);
        if (! is_array($parts) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            throw ValidationException::withMessages(['url' => 'The upstream URL must contain only an HTTP(S) URL and path.']);
        }

        $auth = ComposerUpstreamAuthType::from((string) ($data['auth_type'] ?? $existing?->auth_type->value ?? 'none'));
        $data['auth_type'] = $auth;

        $authChanged = $updating && $existing?->auth_type !== $auth;
        $credentialsRequired = ! $updating || $authChanged || $existing?->hasCredentials() !== true;

        if ($auth === ComposerUpstreamAuthType::BASIC) {
            $hasUsername = array_key_exists('username', $data) && filled($data['username']);
            $hasPassword = array_key_exists('password', $data) && filled($data['password']);
            if ($credentialsRequired && (! $hasUsername || ! $hasPassword)) {
                throw ValidationException::withMessages(['password' => 'Basic authentication requires a username and password.']);
            }

            if ($updating && ! $authChanged) {
                if (! $hasUsername) {
                    unset($data['username']);
                }
                if (! $hasPassword) {
                    unset($data['password']);
                }
            }
        }

        if ($auth === ComposerUpstreamAuthType::BEARER && $credentialsRequired && ! filled($data['token'] ?? null)) {
            throw ValidationException::withMessages(['token' => 'Bearer authentication requires a token.']);
        }

        if ($auth === ComposerUpstreamAuthType::BEARER && $updating && ! $authChanged && ! filled($data['token'] ?? null)) {
            unset($data['token']);
        }

        if ($auth === ComposerUpstreamAuthType::NONE) {
            $data['username'] = $data['password'] = $data['token'] = null;
        } elseif ($auth === ComposerUpstreamAuthType::BASIC) {
            $data['token'] = null;
        } else {
            $data['username'] = $data['password'] = null;
        }

        return $data;
    }

    /** @return array{last_checked_at: Carbon} */
    private function validatedHealth(ComposerUpstream $upstream): array
    {
        try {
            $upstream->client()->validate();
        } catch (Throwable) {
            throw ValidationException::withMessages(['url' => 'The Composer upstream could not be validated.']);
        }

        return [
            'last_checked_at' => now(),
        ];
    }
}
