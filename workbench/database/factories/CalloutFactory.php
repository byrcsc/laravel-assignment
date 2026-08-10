<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Callout;

/** @extends Factory<Callout> */
final class CalloutFactory extends Factory
{
    protected $model = Callout::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference' => strtoupper($this->faker->bothify('JOB-####')),
            'suburb' => $this->faker->randomElement(['richmond', 'fitzroy']),
        ];
    }
}
