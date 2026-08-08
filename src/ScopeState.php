<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use Illuminate\Support\Arr;

final class ScopeState
{
    /** @var array<array-key, mixed> */
    private array $state;

    public function __construct(private readonly AssignmentScope $scope)
    {
        $this->state = $scope->state;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->state, $key, $default);
    }

    public function put(string $key, mixed $value): self
    {
        Arr::set($this->state, $key, $value);

        return $this;
    }

    public function persist(): void
    {
        $this->scope->forceFill(['state' => $this->state])->save();
    }
}
