# Changelog

All notable changes to `byrcsc/laravel-assignment` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-11

### Added

- `Assignable` and `Assignee` traits, so any Eloquent model can be assigned
  and any Eloquent model can take assignments.
- At most one open assignment per assignable and role, enforced by a database
  constraint rather than an application check.
- `assign()` for assignments that start active, and `offer()` for assignments
  the assignee has to accept or decline.
- `reassign()`, `unassign()`, and `complete()`, each recording why the row
  ended rather than deleting it.
- An `Assignment` facade exposing `for()`, `autoAssign()`, and
  `flushQueue()`, and a fluent builder behind `for()`: `among()`, `role()`,
  `scope()`, `using()`, `by()`, `expiresIn()`, `assign()`, `offer()`, and
  `queue()`.
- Selection policies `RoundRobin`, `LeastWorkload`, `Random`, and
  `FirstAvailable`, plus any closure, behind the `SelectionPolicy` contract.
- Per-scope policy state, row locked during selection, so round robin cursors
  cannot skip or repeat a turn under concurrency.
- Assignment profiles, one class per assignable model, so assignment can be
  triggered without arguments and re-resolved later from the queue.
- A priority queue for assignables with no candidates, flushed in priority
  order and then first in, first out.
- Offer cascades that pass a declined or expired offer to the next candidate,
  excluding everyone who has already turned it down in the same cascade.
- Lazy expiry: an offer with a past `expires_at` stops holding its slot
  whether or not a scheduled command has run.
- The `assignment:tick` command, and optional delayed jobs for second-level
  expiry precision.
- Events for every transition: `Assigned`, `AssignmentOffered`,
  `AssignmentAccepted`, `AssignmentDeclined`, `OfferExpired`, `Unassigned`,
  `Reassigned`, `AssignableQueued`, and `OffersExhausted`.
- Assignee notifications for offers received, offers expired, assignments
  received, and assignments removed, each swappable or silenceable in config.
- Typed exceptions under one catchable `AssignmentException` root:
  `SlotOccupied`, `AlreadyAssigned`, `NotAssigned`, `NotTheAssignee`,
  `OfferNotOpen`, `InvalidTransition`, and `NoProfile`.
- Configurable table names, and `uuid`, `ulid`, or `string` keys on either
  side of an assignment.
- Publishable config, migration, translations, and mail views, under the
  `assignment-config`, `assignment-migrations`, `assignment-translations`,
  and `assignment-views` tags.
- Support for MySQL, PostgreSQL, and SQLite, with the atomicity guarantees
  covered by a concurrency suite on all three.

[Unreleased]: https://github.com/byrcsc/laravel-assignment/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/byrcsc/laravel-assignment/releases/tag/v1.0.0
