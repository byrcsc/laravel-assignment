# Contributing

Thanks for helping. Clear, focused pull requests are easier to review and
maintain.

## Getting set up

```bash
git clone https://github.com/byrcsc/laravel-assignment
cd laravel-assignment
composer install
```

No database server and no `.env` are needed. The suite runs against an in-memory
SQLite database that Testbench sets up for you.

To reproduce a CI matrix failure locally, point the suite at a real engine with
`DB_DRIVER`:

```bash
DB_DRIVER=mysql composer test
DB_DRIVER=pgsql composer test
```

That path exists because SQLite does not exercise production partial-index,
`lockForUpdate()`, or transaction-isolation behavior, and the open-slot rule
depends on all three.

## The three checks

All three must be green before a pull request can be merged. CI runs them too,
but running them locally is faster than waiting.

```bash
composer test      # Pest, random order
composer analyse   # PHPStan, level max
composer format    # Pint, applies fixes
```

Two things worth knowing:

- **PHPStan runs at level max with no baseline.**
  If an error is genuinely a false positive, explain it in the pull request so
  we can find the right fix. Do not add `@phpstan-ignore`, a baseline entry, or
  a cast only to silence it.
- **Pint is the only style authority.** Run `composer format` before pushing.
  Avoid manual formatting that conflicts with its output.

The suite runs in random order and fails on warnings, risky tests, and an empty
suite. A test that passes only in a particular order is a bug in the test.

## The workbench

`workbench/` is a bootable demo application that installs the package the way a
real application would. It is where a change is driven by hand before it is
documented.

```bash
composer build    # set the demo up
composer clear    # tear it down again
```

See [workbench/README.md](workbench/README.md) for the demo loop. It is excluded
from the Composer dist archive and is not covered by CI, so it may drift. If you
change a public seam, run it and fix what broke.

## Where tests go

Group tests by behavior, not by class, and extend the group that owns the
behavior rather than adding a parallel suite for a class. `tests/ArchTest.php`
currently enforces strict types and rejects leftover debugging calls.

The package is in active construction, so the group layout is still forming.
When you add the first test for an area, name the directory after the behavior
(`Assign/`, `Offer/`, `Select/`, `Lifecycle/`) rather than after the class you
happened to write. Architecture checks are added with the surfaces they can
enforce; string-backed enums and a single exception root are required once
those types exist.

## What a good change looks like

**Touching the open-slot rule.** At most one open assignment per assignable and
role is enforced by a database constraint, not by application code, and that is
deliberate. A change that moves the check into PHP is a change to the package's
central promise. It needs to be argued for, and it needs a concurrency test on
MySQL and PostgreSQL, because SQLite will let a broken version pass.

**Touching selection.** Round robin cursors must not skip or repeat a candidate
under concurrency. Selection within one scope is serialized and different scopes
must not block each other, so a change here needs a test that races two scopes
as well as one that races within a scope.

**Touching expiry.** Timestamps are authoritative: an offer with an `expires_at`
in the past no longer holds its slot, whether or not any command has run.
Scheduled commands advance cascades and flush the queue; they are never required
for correctness. Do not add a code path that makes them required.

**Adding an ended reason.** Assignments end, they are not deleted, and
`ended_reason` is the history. A new way for an assignment to end needs a new
reason and an event, not a silent reuse of an existing one.

**Fixing behavior.** Please include a test that fails before the change.

## Language

The package uses one canonical vocabulary: an **assignee** is bound to an
**assignable**, optionally under a **role**. An assignment is **offered**,
**active**, or ended with an **ended reason**. `assign()` and `offer()` are the
two verbs. It does not use tickets, owners, claims, or allocations.

Documentation and docblocks state behavior, constraints, and tradeoffs directly.
Avoid conversational and anthropomorphic framing.

## Package scope

The following are deliberate boundaries rather than gaps, and the README's
**Out of scope** section is the full list. Explain any proposed change to them
in the pull request:

- **No UI.** No screens, no Livewire, no Nova resources. The engine is headless.
- **No database-defined pools or rules.** Candidate lists, admin-editable
  assignment rules, and pool membership tables belong to the application.
  Eligibility is your query, permanently.
- **No approval of assignments.** Pair with
  [byrcsc/laravel-approval](https://github.com/byrcsc/laravel-approval) and
  drive it from this package's events.
- **No SLA or escalation timers** beyond the offer TTL.
- **No notifying anyone but the assignee.** Managers, watchers, and dashboards
  listen to events.
- **No compatibility aliases or deprecation shims.** A removed name is removed
  in the major release that removes it, and documented in the changelog.

## Commits and branches

Branch off `main` as `feat/…`, `fix/…`, `docs/…`, `refactor/…`, or `chore/…`,
and write [Conventional Commits](https://www.conventionalcommits.org/)
(`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`). Mark breaking changes with
`!`, which is what decides whether a release is a major one.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
