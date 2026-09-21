<?php

declare(strict_types=1);

namespace App\Sources\Bitbucket\Event;

use App\Sources\Bitbucket\Change;
use App\Sources\Bitbucket\Input;
use App\Sources\Bitbucket\Push;
use App\Sources\Bitbucket\Reference;
use App\Sources\Bitbucket\Repository;
use App\Sources\ReferenceEvent;
use RuntimeException;

class PushEvent extends Input implements ReferenceEvent
{
    public function __construct(
        public Push $push,
        public Repository $repository,
    ) {}

    public function latestChange(): Change
    {
        return $this->push->changes[0] ?? throw new RuntimeException('No changes supplied in webhook');
    }

    public function latestReference(): Reference
    {
        $change = $this->latestChange();
        $reference = $change->new ?? $change->old;

        if ($reference === null) {
            throw new RuntimeException('Neither old or new has been provided');
        }

        return $reference;
    }

    public function isTag(): bool
    {
        return $this->latestReference()->type === 'tag';
    }

    public function shortRef(): string
    {
        return $this->latestReference()->name;
    }

    public function id(): string
    {
        return trim($this->repository->uuid, '{}');
    }
}
