# Security Policy

## Reporting a vulnerability

Please report privately, not in a public issue. Use GitHub's
[private vulnerability reporting](https://github.com/byrcsc/laravel-assignment/security/advisories/new)
on this repository; it opens a channel visible only to the maintainers.

Include the package version, the Laravel and PHP versions, the database driver,
and enough detail to reproduce. If it helps, a failing test is the clearest
possible report.

You can expect an acknowledgement within a week. Because this is a
single-maintainer package, please do not expect a same-day response; if the
issue is being actively exploited, say so in the title.

## Supported versions

Security fixes are released for the latest package version and are not
backported, and neither are ordinary bug fixes. Only the current major receives
either; when a new major is released, the previous one stops receiving fixes.
Keep your dependency constraint current.

## What this package does and does not protect

This package decides which candidate gets a slot, and records what happened to
every assignment. It is a concurrency and history component. It is deliberately
not an authorization component, and that distinction is the whole boundary.

It **does**:

- enforce at most one open assignment per assignable and role with a database
  constraint rather than an application check, so two concurrent callers cannot
  both fill the same slot. The loser gets an exception, never a duplicate;
- check that only the assignee an offer targets can accept or decline it, so a
  pending offer cannot be answered by a different model;
- serialize round robin selection within a scope, so cursors cannot skip or
  repeat a candidate under concurrency, while leaving different scopes free to
  proceed in parallel;
- treat `expires_at` as authoritative rather than derived, so an expired offer
  stops holding its slot whether or not any scheduled command has run;
- end assignments rather than delete them, recording `ended_at` and an
  `ended_reason`, so the rows are the history.

It **does not**:

- decide who is eligible. The engine never asks whether a candidate should be
  in the pool. Your application passes the candidates and the engine selects
  among them, so passing an ineligible model results in an assignment, not an
  error. Every permission, role, tenancy, and business-rule check happens
  before the call, permanently and by design.
- authenticate the caller. `assign()` and `offer()` are ordinary method calls
  with no policy behind them. Exposing either to user input without your own
  authorization is the application's bug, not the package's.
- protect assignment rows from modification. These are ordinary Eloquent models
  with no append-only enforcement and no hash chain. Anything able to write to
  the database can change `ended_at`, `ended_reason`, or `expires_at` and
  thereby free or hold a slot. The row history is tamper-evident against
  nothing; it is a record, not an audit trail.
- provide an audit trail. There is no separate transition table, so an
  overwritten field leaves no trace of its previous value. If you need
  attributable history, listen to the events and write it yourself.
- scope assignments to a tenant. Assignable and assignee are polymorphic
  references in one global namespace; any tenant scoping is the application's
  query, including the candidate list you pass in.
- gate assignments behind approval, or notify anyone but the assignee. Both are
  out of scope and are the application's to build on the events.

## Reporting something that is documented

The list above is the intended boundary, and the README's **Eligibility is
yours, selection is the engine's**, **Guarantees**, and **Out of scope**
sections state it in full. A report that a documented limitation exists is not
a vulnerability, but a report that the package does not actually hold a line it
claims to hold very much is. The clearest example of the latter would be a
sequence producing two open assignments on the same assignable and role, or an
offer accepted by a model it was not addressed to. When in doubt, report it.
