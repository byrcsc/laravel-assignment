<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Tradie;

/** @extends Factory<Tradie> */
final class TradieFactory extends Factory
{
    protected $model = Tradie::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'trade' => $this->faker->randomElement(['plumbing', 'electrical']),
            'available' => true,
            'grade' => $this->faker->numberBetween(1, 3),
        ];
    }

    public function unavailable(): self
    {
        return $this->state(fn (): array => ['available' => false]);
    }
}
