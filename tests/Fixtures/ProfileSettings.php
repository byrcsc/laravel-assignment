<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Policies\FirstAvailable;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class ProfileSettings
{
    /** @var list<Model> */
    public array $candidates = [];

    public string|SelectionPolicy|Closure $policy = FirstAvailable::class;

    public ?string $scope = null;

    public Mode $mode = Mode::Assign;

    public ?int $ttl = null;

    public bool $queueWhenEmpty = true;

    public int $queuePriority = 0;

    /** @var list<array{method: string, role: ?string}> */
    public array $calls = [];

    public function record(string $method, ?string $role): void
    {
        $this->calls[] = ['method' => $method, 'role' => $role];
    }
}
