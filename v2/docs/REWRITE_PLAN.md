# V2 rewrite execution contract

**Scope:** complete behavioral replacement with substantially cleaner architecture, not a rename/refactor of existing large PHP files. Production remains V1 until an explicitly approved cutover.

### Existing hot spots confirmed from repository

- `bot_template.php` has ~2,600 lines and conflates Telegram handlers, media, recovery, campaigns, personal sends and profile clock.
- `api.php` dispatches ~500 lines of authenticated HTTP mutations and reads.
- `lib.php` and `runtime.php` combine persistence, authentication, process orchestration and lifecycle projections.
- Production Compose shares the runtime PID namespace between process supervisor and API. Migration to a different topology needs actual Linux testing, not inference.
- Multiple failures in Actions were in integration test client and lifecycle state timing. A green workflow is necessary, never proof of end-to-end Telegram correctness.

### Required vertical slices and evidence

| Slice | Deliverable | Gate |
| --- | --- | --- |
| Architecture | ADRs, contracts, threat model, feature parity matrix | Human-reviewed |
| Core lifecycle | transactional desired/observed state, ownership and fencing | Model-based + Linux crash/fault tests |
| Session and QR | single-owner MadelineProto adapter, QR/2FA, secure IPC | Disposable accounts + authorized live acceptance |
| Telegram behavior | edits, deletes, media, view-once, archives | Synthetic replay + authorized real tests |
| Scheduling | campaign, personal send, profile clock | Virtual clock + restart/duplicate execution |
| Control plane | API validation, session/CSRF, auth and roles | Negative security/integration tests |
| UX | complete accessible Persian dashboard | Contract + browser E2E |
| Ops | deterministic builds, SBOM, scanning, staging and rollback | Docker Compose, performance, failure injection |

### Non-negotiable release gates

- Functional parity matrix with every V1 feature and known edge case accounted for; no silent feature loss.
- Migration from legacy state and sessions is read-only during rehearsal, checksummed before/after, reversible at binary/data level; backups tested off-host.
- Unit, property/model-based, integration, Linux process, true Docker Compose and API contract tests pass.
- Secure operational gates: Gitleaks, image scans, Composer audit, SBOM, pinned digests and config hardening.
- Observability: account-scoped correlation IDs, bounded redacted logs, actionable failure codes, health based on actual readiness.
- Resource limits, media retention, cancellation, backpressure, retry/jitter and no duplicate message sends after restart.
- Staged canary and rollback, plus authorized Telegram login and bot Start/Stop/recovery/QR/2FA verification.
- No prod deployment, changes to existing sessions, or mutation of live private state without explicit approval.

### Immediate next action

Complete a **feature inventory and ADR set**, then implement an independently tested transactional lifecycle repository on top of this pure model. When persistence is added, prove version conflicts and stale worker fencing under concurrent writes. Never wire the experimental domain to `api.php`, `worker.php` or production Compose before the full acceptance gates.
