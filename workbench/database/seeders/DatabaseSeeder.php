<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Callout;
use Workbench\App\Models\Crew;
use Workbench\App\Models\Enquiry;
use Workbench\App\Models\Tradie;

/**
 * Sized so a rotation comes round more than once and a workload spread is
 * visible: three available tradies per trade against three enquiries each,
 * and more callouts per suburb than crews to take them. One unavailable
 * tradie and one unavailable crew give eligibility something to filter.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Tradie::factory()->count(3)->sequence(
            ['name' => 'Ada', 'email' => 'ada@example.test', 'trade' => 'plumbing', 'grade' => 1],
            ['name' => 'Bo', 'email' => 'bo@example.test', 'trade' => 'plumbing', 'grade' => 2],
            ['name' => 'Cleo', 'email' => 'cleo@example.test', 'trade' => 'plumbing', 'grade' => 3],
        )->create();

        Tradie::factory()->count(3)->sequence(
            ['name' => 'Eli', 'email' => 'eli@example.test', 'trade' => 'electrical', 'grade' => 1],
            ['name' => 'Fay', 'email' => 'fay@example.test', 'trade' => 'electrical', 'grade' => 2],
            ['name' => 'Gus', 'email' => 'gus@example.test', 'trade' => 'electrical', 'grade' => 3],
        )->create();

        Tradie::factory()->unavailable()->create([
            'name' => 'Dane',
            'email' => 'dane@example.test',
            'trade' => 'plumbing',
            'grade' => 1,
        ]);

        Crew::factory()->count(3)->sequence(
            ['name' => 'Hana crew', 'email' => 'hana@example.test', 'suburb' => 'richmond'],
            ['name' => 'Ivo crew', 'email' => 'ivo@example.test', 'suburb' => 'richmond'],
            ['name' => 'Jo crew', 'email' => 'jo@example.test', 'suburb' => 'richmond'],
        )->create();

        Crew::factory()->count(2)->sequence(
            ['name' => 'Kai crew', 'email' => 'kai@example.test', 'suburb' => 'fitzroy'],
            ['name' => 'Lou crew', 'email' => 'lou@example.test', 'suburb' => 'fitzroy'],
        )->create();

        Crew::factory()->unavailable()->create([
            'name' => 'Mira crew',
            'email' => 'mira@example.test',
            'suburb' => 'richmond',
        ]);

        Enquiry::factory()->count(6)->sequence(
            ['trade' => 'plumbing', 'emergency' => true],
            ['trade' => 'electrical', 'emergency' => false],
        )->create();

        Callout::factory()->count(8)->sequence(
            ['suburb' => 'richmond'],
            ['suburb' => 'fitzroy'],
        )->create();
    }
}
