<?php

declare(strict_types=1);

namespace App\Sources;

interface ReferenceEvent
{
    public function id(): string;

    public function isTag(): bool;

    public function shortRef(): string;
}
