<?php

declare(strict_types=1);

namespace App\Sources\Gitlab\Event;

use App\Sources\Gitlab\Input;
use App\Sources\Gitlab\Project;
use App\Sources\ReferenceEvent;

class PushEvent extends Input implements ReferenceEvent
{
    public function __construct(
        public string $ref,
        public string $after,
        public string $before,
        public ?string $checkoutSha,
        public Project $project,
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
        return (string) $this->project->id;
    }
}
