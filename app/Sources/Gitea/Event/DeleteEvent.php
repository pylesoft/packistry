<?php

declare(strict_types=1);

namespace App\Sources\Gitea\Event;

use App\Sources\Gitea\Input;
use App\Sources\Gitea\Repository;
use App\Sources\ReferenceEvent;

class DeleteEvent extends Input implements ReferenceEvent
{
    public function __construct(
        public string $ref,
        public string $refType,
        public string $pusherType,
        public Repository $repository,
    ) {}

    public function isTag(): bool
    {
        return $this->refType === 'tag';
    }

    public function shortRef(): string
    {
        return $this->ref;
    }

    public function id(): string
    {
        return (string) $this->repository->id;
    }
}
