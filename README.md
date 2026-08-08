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
means.

| Laravel | Tested PHP versions |
|---|---|
| 12.x | 8.3, 8.4 |
| 13.x | 8.3, 8.4 |

## Installation

Install the package and publish its migrations:

```bash
composer require byrcsc/laravel-assignment
php artisan vendor:publish --tag="assignment-migrations"
php artisan migrate
```

Publish the configuration before the migration when you need custom table
names or non-integer model keys:

```bash
php artisan vendor:publish --tag="assignment-config"
```

Set `ASSIGNMENT_ASSIGNABLE_KEY_TYPE` and `ASSIGNMENT_ASSIGNEE_KEY_TYPE` to
`uuid`, `ulid`, or `string` when the models on either side of an assignment do
not use integer keys.

Notification wording and its mail views publish separately, and only when you
want to change them:

```bash
php artisan vendor:publish --tag="assignment-translations"
php artisan vendor:publish --tag="assignment-views"
```

## What an assignment is

An **assignment** binds one **assignee** (any model: a user, a driver, a team)
to one **assignable** (any model: a lead, an order, a ticket, a delivery),
optionally under a **role**. The rules are small and strict:

- At most one **open** assignment per assignable and role. Open means
  `offered` or `active`. The role is optional; leave it off and an assignable
  has at most one assignee. Use roles and one delivery holds a driver and a
  seller concurrently. The rule is enforced by the database, not by
  application code.
- Two verbs create assignments. `assign()` creates an `active` assignment
  immediately. `offer()` creates an `offered` assignment that the assignee
  must accept or decline. An open offer holds its slot: nobody else can be
  assigned or offered that slot while it stands.
- Only the assignee an offer targets can accept or decline it.
- Assignments end, they are not deleted. An ended row records `ended_at` and
  an `ended_reason`: `unassigned`, `reassigned`, `declined`, `expired`, or
  `completed`. The rows are the history; there is no separate audit table.
- Timestamps are authoritative. An offer with an `expires_at` in the past no
  longer holds its slot, whether or not any command has run. Scheduled
  commands only advance cascades and flush the queue; they are never required
  for correctness.
- The engine never decides who is eligible. Your application passes the
  candidates; the engine selects among them, fairly and atomically.

## Quick start

Add `Assignable` to the models that get assigned and `Assignee` to the models
that take assignments:

```php
use ByRcsc\LaravelAssignment\Concerns\Assignable;
use ByRcsc\LaravelAssignment\Concerns\Assignee;

class Lead extends Model
{
    use Assignable;
}

class Seller extends Model
{
    use Assignee;
}
```

Assign manually, read state, and end assignments:

```php
$lead->assign($seller);                  // active immediately
$lead->assignee();                       // the Seller, or null
$lead->reassign($otherSeller);           // atomic: ends the old row, creates the new one
$lead->unassign();                       // ends with reason unassigned
$lead->assignments;                      // the full history, oldest first

$delivery->assign($driver, role: 'driver');
$delivery->assign($seller, role: 'seller');
$delivery->assignee('driver');           // the Driver
```

`assign()` on an occupied slot throws; reassignment is an explicit verb.

Auto-assign with the fluent builder. You bring the candidates, a policy picks
one:

```php
use ByRcsc\LaravelAssignment\Facades\Assignment;
use ByRcsc\LaravelAssignment\Policies\RoundRobin;

Assignment::for($lead)
    ->among(Seller::where('active', true)->get())
    ->scope('campaign:'.$lead->campaign_id)
    ->using(RoundRobin::class)
    ->assign();
```

Every lead from that campaign rotates through the same cursor, so sellers take
turns fairly even though you rebuild the candidate list on every call.
Another campaign, another cursor.

## Eligibility is yours, selection is the engine's

