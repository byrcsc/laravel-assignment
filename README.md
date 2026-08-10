# Laravel Assignment

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-assignment.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-assignment)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-assignment/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-assignment/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-assignment/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-assignment/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-assignment.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-assignment)

Assignment routing for Eloquent models: any model can be assigned, any model
can be an assignee, with atomic slot claims, an offer and accept handshake,
pluggable selection policies, fair per-scope rotation, a priority queue for
work nobody can take yet, and offer cascades that pass unanswered offers to
the next candidate.

The package provides the assignment engine. Your application keeps ownership
of its UI, users, teams, eligibility rules, and what being assigned actually
means: you pass the candidates, the engine picks one fairly and atomically,
records why, and fires plain Laravel events when the state changes.

| Laravel | Tested PHP versions |
|---|---|
| 12.x | 8.3, 8.4 |
| 13.x | 8.3, 8.4 |

Atomic on MySQL, PostgreSQL, and SQLite. Two concurrent calls cannot both
fill the same slot, and round robin cursors cannot skip or repeat a candidate
under load.

## Installation

Install the package and publish its migrations:

```bash
composer require byrcsc/laravel-assignment
php artisan vendor:publish --tag="assignment-migrations"
php artisan migrate
```

Schedule the tick. Timestamps are authoritative, so an expired offer stops
holding its slot whether or not the command has run; the tick is what
advances cascades and flushes the queue.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('assignment:tick')->everyMinute();
```

## What an assignment is

An **assignment** binds one **assignee** (any model: a user, a crew, a team)
to one **assignable** (any model: an enquiry, an order, a ticket, a callout),
optionally under a **role**.

At most one **open** assignment exists per assignable and role, where open
means `offered` or `active`, and the database enforces it rather than
application code. `assign()` creates an active assignment immediately;
`offer()` creates one the assignee must accept or decline, holding the slot
while it stands. Assignments end, they are not deleted: an ended row records
`ended_at` and why, so the rows are the history.

## Quick start

Add `Assignable` to the models that get assigned and `Assignee` to the models
that take assignments:

```php
use ByRcsc\LaravelAssignment\Concerns\Assignable;
use ByRcsc\LaravelAssignment\Concerns\Assignee;

class Enquiry extends Model
{
    use Assignable;
}

class Tradie extends Model
{
    use Assignee;
}
```

Assign manually, read state, and end assignments:

```php
$enquiry->assign($tradie);          // active immediately
$enquiry->assignee();               // the Tradie, or null
$enquiry->reassign($otherTradie);   // ends the old row, creates the new one
$enquiry->unassign();               // ends with reason unassigned

$callout->assign($crew, role: 'crew');
$callout->assignee('crew');         // the Crew
```

Auto-assign with the fluent builder. You bring the candidates, a policy picks
one:

```php
use ByRcsc\LaravelAssignment\Facades\Assignment;
use ByRcsc\LaravelAssignment\Policies\RoundRobin;

Assignment::for($enquiry)
    ->among(Tradie::where('available', true)->get())
    ->scope('trade:'.$enquiry->trade)
    ->using(RoundRobin::class)
    ->assign();
```

Every enquiry from that trade rotates through the same cursor, so tradies
take turns fairly even though you rebuild the candidate list on every call.
Swap `assign()` for `offer()` and the candidate gets a say, with declines and
expiries cascading to the next one.

The builder is the same for both modes:

| Method | Does |
|---|---|
| `Assignment::for($assignable)` | Starts the chain |
| `among($candidates)` | The candidates: a collection, an array, or a closure returning either |
| `role($role)` | Which slot to fill |
| `scope($scope)` | The state key stateful policies rotate on |
| `using($policy)` | A policy class name, an instance, or a closure |
| `by($actor)` | Records the actor in `assigned_by` |
| `expiresIn($seconds)` | Puts an `expires_at` on an offer |
| `assign()` | Creates the assignment, active |
| `offer()` | Creates the offer, pending an answer |
| `queue($priority)` | Parks the assignable in the queue instead |

`assign()` and `offer()` return `null` when the candidate list is empty or
the policy picks nobody. Everything else throws.

## Eligibility is yours, selection is the engine's

The policy list deliberately contains no territory policy and no skill
policy. Who *can* take an assignment (territory, skills, shift, capacity) is
a query on data your application already owns. Who *gets* it, among the
eligible, is what the engine decides. Routing by suburb is therefore a query
plus a scope, not a feature:

```php
Assignment::for($callout)
    ->among(Crew::where('suburb', $callout->suburb)->where('available', true)->get())
    ->role('crew')
    ->scope('suburb:'.$callout->suburb)
    ->using(LeastWorkload::class)
    ->assign();
