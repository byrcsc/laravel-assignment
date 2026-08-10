# Workbench

A bootable demo application that installs `byrcsc/laravel-assignment` the way
a real application would: it publishes the migration, registers profiles in
config, and drives every public verb by hand. It is where a change is walked
through before it is documented.

The demo is a trade services business. Customers send **enquiries**, which are
routed to a **tradie** who works that trade. Booked jobs are **callouts**, and
each one holds two slots at once: a **crew** to do the work, and a **tradie**
supervising it.

## The demo loop

### Seed

```bash
composer build
```

That creates the SQLite database, publishes the package migration, migrates
from scratch, and seeds:

- **7 tradies**, one unavailable: three plumbers and three sparkies on the
  tools, graded 1 to 3.
- **6 crews**, five available: three in `richmond`, two in `fitzroy`.
- **6 enquiries**, alternating between `plumbing` and `electrical`, and
  between emergency and routine.
- **8 callouts**, alternating between the two suburbs.

Three available tradies per trade against three enquiries each means a
rotation comes round more than once, and the two trades rotate on separate
cursors. More callouts per suburb than crews to take them means
`LeastWorkload` has a real spread to choose from. The unavailable tradie and
the unavailable crew give eligibility something to filter.

Run `composer build` again at any point to get back to this state.

### Drive

Every command runs through Testbench:

```bash
vendor/bin/testbench <command>
```

| Command | Drives |
|---|---|
| `demo:status` | Every open assignment, the queue, the workload spread, the notification count |
| `demo:auto enquiry` | `autoAssign()` through `EnquiryProfile`: RoundRobin, one cursor per trade |
| `demo:auto callout --role=crew` | `autoAssign()` in offer mode: LeastWorkload, 120 second TTL |
| `demo:auto callout --role=supervisor` | The second slot on the same callout: FirstAvailable, assigned outright |
| `demo:fluent enquiry --policy=random` | The builder with `Random` |
| `demo:fluent enquiry --policy=closure` | The builder with a closure policy |
| `demo:fluent callout 2 --role=crew --offer` | `expiresIn()` and `offer()` |
| `demo:fluent callout 3 --queue` | `queue()`, parking an assignable at priority 5 |
| `demo:answer <id>` | The assignee accepts |
| `demo:answer <id> --decline` | The assignee declines, and the cascade offers it on in the same request |
| `demo:age-offer <id>` | Backdates `expires_at` so expiry can be watched without waiting |
| `assignment:tick` | Expires overdue offers, advances their cascades, flushes the queue |
| `demo:reassign callout 1 --role=crew` | `reassign()`, ending one row and opening the next atomically |
| `demo:end <id>` | `unassign()` |
| `demo:end <id> --complete` | `complete()` |
| `demo:flush-queue` | `Assignment::flushQueue()` |
| `demo:availability tradies off` | Takes every tradie off the tools, so the next enquiry has no candidates |
| `demo:availability tradies off --trade=plumbing` | Only that trade, so one cursor starves while the other keeps rotating |
| `demo:availability crews on --suburb=richmond` | Puts one suburb's crews back on |

`demo:auto` and `demo:fluent` take an optional id. Leave it off and they pick
the first assignable whose slot is still free.

The queue is the one path that needs setting up, because a profile only
queues when it finds nobody:

```bash
vendor/bin/testbench demo:availability tradies off
vendor/bin/testbench demo:auto enquiry          # no candidates, so it is queued
vendor/bin/testbench demo:availability tradies on
vendor/bin/testbench demo:flush-queue           # re-resolved through the profile
```

Emergency enquiries queue at priority 10 and routine ones at 0, so a flush
drains them in that order rather than by arrival. Queue two of each and
`demo:status` shows the emergency above the routine one that arrived first.

The scheduler runs `assignment:tick` every minute, registered in
`WorkbenchServiceProvider`. Watch it with `vendor/bin/testbench schedule:list`,
or drive the tick by hand rather than waiting for the minute.

### Observe

```bash
vendor/bin/testbench demo:status
```

All four notifications are observable. Mail goes to the log, so tail the
skeleton's `storage/logs/laravel.log`; the database channel writes to the
`notifications` table, which `demo:status` counts.

Two behaviours are worth watching for, because neither is obvious from the
README alone:

- **Exhaustion and the tick meet in one command.** When a cascade runs out of
  candidates the assignable is queued, and `assignment:tick` flushes the queue
  in the same run. A fresh cascade starts there, with everyone eligible again,
  so a crew that declined can be offered the same callout seconds later. That
  is the design, not a bug: the cascade's exclusion list lives for one cascade.
- **A slot frees itself.** After `demo:age-offer` has moved `expires_at` into
  the past, the slot is already free, even though the row still reads
  `offered` and nothing has ended it. The timestamp alone decides; the tick
  only catches the row up afterwards.

### Reset

```bash
composer build   # back to the seeded state
composer clear   # remove the skeleton entirely
```

## What the demo stands in for

A real application publishes `config/assignment.php` and edits it. The demo has
no published config, so `WorkbenchServiceProvider` sets the same two values at
runtime: the profile map, and `assignee_key_type` of `uuid`.

That key type is the point of the uuid-keyed tradies and crews. Assignables
stay integer keyed, so one build exercises both column types on either side of
an assignment.

Tradies and crews are two classes on purpose. The engine never asks what a
candidate is, only that it is a model, and a callout proves it by holding a
crew in one slot and a tradie in the other.

To watch the delayed expiry jobs instead of the tick, set
`assignment.dispatch_expiry_jobs` to `true` in `WorkbenchServiceProvider` and
move `QUEUE_CONNECTION` off `sync` in `workbench/.env`, since a sync queue
runs a delayed job the moment the offer is made. It is set in the provider
rather than read from an `ASSIGNMENT_*` variable because `workbench/.env` is
copied into the skeleton the package test suite boots against, and that suite
asserts the shipped defaults.