The policy list deliberately contains no territory policy and no skill policy.
Who *can* take an assignment (territory, skills, shift, capacity) is a query
on data your application already owns. Who *gets* it, among the eligible, is
what the engine decides. Territory-based assignment is therefore a query plus
a scope, not a feature:

```php
Assignment::for($delivery)
    ->among(Driver::where('territory', $delivery->city)->where('online', true)->get())
    ->role('driver')
    ->scope('territory:'.$delivery->city)
    ->using(LeastWorkload::class)
    ->assign();
```

Per-assignee capacity caps work the same way: filter candidates by
`$driver->workload() < 3` before passing them in.

## Selection policies

| Policy | Picks | State |
|---|---|---|
| `RoundRobin` | The next candidate after the last one this scope picked | Cursor per scope |
| `LeastWorkload` | The candidate with the fewest open assignments | None |
| `Random` | Any candidate | None |
| `FirstAvailable` | The first candidate in the order you passed them | None |
| Any closure | Whatever you return | Yours |

- `LeastWorkload` counts open assignments: `active` plus `offered`, so ten
  unanswered offers cannot pile onto one idle driver. Constrain the count to
  one assignable type with `LeastWorkload::of(Delivery::class)`. Ties break
  toward the least recently assigned candidate, so equal workloads still
  rotate.
- `FirstAvailable` makes your query order the priority order: senior sellers
  first is `orderBy('tier')` and nothing else.
- A closure receives the assignable and the candidates, and returns one
  candidate:

```php
->using(fn ($lead, $candidates) => $candidates->sortByDesc('conversion_rate')->first())
```

Stateful policies key their state on the scope string. Omit `scope()` and the
default is derived from the assignable class and role, which gives one global
rotation per model type for free.

## Offers

Use `offer()` when the assignee gets a say:

```php
$assignment = Assignment::for($delivery)
    ->among($drivers)
    ->role('driver')
    ->using(LeastWorkload::class)
    ->expiresIn(120)
    ->offer();

$driver->accept($assignment);   // offered -> active
$driver->decline($assignment);  // offered -> ended (declined), cascade advances
```

`expiresIn()` puts an `expires_at` on the offer. The instant it passes, the
offer stops holding its slot and counts as `expired` in history. No worker is
required for that to be true.

### The cascade

Declines and expiries do not strand the assignable. The engine re-runs the
selection against the remaining candidates and offers the next one, excluding
everyone who already declined or let an offer expire in this cascade. When the
candidates are exhausted, the assignable is queued and `OffersExhausted`
fires.

Declines cascade immediately. Expiries cascade when the clock is advanced:

```php
// routes/console.php
Schedule::command('assignment:tick')->everyMinute();
```

`assignment:tick` expires overdue offers, advances their cascades, and flushes
the queue. For second-level precision instead of cron granularity, set
`dispatch_expiry_jobs` in the config and the engine dispatches a delayed queue
job per offer as well; the tick remains a safety net.

## Profiles

A profile packages the candidates, policy, scope, and mode for one assignable
model, so assignment can be triggered without arguments, including later, from
the queue, where no fluent chain exists:

```php
use ByRcsc\LaravelAssignment\AssignmentProfile;

class LeadProfile extends AssignmentProfile
{
    public function candidates(Model $lead, ?string $role): iterable
    {
        return Seller::where('active', true)->get();
    }

    public function policy(Model $lead, ?string $role): string
    {
        return RoundRobin::class;
    }

    public function scope(Model $lead, ?string $role): ?string
    {
        return 'campaign:'.$lead->campaign_id;
    }
}
```

Register it and trigger it:

```php
// config/assignment.php
'profiles' => [
    Lead::class => LeadProfile::class,
],
```

```php
$lead->autoAssign();            // candidates, policy, scope all come from the profile
$delivery->autoAssign('driver');
```

Profiles also define the optional behavior: `mode()` returns `Mode::Assign`
(default) or `Mode::Offer`, `offerTtl()` returns the offer lifetime in
seconds, `queueWhenEmpty()` (default true) queues the assignable when there
are no candidates, and `queuePriority()` orders it within the queue.

