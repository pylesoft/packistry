<?php

declare(strict_types=1);

namespace App\Sources\GitHub\Event;

use App\Sources\GitHub\Input;
use App\Sources\GitHub\Repository;
use App\Sources\ReferenceEvent;

class PushEvent extends Input implements ReferenceEvent
{
    public function __construct(
        public string $ref,
        public Repository $repository,
    ) {}

    public function isTag(): bool
    {
        return str_starts_with($this->ref, 'refs/tags/');
    }

    public function shortRef(): string
    {
        $parts = explode('/', $this->ref);

        return implode('/', array_slice($parts, 2));
    }

    public function id(): string
    {
        return (string) $this->repository->id;
    }
}
