<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('creates and rolls back every package table', function (): void {
    expect(Schema::hasTable('assignments'))->toBeTrue()
        ->and(Schema::hasTable('assignment_scopes'))->toBeTrue()
        ->and(Schema::hasTable('assignment_queue'))->toBeTrue();

    $migration = require __DIR__.'/../../database/migrations/create_assignments_table.php.stub';
    $migration->down();

    expect(Schema::hasTable('assignments'))->toBeFalse()
        ->and(Schema::hasTable('assignment_scopes'))->toBeFalse()
        ->and(Schema::hasTable('assignment_queue'))->toBeFalse();

    $migration->up();
});

it('enforces one open assignment per assignable slot while retaining history', function (): void {
    $row = [
        'assignable_type' => 'lead',
        'assignable_id' => 1,
        'assignee_type' => 'seller',
        'assignee_id' => 1,
        'status' => AssignmentStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('assignments')->insert($row + ['slot' => '']);

    expect(fn () => DB::table('assignments')->insert(array_merge($row, ['assignee_id' => 2, 'slot' => ''])))
        ->toThrow(QueryException::class);

    DB::table('assignments')->where('id', 1)->update([
        'status' => AssignmentStatus::Ended->value,
        'slot' => null,
        'ended_at' => now(),
    ]);

    DB::table('assignments')->insert(array_merge($row, ['assignee_id' => 2, 'slot' => '']));
    DB::table('assignments')->where('id', 2)->update([
        'status' => AssignmentStatus::Ended->value,
        'slot' => null,
        'ended_at' => now(),
    ]);

    DB::table('assignments')->insert(array_merge($row, [
        'assignable_id' => 2,
        'assignee_id' => 3,
        'role' => 'driver',
        'slot' => 'driver',
    ]));
    DB::table('assignments')->insert(array_merge($row, [
        'assignable_id' => 2,
        'assignee_id' => 4,
        'role' => 'seller',
        'slot' => 'seller',
    ]));

    expect(fn () => DB::table('assignments')->insert(array_merge($row, [
        'assignable_id' => 2,
        'assignee_id' => 5,
        'role' => 'driver',
        'slot' => 'driver',
    ])))->toThrow(QueryException::class)
        ->and(DB::table('assignments')->count())->toBe(4);
});

it('honors custom table names and configured model key types', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_assignments_table.php.stub';
    $migration->down();

    config()->set('assignment.tables', [
        'assignments' => 'routing_assignments',
        'scopes' => 'routing_scopes',
        'queue' => 'routing_queue',
    ]);
    config()->set('assignment.assignable_key_type', 'uuid');
    config()->set('assignment.assignee_key_type', 'ulid');

    $migration->up();

    $assignableId = (string) Str::uuid();
    $assigneeId = (string) Str::ulid();

    DB::table('routing_assignments')->insert([
        'assignable_type' => 'lead',
        'assignable_id' => $assignableId,
        'assignee_type' => 'seller',
        'assignee_id' => $assigneeId,
        'slot' => '',
        'status' => AssignmentStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Schema::hasTable('routing_assignments'))->toBeTrue()
        ->and(Schema::hasTable('routing_scopes'))->toBeTrue()
        ->and(Schema::hasTable('routing_queue'))->toBeTrue()
        ->and(DB::table('routing_assignments')->value('assignable_id'))->toBe($assignableId)
        ->and(DB::table('routing_assignments')->value('assignee_id'))->toBe($assigneeId);
});

it('accepts string keys for both assignment sides', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_assignments_table.php.stub';
    $migration->down();

    config()->set('assignment.assignable_key_type', 'string');
    config()->set('assignment.assignee_key_type', 'string');

    $migration->up();

    DB::table('assignments')->insert([
        'assignable_type' => 'lead',
        'assignable_id' => 'lead-east',
        'assignee_type' => 'seller',
        'assignee_id' => 'seller-primary',
        'slot' => '',
        'status' => AssignmentStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('assignments')->value('assignable_id'))->toBe('lead-east')
        ->and(DB::table('assignments')->value('assignee_id'))->toBe('seller-primary');
});
