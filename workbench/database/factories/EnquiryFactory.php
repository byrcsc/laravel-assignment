<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Enquiry;

/** @extends Factory<Enquiry> */
final class EnquiryFactory extends Factory
{
    protected $model = Enquiry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer' => $this->faker->name(),
            'trade' => $this->faker->randomElement(['plumbing', 'electrical']),
            'emergency' => false,
        ];
    }

    public function emergency(): self
    {
        return $this->state(fn (): array => ['emergency' => true]);
    }
}
