<?php

declare(strict_types=1);

namespace App\Actions\Sources;

use App\Enums\SourceProvider;
use App\Models\Source;
use Illuminate\Validation\ValidationException;

class DestroySource
{
    public function handle(Source $source): Source
    {
        if ($source->provider === SourceProvider::COMPOSER && $source->packages()->exists()) {
            throw ValidationException::withMessages([
                'source' => 'Remove enrolled packages before deleting this Composer source.',
            ]);
        }

        $source->delete();

        return $source;
    }
}
