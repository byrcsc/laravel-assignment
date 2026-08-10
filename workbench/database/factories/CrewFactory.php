<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Crew;

/** @extends Factory<Crew> */
final class CrewFactory extends Factory
{
    protected $model = Crew::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->lastName().' crew',
            'email' => $this->faker->unique()->safeEmail(),
            'suburb' => $this->faker->randomElement(['richmond', 'fitzroy']),
            'available' => true,
        ];
    }

    public function unavailable(): self
    {
        return $this->state(fn (): array => ['available' => false]);
    }
}