## The queue

When auto-assignment finds no candidates, the assignable waits instead of
falling on the floor. Queued assignables are flushed in priority order, then
first in, first out. Flushing re-resolves everything through the profile, so
a driver coming online or a seller being activated is picked up by the next
flush:

```php
Assignment::flushQueue();                 // or let assignment:tick do it
$lead->queuedAssignment();                // is it waiting?
```

`AssignableQueued` fires when something enters the queue. Flush from a
listener on your own domain events (driver came online), from the tick, or
both.

## Status and history

An assignment moves through a small state machine: `offered`, then `active`,
then `ended`, with `assign()` starting directly at `active`. Everything that
ever happened to an assignable is its `assignments` relation, ordered by
time: who had it, who declined it, when, and why it moved. Each row carries
`offered_at`, `accepted_at`, `expires_at`, `ended_at`, `ended_reason`, and a
polymorphic `assigned_by` recording who or what created it; null means the
engine did.

```php
$delivery->assignments()->ended()->get();     // past holders
$driver->activeAssignments;                   // current work
$driver->workload();                          // open assignment count
$assignment->complete();                      // ends with reason completed
```

## Notifications

The engine notifies the assignee, nobody else, at the four moments that
concern them: an offer received, an offer they let expire, an assignment
received, an assignment removed. Each notification class is swappable or
removable in the config; set one to `null` to silence it:

```php
'notifications' => [
    'offered'       => OfferReceived::class,
    'offer_expired' => OfferExpired::class,
    'assigned'      => AssignmentReceived::class,
    'unassigned'    => AssignmentRemoved::class,
],
'notification_channels' => ['mail', 'database'],
```

Assignees that are not `Notifiable` (a Team model, for instance) are skipped
silently; fanning out to members is your application's decision. Wording and
mail views publish with the `assignment-translations` and `assignment-views`
tags. Everything beyond the assignee (a manager dashboard, an exhausted-queue
alert) hangs off the events instead.

## Events

The package exposes these lifecycle events; none ship with application
listeners.

| Event | Fires when |
|---|---|
| `Assigned` | An assignment becomes active, by `assign()` or by acceptance |
| `AssignmentOffered` | An offer is created |
| `AssignmentAccepted` | The assignee accepts an offer |
| `AssignmentDeclined` | The assignee declines an offer |
| `OfferExpired` | An overdue offer is expired by tick or job |
| `Unassigned` | An active assignment ends by `unassign()` |
| `Reassigned` | A reassignment replaces one assignee with another |
| `AssignableQueued` | An assignable enters the queue |
| `OffersExhausted` | A cascade runs out of candidates |

## Guarantees

Assignment is atomic on MySQL, PostgreSQL, and SQLite:

- Two concurrent calls cannot both fill the same slot. The open-slot rule is
  a database constraint, and the loser gets an exception, not a duplicate.
- Round robin cursors cannot skip or repeat a candidate under concurrency;
  selection within one scope is serialized, while different scope rows are
  locked independently. SQLite may still serialize writes at the database
  level.

## Out of scope

The package will never grow the following. This section is the standing
answer to feature requests:

- **UI.** No screens, no Livewire, no Nova resources. The engine is headless.
- **Database-defined pools or rules.** Candidate lists, admin-editable
  assignment rules, and pool membership tables belong to your application.
  Eligibility is your query, permanently.
- **Approval of assignments.** The engine does not gate assignments behind
  sign-off. Pair it with
  [byrcsc/laravel-approval](https://github.com/byrcsc/laravel-approval) and
  drive it from this package's events.
- **SLA and escalation timers** beyond the offer TTL. Business deadlines are
  your scheduler's job; the events give you the hooks.
- **Notifying anyone but the assignee.** Managers, watchers, and dashboards
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

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
