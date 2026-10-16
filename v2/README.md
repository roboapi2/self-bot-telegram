# UBCC V2 — isolated rewrite workspace

**Status: architecture bootstrap only. Not a working replacement or deployable image.**

The existing production application remains the source of truth. Nothing under `v2/` is wired to production entrypoints, Docker images, runtime workers, account files, or deployment scripts. No real user sessions or credentials are read.

## Target architecture

- `Domain/`: immutable state, value objects, explicit invariants, pure transition logic; no PHP globals, network, filesystem or Telegram dependencies.
- `Application/`: typed use cases and ports for account lifecycle, QR authentication, campaigns, archival, messaging, and configuration; transactional intent and idempotency semantics.
- `Infrastructure/Persistence/`: SQLite transactional control-plane store with version/CAS, schema migrations, backup and rollback; separate protected per-account MadelineProto session storage. Choice must be load-tested before adoption.
- `Infrastructure/Processes/`: supervisor, account-scoped leases and fencing tokens, PID birth verification, structured failure codes, retry policies, cancellation and graceful shutdown.
- `Infrastructure/Telegram/`: MadelineProto adapter, isolated session owner, event handler mapping, media streaming, backpressure, rates and reconnection.
- `Interfaces/Http/`: authenticated versioned API; validated typed input, CSRF, authorization, standardized errors, bounded pagination and audit trails.
- `Interfaces/Web/`: isolated dashboard, explicit lifecycle states, accessible UI, API-generated client types.
- `Console/`: health, diagnostics, migrations, reversible dry runs and read-only auditing.

## Architectural invariants

1. One live process owns a given Telegram session; the web API never accesses it directly.
2. Every process interaction is fenced by account ID, generation and opaque run ID. Persist intent and generation **before** side effects.
3. An optimistic view (e.g. process missing) is not durable failure; only a committed failure event produces a retry.
4. Every account update is atomic; retries and commands are idempotent and cannot resurrect a stopped account.
5. Separate desired state from observed state and acknowledgements; readiness means Telegram handler registration succeeded.
6. Never log tokens, sessions, message bodies, QR data, 2FA or environment values. Keep failures classified.
7. Default to least privilege, UID 10001, read-only container root, capability drop, no-new-privileges, private mounts and pinned images.
8. No destructive migration: preserve old data and sessions, keep the old binary independently deployable, dry-run on anonymized copies, support rollback without rewriting old state.
9. Protect secret-bearing media, archive, cache and logs by policy, quotas, retention and account isolation; never publish fixtures with real state.
10. Do not ship or claim feature parity until verified by tests exercising real Linux process behavior and the Telegram integration independently.

## Initial deliverable

`src/Domain/Lifecycle/` contains a small typed **pure** lifecycle machine. It deliberately does **not** spawn PHP, manage files, claim process ownership, touch Telegram or replace the live supervisor. `tests/LifecycleMachineTest.php` provides isolated assertions. Later adapters must implement durable compare-and-swap, session ownership and full-process acknowledgement before this model can be integrated.

To run locally (PHP >=8.3):

```bash
php v2/tests/LifecycleMachineTest.php
```

## Migration plan

1. Document functional inventory and behavioral/API/data compatibility baseline; classify critical paths and security guarantees.
2. Characterization tests around old behavior; replay synthetic events, validate privacy boundaries and capture historical failure cases.
3. Domain/application boundaries, RFCs and design review; threat model, state machine and transaction protocol.
4. Durable transactional store with a **read-only legacy importer** (dry run first), schema versioning, backups, export and downgrade strategy.
5. Isolated process manager and MadelineProto adapter validated in Linux containers with startup/crash/stop/QR and concurrent session tests.
6. Implement feature modules incrementally: recovered deletes/edits and media, archives, campaigns, personal sends, profile clock, chat/history, UI; parallel synthetic differential checks.
7. Staging shadow validation where no second process touches real Telegram sessions, failure injection, SAST/dependency scans, privacy and chaos/load/soak tests.
8. Release candidate with reviewed migration, explicit operator approval, backup and rollback drills; deploy production only when all required gates pass and real Telegram acceptance has been done.

Do not eliminate existing functionality, replace hardened deployment gates, skip tests, or run production migration to accelerate progress. No big-bang production cutover.
