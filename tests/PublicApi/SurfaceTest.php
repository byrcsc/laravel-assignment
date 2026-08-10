<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\AssignmentManager;
use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\AssignmentServiceProvider;
use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Notifications\AssignmentNotification;
use ByRcsc\LaravelAssignment\Tests\PublicApi\Introspection;
use ByRcsc\LaravelAssignment\Tests\PublicApi\Surface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Turns a manifest keyed by class into a Pest dataset of [key, value] pairs.
 *
 * @param  array<string, mixed>  $manifest
 */
function surfaceDataset(array $manifest): Generator
{
    foreach ($manifest as $key => $value) {
        yield $key => [$key, $value];
    }
}

it('pins the signature of every public method', function (string $class, string $method, string $signature): void {
    expect(method_exists($class, $method))->toBeTrue("{$class}::{$method}() is gone from the public API")
        ->and(Introspection::signature($class, $method))->toBe($signature);
})->with(function (): Generator {
    foreach (Surface::methods() as $class => $signatures) {
        foreach ($signatures as $method => $signature) {
            yield "{$class}::{$method}" => [$class, $method, $signature];
        }
    }
});

it('pins the payload of every event', function (string $event, array $payload): void {
    $reflection = new ReflectionClass($event);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and(array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        ))->toBe($payload);

    foreach ($payload as $property) {
        expect($reflection->getProperty($property)->isPublic())->toBeTrue();
    }
})->with(fn (): Generator => surfaceDataset(Surface::events()));

it('pins the cases of every enum', function (string $enum, array $cases): void {
    $actual = [];

    foreach ($enum::cases() as $case) {
        $actual[$case->name] = $case->value;
    }

    expect($actual)->toBe($cases);
})->with(fn (): Generator => surfaceDataset(Surface::enums()));

it('pins the columns the README describes as readable', function (string $table, array $columns): void {
    $name = config("assignment.tables.{$table}");

    expect($name)->toBeString()
        ->and(Schema::hasColumns((string) $name, $columns))->toBeTrue();
})->with(fn (): Generator => surfaceDataset(Surface::columns()));

it('ships every named exception', function (): void {
    foreach (Surface::exceptions() as $exception) {
        expect(class_exists($exception))->toBeTrue("{$exception} is gone from the public API")
            ->and((new ReflectionClass($exception))->isFinal())->toBeTrue();
    }
});

it('keeps every shipped notification swappable through one base class', function (): void {
    expect((new ReflectionClass(AssignmentNotification::class))->isAbstract())->toBeTrue();

    foreach (Surface::notifications() as $notification) {
        expect(is_subclass_of($notification, AssignmentNotification::class))->toBeTrue();
    }
});

it('ships every named policy behind the selection contract', function (): void {
    foreach (Surface::policies() as $policy) {
        expect(is_subclass_of($policy, SelectionPolicy::class))->toBeTrue("{$policy} no longer implements the policy contract");
    }
});

it('pins the config keys', function (): void {
    $config = config('assignment');

    expect($config)->toBeArray()
        ->and(Introspection::configPaths((array) $config))->toBe(Surface::configKeys());
});

it('points the notification config at the shipped notifications', function (): void {
    expect(array_values((array) config('assignment.notifications')))
        ->toBe(Surface::notifications());
});

it('pins the publish tags', function (string $tag): void {
    expect(ServiceProvider::pathsToPublish(AssignmentServiceProvider::class, $tag))->not->toBeEmpty();
})->with(Surface::publishTags());

it('pins the command name', function (): void {
    expect(Artisan::all())->toHaveKey(Surface::commandName());
});

it('pins the facade surface in both directions', function (): void {
    $annotated = Introspection::facadeAnnotations(AssignmentFacade::class);

    expect($annotated)->toBe(Surface::facadeMethods());

    foreach ($annotated as $method) {
        expect(method_exists(AssignmentManager::class, $method))
            ->toBeTrue("The facade advertises {$method}() and the manager does not have it");
    }
});

it('resolves the facade to the manager', function (): void {
    expect(AssignmentFacade::getFacadeRoot())->toBeInstanceOf(AssignmentManager::class);
});

it('keeps the extension points open and the implementations closed', function (): void {
    expect((new ReflectionClass(AssignmentProfile::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionClass(SelectionPolicy::class))->isInterface())->toBeTrue()
        ->and((new ReflectionClass(Assignment::class))->isFinal())->toBeTrue();
});

it('keeps the slot column out of the model surface', function (): void {
    expect((new Assignment)->getHidden())->toContain('slot');
});

it('marks every class outside the public surface as internal', function (): void {
    $public = Surface::classes();
    $leaked = [];

    foreach (Introspection::sourceClasses() as $class) {
        if (in_array($class, $public, true)) {
            continue;
        }

        $docBlock = (new ReflectionClass($class))->getDocComment();

        if (! is_string($docBlock) || ! str_contains($docBlock, '@internal')) {
            $leaked[] = $class;
        }
    }

    expect($leaked)->toBe([], 'Neither public nor annotated @internal: '.implode(', ', $leaked));
});

it('names only public classes in the README', function (): void {
    $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');

    expect($readme)->toBeString();

    preg_match_all('/ByRcsc\\\\LaravelAssignment\\\\[A-Za-z\\\\]+/', (string) $readme, $matches);

    $named = array_unique($matches[0]);

    expect($named)->not->toBeEmpty();

    foreach ($named as $class) {
        expect(in_array($class, Surface::classes(), true))
            ->toBeTrue("The README documents {$class}, which the public surface does not cover");
    }
});