```

Per-assignee capacity caps work the same way: filter candidates by
`$crew->workload() < 3` before passing them in.

## Selection policies

| Policy | Picks | State |
|---|---|---|
| `RoundRobin` | The next candidate after the last one this scope picked | Cursor per scope |
| `LeastWorkload` | The candidate with the fewest open assignments | None |
| `Random` | Any candidate | None |
| `FirstAvailable` | The first candidate in the order you passed them | None |
| Any closure | Whatever you return | Yours |

`LeastWorkload` counts `active` plus `offered`, so ten unanswered offers
cannot pile onto one idle crew. `FirstAvailable` makes your query order the
priority order. A closure receives the assignable and the candidates, and
returns one candidate:

```php
->using(fn ($enquiry, $candidates) => $candidates->sortByDesc('rating')->first())
```

Return `null` to select nobody. Returning a model that is not in
`$candidates` is an error, so a policy cannot smuggle in an ineligible
assignee. Reusable policies implement `SelectionPolicy`.

## Documentation

The [versioned documentation][documentation] contains the complete setup,
guides, operations advice, and API reference:

- [Installation and setup][installation]
- [Full quick start][quick-start]
- [Selection policies][selection-policies]
- [Offer cascades][offer-cascades]
- [Public API reference][api-reference]

## Out of scope

Things this package will not do, so you can build on what it does do:

- UI: no screens, no Livewire, no Nova resources. The engine is headless.
- Database-defined pools, admin-editable rules, or pool membership tables.
  Eligibility is your query, permanently.
- Approval of assignments. Pair it with
  [byrcsc/laravel-approval](https://github.com/byrcsc/laravel-approval) and
  drive it from this package's events.
- SLA and escalation timers beyond the offer TTL.
- Notifying anyone but the assignee. Managers, watchers, and dashboards
  listen to events.

## Versioning

The package follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

- Upgrading within `1.x` is safe. Nothing you use will break.
- Only a new major version, like `2.0.0`, can break your code.
- If the README or the documentation describes it, it is safe to build on.
  If they don't, treat it as internal and expect it to change.

Bug fixes go into the newest version only. To get a fix, upgrade to it.

## Questions and issues

- **Stuck, or have an idea?** Start a
  [discussion](https://github.com/byrcsc/laravel-assignment/discussions).
  Usage questions and feature ideas both live there.
- **Found a bug you can reproduce?**
  [Open an issue](https://github.com/byrcsc/laravel-assignment/issues). A
  failing test is the fastest way to a fix, and a short reproduction is the
  next best thing.
- **Found a security problem?** Please don't open a public issue. See
  [SECURITY.md](SECURITY.md) for how to report it privately.
- **Planning a pull request?** [CONTRIBUTING.md](CONTRIBUTING.md) covers the
  setup and the three checks it needs to pass.

This package is maintained by one person, so replies can take a while.
Everything gets read.

## License

MIT. See [LICENSE.md](LICENSE.md). Changelog in [CHANGELOG.md](CHANGELOG.md).

[api-reference]: https://docs.rcsc.dev/laravel-assignment/v1/api-reference
[documentation]: https://docs.rcsc.dev/laravel-assignment/v1/introduction
[installation]: https://docs.rcsc.dev/laravel-assignment/v1/installation
[offer-cascades]: https://docs.rcsc.dev/laravel-assignment/v1/offer-cascades
[quick-start]: https://docs.rcsc.dev/laravel-assignment/v1/quick-start
[selection-policies]: https://docs.rcsc.dev/laravel-assignment/v1/selection-policies
