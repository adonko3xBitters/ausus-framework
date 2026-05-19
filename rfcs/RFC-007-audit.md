# RFC-007 — Audit subsystem

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-03 (incl. Amendment-01), RFC-002 Draft, RFC-003 Draft |
| Supersedes    | —                                                      |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |

---

## 0. Problem statement

RFC-001 §1.1.7 puts the Audit Spine in the Kernel: the Kernel requires emission but does not store. RFC-001 §8.3 (post-Amendment-01) fixes the Audit Entry payload shape but does not specify how the bytes get from the Invoker to a sink. Amendment-01 §A-1.6 sets the primary/secondary failure contract — primary failure aborts the Action, secondary failure queues for retry — but leaves the acknowledgement protocol, the retry mechanics, the ordering guarantees, the orphan-cleanup story, the dedup strategy, the redaction enforcement, and the correlation propagation undefined. RFC-002 §17.7 explicitly hands the orphan question to this RFC. RFC-003 §10.5 adds the `Elevation` slot to the Audit Entry shape and lists RFC-007 acceptance as a downstream gate.

This RFC closes all of those gaps. It defines:

- The `Auditor` L2 contract the Invoker calls.
- The `AuditSink` L3 plugin contract, separated into PRIMARY and SECONDARY roles.
- The two acknowledgement protocols: same-connection (in-transaction) and external (two-phase).
- The delivery-guarantee commitment: at-least-once with EntryId-based dedup, escalating to effectively-exactly-once on conforming sinks.
- Ordering: per-CorrelationId monotonic Sequence; cross-Correlation ordering is best-effort.
- Correlation, Trace, and Elevation chain propagation across Invoker calls, queues, and elevated contexts.
- The retry model (exponential backoff, bounded attempts, dead-letter sink).
- The orphan-reconciliation contract for external primary sinks.
- BulkSubject on-wire serialization.
- Redaction enforcement (global patterns and Field-level annotation), with conformance rules.
- Read-side opt-in audit emission.

The audit log is the load-bearing compliance artifact of the entire platform. Every promise the Kernel makes about Tenancy, Policies, and mutation traceability terminates here. The contracts in this RFC are non-negotiable parts of the V1 surface.

---

## 1. Scope and inherited constraints

### 1.1 Inherited

1. The Kernel requires emission; the Kernel does not store (RFC-001 §1.1.7).
2. The Audit Entry shape is fixed by Amendment-01 §A-1.8: `EntryId` and `Sequence` are added by this RFC for delivery and ordering, and the `Elevation` slot is fixed by RFC-003 §10.5.
3. Sink failure semantics: primary failure aborts via PersistenceDriver rollback (RFC-002 §7.3); secondary failure queues for retry (Amendment-01 §A-1.6).
4. Audit log is append-only (RFC-001 §8.3): no update, no delete, on any first-party sink.
5. Reads are not audited by default; per-Entity opt-in is permitted (RFC-001 §8.3).
6. The Invoker is the sole authorized caller of the Auditor for mutating Actions (Amendment-01 §A-1.4 + this RFC §3.2).
7. Redaction patterns at the global level live in `config/ausus.php` (RFC-001 §5.4, restated by Amendment-01 §A-1.6).
8. The single-driver-per-deployment constraint (RFC-002 §14) means transactional primary sinks share exactly one underlying connection.

### 1.2 Out of scope

- The choice of backing store for any specific sink (database, S3, Kafka, SIEM). RFC-007 specifies the contracts; sink authors choose backing stores.
- Audit log analytics, search UI, or retention dashboards. Those are downstream consumers of the log.
- SIEM integration protocols (CEF, LEEF, OCSF, etc.). A sink implementation may target them; the protocols themselves are not part of the Kernel contract.
- Cryptographic signing of audit entries (tamper-evidence). A V1 sink MAY implement signing as a value-add; the contract neither requires nor prohibits it.
- Long-term archival, cold storage migration. Sink-internal.
- Encryption at rest. Sink-internal.

---

## 2. Audit Entry shape (normative)

### 2.1 Wire shape

```
AuditEntry := {
  entryId:           string,                         // UUID v7; assigned by Auditor
  sequence:          int,                            // monotonic per correlationId
  actor:             ActorRef,
  tenant:            string,                         // active TenantId.value()
  actionFqn:         string,
  subject:           SingleSubject | BulkSubject,    // per A-1.8
  inputs:            object,                         // redacted per §14
  outputs:           object,                         // redacted per §14
  timestamp:         string,                         // RFC 3339 UTC, micro-precision
  correlationId:     string,                         // UUID v7; propagated per §9
  traceId:           string | null,                  // W3C trace ID if present
  invocationClass:   "Standard" | "Maintenance",
  elevation:         null | ElevationRef,            // RFC-003 §10.5
  emitterVersion:    string                          // kernel version that emitted
}

ActorRef := {
  type:              "user" | "system" | "service",
  id:                string,                         // identity within type
  homeTenant:        string                          // TenantId of actor's home
}

SingleSubject := {
  kind:              "single",
  tenant_id:         string,
  entity_fqn:        string,
  identity_handle:   string
}

BulkSubject := {
  kind:              "bulk",
  tenant_id:         string,
  entity_fqn:        string,
  affected_count:    int,
  sample_handles:    string[]                        // bounded; sink-configurable, default 100
}

ElevationRef := {
  from:              string,                         // origin TenantId
  reason:            string,
  elevateCorrelationId: string                       // CorrelationId of the elevate grant entry
}
```

`entryId`, `sequence`, and `emitterVersion` are RFC-007 additions on top of Amendment-01 §A-1.8 + RFC-003 §10.5. They are mandatory and part of the V1 surface.

### 2.2 Invariants

1. `entryId` is unique across the entire log. UUID v7 is normative for first-party emission to provide time-ordered hashing without coordination.
2. `sequence` starts at 0 for the first entry under a `correlationId` and increments by 1 for each subsequent entry sharing that `correlationId`. The Auditor maintains the counter per-process; persistence across process restarts is not required (a fresh process restarts at 0 for a new correlation; correlations do not span processes in V1).
3. `timestamp` is UTC. Local times are forbidden. Micro-precision is required to make tie-breaking on `(correlationId, sequence)` unnecessary.
4. `subject.kind` is the discriminator. Standard Actions emit `kind: "single"`; Maintenance Actions emit `kind: "bulk"`. Mixing within a single entry is forbidden.
5. `inputs` and `outputs` are JSON objects, never `null`. Empty inputs/outputs are `{}`.
6. `emitterVersion` is the kernel version that produced the entry. Sinks use this for replay compatibility when reading entries written by older kernels.

### 2.3 Append-only

The wire format has no `updatedAt` field, no `version`, no `state`. Once emitted, an entry is immutable. Sinks that need to express "this entry was later identified as erroneous" do so via a separate compensating entry, never by modifying the original.

---

## 3. `Auditor` contract (L2 Runtime)

### 3.1 Contract

```
interface Auditor
{
  function emit(AuditEntry $entry, ?TransactionHandle $txn): EmissionOutcome;
}

final class EmissionOutcome
{
  function primaryAcked(): bool;
  function secondaryDispatched(): SecondaryDispatchRef[];
}

final class SecondaryDispatchRef
{
  function sinkName(): string;
  function queuedEntryId(): string;          // retry-queue identifier
}
```

### 3.2 Authorized caller

The Auditor is called only by:

1. The **Invoker** (Amendment-01 §A-1.4) for mutating Actions. The Invoker constructs the entry, calls `emit`, and uses the outcome to decide commit/rollback per Amendment-01 §A-1.6.
2. The **Repository** (RFC-002 §5) for read operations on Entities that have opted into read auditing (§15).
3. The **Kernel's own bootstrap** for `kernel.tenant.elevate` and `kernel.tenant.elevate_close` audit entries (RFC-003 §10.2).

Plugins MUST NOT call the Auditor directly. The Auditor binding in the container is private to L2. Plugin attempts to acquire the Auditor via the container raise `UnauthorizedAuditorAccess` at boot via static analysis.

### 3.3 Effect of `emit`

`emit(entry, txn)` performs, in order:

1. Assigns `entryId` (UUID v7) if not already set.
2. Assigns `sequence` from the per-process counter keyed by `entry.correlationId`.
3. Applies redaction (§14).
4. Submits to the primary sink:
   - If the primary sink is `Transactional` (§5.1) AND `txn` is non-null AND the sink's connection equals the driver's transaction connection, the primary sink writes inside `txn`. ACK is implicit at commit time; for the purposes of `emit`'s return, the write is "tentatively acked" — the actual commit/rollback decision still rests with the Invoker.
   - If the primary sink is `External` (§5.2), the Auditor invokes the sink's `prepare(entry)` (§6.2). The sink returns an ack or a failure.
5. On primary failure (External case) OR on Transactional sink raising a non-recoverable error during the in-tx insert, `emit` returns `primaryAcked: false`. The Invoker rolls back per Amendment-01 §A-1.6.
6. On primary success, enqueues a copy for each secondary sink via the retry queue (§11), returning a `SecondaryDispatchRef` per secondary. Secondaries are processed asynchronously; their success or failure does not affect the Action.

### 3.4 The Auditor does not own the transaction

`txn` is passed in, never created by the Auditor. The Auditor uses it for primary-sink in-tx writes; the Invoker owns the lifecycle per RFC-002 §7.

### 3.5 Idempotency

`emit` is NOT idempotent on retry by the caller. Reissue with the same `entryId` is a logic error; the Invoker MUST NOT call `emit` twice for the same Action. The Auditor MAY assert.

Sink-side dedup by `entryId` is a separate mechanism (§7.2) handled by sinks, not by the Auditor.

---

## 4. `AuditSink` contract (L3 plugin)

### 4.1 Common contract

```
interface AuditSink
{
  function name(): string;                          // FQN; e.g. "ausus.audit.database"
  function role(): SinkRole;                        // PRIMARY | SECONDARY
  function kind(): SinkKind;                        // TRANSACTIONAL | EXTERNAL
  function capabilities(): SinkCapabilities;
}

enum SinkRole { PRIMARY, SECONDARY }
enum SinkKind { TRANSACTIONAL, EXTERNAL }

final class SinkCapabilities
{
  function supportsDedupByEntryId(): bool;
  function maxSampleHandles(): int;                 // hard upper bound for BulkSubject sample_handles
  function maxInputsBytes(): int;
  function maxOutputsBytes(): int;
  function preservesInsertionOrder(): bool;         // affects §8
}
```

### 4.2 Transactional sink

```
interface TransactionalSink extends AuditSink
{
  function writeInTransaction(AuditEntry $entry, TransactionHandle $txn): void;
}
```

A `TRANSACTIONAL` sink shares the underlying driver connection and is invoked inside the Invoker's data transaction. ACK is implicit: if `writeInTransaction` returns, the entry is part of the transaction. Rollback rolls the entry back too. There is no orphan possible.

`writeInTransaction` MAY raise `SinkRejected` for permanent failures (constraint violation, malformed payload). The Auditor reports primary failure on the Invoker.

### 4.3 External sink

```
interface ExternalSink extends AuditSink
{
  function prepare(AuditEntry $entry): PreparedHandle;
  function confirm(PreparedHandle $handle): void;
  function cancel(PreparedHandle $handle, string $reason): void;
}

final class PreparedHandle
{
  function entryId(): string;
  function sinkInternal(): string;          // opaque to the Kernel
}
```

An `EXTERNAL` sink does NOT share the driver connection. It uses a three-phase protocol:

1. `prepare(entry)` writes a provisional entry to the external system. The sink MUST be able to identify this entry later by its `entryId` for confirmation or cancellation.
2. `confirm(handle)` finalizes the entry: marks it durable, visible, queryable.
3. `cancel(handle, reason)` invalidates the entry: a confirming reader MUST treat cancelled entries as if they were never written.

The Auditor calls `prepare` synchronously during `emit`; the Invoker calls `confirm` after data-transaction commit succeeds; the Invoker calls `cancel` after data-transaction rollback (including post-`emit` rollback driven by other reasons). The orphan-reconciliation contract (§12) handles the case where neither `confirm` nor `cancel` is delivered.

### 4.4 Secondary sink

A `SECONDARY` sink MAY be `TRANSACTIONAL` or `EXTERNAL`. The Auditor never invokes a secondary synchronously; secondary delivery is always via the retry queue (§11), even for the first attempt. This guarantees that secondary failure cannot affect an Action's commit decision.

### 4.5 No `update`, no `delete`

The `AuditSink` interface has no method for updating or deleting an existing entry. Append-only is enforced at the contract level.

### 4.6 Capability advertisement

`capabilities()` is invariant for the process. Misadvertisement (e.g., claiming `supportsDedupByEntryId: true` while letting duplicates through) is a conformance failure detected by the §22 conformance test.

---

## 5. Primary and secondary sinks

### 5.1 Configuration

Per Amendment-01 §A-1.6, the audit configuration declares:

```
audit:
  primary_sink:        ausus.audit.database         # exactly one
  secondary_sinks:     []                           # zero or more
  redact:              []                           # see §14
```

The Kernel rejects boot if `primary_sink` is unset.

### 5.2 Primary uniqueness

Exactly one primary sink may be configured. Multiple primaries are explicitly forbidden because they multiply the chance of an Action being aborted by a sink unavailable for reasons unrelated to the data path.

### 5.3 Recommended default

The recommended deployment default is a Transactional primary sink that writes to the same database the bound PersistenceDriver uses (e.g., a `kernel_audit_log` table). This makes orphans architecturally impossible.

External primaries (Kafka, SIEM-direct, S3) are supported but operationally costlier (§12). A deployment selecting an External primary explicitly accepts the orphan-reconciliation operational load.

### 5.4 Secondary purposes

Secondary sinks exist for fan-out to compliance systems, SIEMs, cold storage, and analytics pipelines. Failure of a secondary does not stop the platform.

### 5.5 The retry queue is a Kernel responsibility

The retry queue (§11) is provided by the Kernel, persisted in `system`-Tenant storage via the bound PersistenceDriver. Secondary sinks consume it via the contract in §11; they do not implement their own queue.

---

## 6. Acknowledgement protocols

### 6.1 Transactional ACK

For a Transactional primary:

```
Invoker:                 driver.beginTransaction(tenant) → txn
Invoker:                 effect(ctx, subject, inputs)            // mutations in txn
Invoker:                 auditor.emit(entry, txn)                // ⇨ sink.writeInTransaction(entry, txn)
   Auditor:              applies §14 redaction
   Auditor:              calls sink.writeInTransaction(entry, txn)
   Sink:                 INSERT INTO kernel_audit_log ... within txn
   Auditor:              returns EmissionOutcome(primaryAcked = true)
Invoker:                 driver.commit(txn)                      // both visible together
```

ACK semantics: the entry is part of the same transaction. Commit makes both data and audit durable simultaneously; rollback removes both. No orphan.

### 6.2 External ACK (two-phase from the Auditor's view, three-phase including Invoker commit)

For an External primary:

```
Invoker:                 driver.beginTransaction(tenant) → txn
Invoker:                 effect(ctx, subject, inputs)            // mutations in txn
Invoker:                 auditor.emit(entry, txn)
   Auditor:              applies §14 redaction
   Auditor:              calls sink.prepare(entry) → PreparedHandle
   Sink:                 writes entry with provisional marker, returns handle
   Auditor:              returns EmissionOutcome(primaryAcked = true)
                         and stashes handle in the Invoker-scoped context
Invoker:                 driver.commit(txn) → on success:
                         → auditor.confirmPending(txn)
                            → sink.confirm(handle)
                            → if confirm fails: enqueue confirmation retry via §11
                         on failure:
                         → auditor.cancelPending(txn)
                            → sink.cancel(handle, reason)
                            → if cancel fails: enqueue cancellation retry via §11
```

The Auditor maintains a per-transaction list of External primary handles needing confirmation or cancellation. The Invoker calls `confirmPending` after successful data commit; `cancelPending` after rollback.

If `confirm` itself fails (e.g., network failure to the External sink), the handle is enqueued and a retry worker (§11) reissues. Until confirmation succeeds, the entry is in `prepared-but-not-confirmed` state in the External sink. Readers MUST treat such entries as not-yet-visible. The orphan-reconciliation contract (§12) governs entries that stay in this state past a TTL.

### 6.3 Timeout

`prepare` MUST honor a deployment-configurable timeout (default 5s). Timeout = primary failure = Invoker aborts. The configuration is `audit.primary_ack_timeout_ms`.

For Transactional sinks, the timeout is the driver's transaction timeout itself — no separate audit timeout.

### 6.4 Auditor MUST NOT block on secondaries

Secondary writes are enqueued and processed asynchronously by the retry worker. `emit` returns as soon as the primary ACKs.

---

## 7. Delivery guarantees

### 7.1 Commitment

| Side | Guarantee |
|------|-----------|
| Transactional primary | Exactly-once (atomic with data) |
| External primary | At-least-once on prepare/confirm; effectively-exactly-once when sink advertises `supportsDedupByEntryId` and dedups by `entryId` |
| Secondary | At-least-once with retries; effectively-exactly-once when sink dedups by `entryId` |
| Per-correlation ordering | Strict, when consumed through a sink that preserves insertion order; reconstructible via `(correlationId, sequence)` otherwise |

### 7.2 Dedup by `entryId`

Sinks SHOULD support dedup by `entryId`. The Auditor MAY emit a duplicate `entryId` only under one scenario: external-primary confirmation retry where the sink's internal state is unclear after a network partition. Sinks that dedup treat the duplicate as a no-op; sinks that do not dedup produce a duplicate row that downstream readers must reconcile.

A sink advertising `supportsDedupByEntryId: false` MUST document the at-least-once behaviour and the deduplication strategy (typically: a downstream consumer dedups by `entryId`).

### 7.3 No "exactly-once" claim across systems

The Auditor does not claim exactly-once across heterogeneous sinks. The honest commitment is at-least-once with dedup; conforming sinks elevate to effectively-exactly-once on their side.

### 7.4 Visibility ordering across primaries and secondaries

A secondary may write an entry before the primary's data has committed (if the secondary processes its queue quickly) OR after (if the primary commits fast and the secondary lags). There is no cross-sink visibility ordering guarantee. Consumers correlating across sinks MUST do so by `entryId` and by `timestamp` / `(correlationId, sequence)`, not by sink-local insertion order.

---

## 8. Ordering guarantees

### 8.1 Per-CorrelationId

All entries sharing a `correlationId` carry monotonically increasing `sequence` values starting at 0. A consumer can reconstruct in-correlation order by sorting on `sequence`. This is the strongest ordering commitment.

### 8.2 Cross-Correlation

Cross-Correlation ordering is governed by `timestamp` only. Two entries from different correlations may interleave; their relative order is the wall-clock order at emit time, with micro-precision tie-breaking required.

### 8.3 Per-sink insertion order

A sink advertising `preservesInsertionOrder: true` writes entries in the order the Auditor submits them. For Transactional primary sinks this is naturally the case (single transaction, single insert order). For External and secondary sinks it depends on the sink's storage.

Consumers that require strict ordering MUST sort by `(correlationId, sequence)` after reading, regardless of sink-side insertion order.

### 8.4 No total ordering

A total order across all entries from all Actions is not guaranteed. Operationally, `timestamp` is "good enough" for human review; programmatic ordering across correlations is not part of the V1 contract.

---

## 9. Correlation and Trace propagation

### 9.1 CorrelationId scope

A `correlationId` covers a single Action-invocation chain:

- A top-level Action invocation (from HTTP, CLI, queue, scheduled — RFC-003 §11) starts a new `correlationId`.
- Actions invoked from within an Action's effect (RFC-001 §8.2.1 nested invocation) inherit the parent's `correlationId`.
- The Auditor's `sequence` counter is keyed by `correlationId` and increments for each entry under that correlation.

### 9.2 TraceId scope

A `traceId` covers a broader span: an entire end-to-end request that may cross many Actions and even many services. The TraceId comes from outside the Kernel:

- For HTTP, the W3C `traceparent` header (RFC-005 binds).
- For queue jobs, the dispatcher captures the active TraceId and stores it in the job payload (alongside the `__ausus_tenant` field of RFC-003 §11.3).
- For scheduled jobs, the scheduler generates a fresh TraceId per invocation.

The TraceId is propagated through every Audit Entry emitted during the traced span. The Kernel does not generate TraceIds; it carries them.

### 9.3 Cross-process correlation

`correlationId` is process-scoped in V1: a job dispatched from process A and executed in process B does NOT share a `correlationId`. It SHARES a `traceId` (via the dispatcher-captured payload field) but starts a fresh `correlationId` on the consumer side.

This is intentional: per-process sequence counters need no coordination, and the TraceId provides the cross-process link. Consumers wanting cross-process Action grouping use `traceId`.

### 9.4 Elevation chain

Per RFC-003 §10.2, `Ausus::elevate(target, reason)` emits an `kernel.tenant.elevate` Audit Entry (the "elevation grant") and opens an `ElevatedContext`. Every subsequent Audit Entry emitted within the elevated scope MUST carry an `elevation` slot referencing the elevation grant's `correlationId`:

```
elevation: {
  from:                  "<origin TenantId>",
  reason:                "<reason string>",
  elevateCorrelationId:  "<correlationId of kernel.tenant.elevate entry>"
}
```

The elevation grant entry's own `elevation` slot is `null` (it is the root of the elevation chain).

### 9.5 No nested elevation in V1

Per RFC-003 §10.3, nested elevation is forbidden. The Audit Entry shape therefore does not need to express a chain of multiple ancestors; the elevation slot is single-level.

### 9.6 Reserved field path on queue payloads

The dispatcher (RFC-003 §11.3) reserves `__ausus_tenant` for Tenant restoration. This RFC additionally reserves `__ausus_trace` for TraceId carry. Plugin job payloads MUST NOT use either name.

---

## 10. Elevation entries

### 10.1 Two entries per elevation window

For every `Ausus::elevate(target, reason)` invocation:

1. Open: `kernel.tenant.elevate` Audit Entry, emitted before the elevated scope executes. Subject = `SingleSubject` referencing the target Tenant's catalog row (`kernel.tenant`, `target_id`). `elevation` slot is `null` (the grant is its own root).
2. Close: `kernel.tenant.elevate_close` Audit Entry, emitted when the elevated scope closes (normal completion OR thrown exception). Same correlation as the open. `elevation` slot is `null` here as well — the close entry is part of the grant's correlation, not of any operation inside the scope.

Both entries are emitted via the Auditor like any other; primary ACK applies to the open entry (failure denies elevation), and to the close entry (failure leaves the elevation open in audit terms — see §12.5).

### 10.2 Operations inside the scope

Audit entries emitted by Actions executed inside the elevated scope carry:

- Their own correlationId (a normal Action correlation).
- The `elevation` slot referencing the grant's correlationId.
- The `tenant` field set to the **target** Tenant.

### 10.3 Read auditing inside elevation

If a read-audited Entity (§15) is read during elevation, the read audit entry carries the elevation slot. Read entries in elevated scope are not silently dropped.

---

## 11. Retry model and dead-letter

### 11.1 Retry queue contract

```
interface AuditRetryQueue
{
  function enqueue(QueuedEntry $q): string;          // returns queuedEntryId
  function reserve(int $maxBatch): QueuedEntry[];    // workers pull batches
  function ack(string $queuedEntryId): void;
  function nack(string $queuedEntryId, string $reason): void;  // bumps attempt counter
  function deadLetter(string $queuedEntryId, string $reason): void;
}

final class QueuedEntry
{
  function id(): string;                             // queuedEntryId
  function sinkName(): string;                       // target sink
  function operation(): RetryOperation;
  function entry(): AuditEntry;
  function preparedHandle(): ?PreparedHandle;        // for confirm/cancel retries
  function attemptCount(): int;
  function firstEnqueuedAt(): string;                // RFC 3339
  function lastAttemptAt(): ?string;
}

enum RetryOperation {
  WRITE,                  // secondary delivery
  CONFIRM_EXTERNAL,       // external-primary post-commit confirm (§6.2)
  CANCEL_EXTERNAL         // external-primary post-rollback cancel (§6.2)
}
```

The retry queue is a Kernel-managed Entity (`kernel.audit_retry_queue`) stored in `system` scope through the bound PersistenceDriver. Queue mutations execute through the Invoker (per RFC-001 §2.4: Actions are the only mutation path).

### 11.2 Worker

The Kernel registers a scheduled MaintenanceAction `kernel.audit.retry_worker` that runs at deployment-configured intervals (default every 30s). The worker:

1. Reserves a bounded batch (default 50 items) per sink.
2. For each item: invokes the sink's `emit` / `retry` / `confirm` / `cancel` as appropriate.
3. On success: `ack`.
4. On failure: `nack`, which increments the attempt counter.
5. If attempt counter exceeds `audit.retry_max_attempts` (default 100): `deadLetter` with reason "max attempts exceeded".

### 11.3 Backoff

Exponential backoff with jitter:

```
nextAttemptAt = lastAttemptAt + min(
    base * (2 ^ attemptCount),
    max_delay
) + uniform_jitter(0, base)
```

Default `base = 1000ms`, `max_delay = 1h`. Workers MUST NOT process items whose `nextAttemptAt` is in the future.

### 11.4 Dead-letter

Items that exceed `audit.retry_max_attempts` are moved to a tombstone state: `dead_letter`. They remain in the queue table (still append-only — dead-letter is a state, not a deletion) and emit an `kernel.audit.dead_letter` Audit Entry of their own. Operators receive notification through deployment-configured channels (alerting plugins; out of RFC-007 scope).

A dead-lettered item MAY be manually re-attempted via `kernel.audit.retry_dead_letter` (a `MaintenanceAction`). This is the only escape from dead-letter.

### 11.5 Queue persistence

The queue persists across process restarts because it lives in the PersistenceDriver. A process crash mid-retry leaves the item in `reserved` state with a reservation expiry (default 5 minutes); the next worker reclaims expired reservations.

### 11.6 Throughput bound

The queue is intentionally simple. A deployment with high audit secondary fan-out (e.g., per-Action SIEM mirroring) may exceed a single-process queue's throughput. The worker count is deployment-configured (default 1; scaled by running additional worker processes). Each worker reserves a disjoint slice via the queue's `reserve` semantics (reservation tokens partition the queue).

---

## 12. Orphan reconciliation (External primary)

### 12.1 The orphan

For an External primary, three failure shapes produce orphan-like states:

1. `prepare` succeeds, data commit succeeds, `confirm` fails permanently.
2. `prepare` succeeds, data commit fails, `cancel` fails permanently.
3. `prepare` succeeds, the kernel process crashes before commit/rollback, no `confirm` or `cancel` ever issued.

Cases 1 and 2 enter the retry queue (§6.2). Case 3 is the true orphan: an external entry in `prepared` state with no follow-up.

### 12.2 Reconciliation contract

External primary sinks MUST implement:

```
interface ExternalSinkWithReconciliation extends ExternalSink
{
  function listPreparedOlderThan(string $rfc3339): PreparedHandle[];
  function reconcileFromKernel(PreparedHandle $handle, ReconciliationDecision $decision): void;
}

enum ReconciliationDecision { CONFIRM, CANCEL }
```

A Kernel-managed scheduled Action `kernel.audit.reconcile_external_primary` runs at deployment-configured intervals (default every 5 minutes). The Action:

1. Calls `listPreparedOlderThan(now - audit.reconcile_window)` on the External primary (`audit.reconcile_window` default: 1 hour).
2. For each handle: looks up the Action's data side via the `entryId` → Action invocation trace. The Kernel maintains a side table `kernel.audit_pending` that records (entryId, transactionId, status) for in-flight External-primary emissions.
3. Decides: if data commit is recorded, instruct `confirm`. If data rollback is recorded, instruct `cancel`. If neither is recorded (process died mid-flight), instruct `cancel` (safer to drop than to confirm uncommitted data).
4. Updates `kernel.audit_pending` to reflect the reconciliation.

### 12.3 The `kernel.audit_pending` table

```
kernel.audit_pending {
  entryId:           string,         // PK
  correlationId:     string,
  txnFingerprint:    string,         // opaque driver-internal txn identifier
  preparedHandle:    json,           // serialized PreparedHandle
  preparedAt:        timestamp,
  status:            "pending" | "confirmed" | "cancelled" | "orphan",
  resolvedAt:        timestamp | null
}
```

The Auditor writes `pending` immediately after `prepare`. The Invoker updates `confirmed` or `cancelled` after data commit/rollback. The reconciler resolves `pending` rows older than the reconciliation window.

This table is itself in `system` scope, persisted via the PersistenceDriver, append-only at the entryId level (status transitions are recorded; no deletion).

### 12.4 Transactional primary requires no reconciliation

A Transactional primary cannot orphan: the audit and the data share a transaction. The `kernel.audit_pending` table is unused for Transactional primaries; the Kernel skips writes to it.

### 12.5 Elevation close orphan

If `kernel.tenant.elevate_close` fails to emit (External primary failure during the close), the elevation window is *audited as open*. The reconciler emits a `kernel.tenant.elevate_close_late` entry at reconciliation time, restoring the close audit. The original close is also retried via §11.

---

## 13. BulkSubject serialization

### 13.1 Sample bound

`sample_handles` is bounded. The bound for a given sink is the smaller of:

- The Kernel default: 100.
- The sink's `SinkCapabilities::maxSampleHandles()`.
- The deployment config `audit.max_sample_handles` (defaults to 100).

The Auditor truncates to the minimum at serialization time. Truncation is recorded in the entry's `outputs.bulk_truncated: true` to signal that `sample_handles` is a proper subset of the affected set.

### 13.2 Sample selection

Sample selection is deterministic, drawn from the head of the affected-set iterator returned by the PersistenceDriver's bulk operation (RFC-002 §11.2 `BulkResult::sampleHandles()`). The Auditor does not reshuffle; the sample reflects the driver's natural iteration order.

If forensic value requires a stratified or randomized sample, this is a future RFC; V1 takes the driver's first-N.

### 13.3 `affected_count`

`affected_count` is the exact count reported by `BulkResult::affectedCount()`. The Auditor does not approximate. For partial-failure bulk operations (which RFC-002 §11.6 forbids in V1 inside Invoker transactions), `affected_count` reflects the successful-commit count, which equals the all-or-nothing total under the V1 constraint.

### 13.4 Wire encoding

`sample_handles` is a JSON array of strings. Each string is the opaque value of `IdentityHandle::value()` per RFC-002 §6.1. Sinks MUST NOT parse the handle structure.

### 13.5 Audit-driven scaling

Maintenance Actions affecting millions of Subjects produce one Audit Entry, not millions. The single-entry overhead is bounded by the sample bound. A SIEM ingesting audit can pivot from `affected_count + sample_handles` to a full subject list by querying the originating Tenant's data via a separate reconciliation path; the audit log does not duplicate that data.

---

## 14. Redaction enforcement

### 14.1 Two layers

V1 supports two redaction sources, applied in order:

1. **Global patterns** from `audit.redact` config (RFC-001 §5.4). Glob patterns matched against input/output key paths.
2. **Field annotations**: Fields marked `sensitive: true` in the FieldDescriptor.

This RFC adds the `sensitive: true` annotation to FieldDescriptor as a downstream change to RFC-001 (flagged in §19 as a dependency on an RFC-001 follow-up amendment).

### 14.2 Pattern grammar

Global patterns are glob expressions over JSON key paths:

```
"*.password"              # any field named "password" at any depth
"*.token"
"billing.invoice.notes"   # specific FQN
"creditCard.*"            # all children of creditCard
```

Pattern matching is performed before serialization to a sink. The Auditor walks `inputs` and `outputs`, replacing matched values with the constant string `"[REDACTED]"`.

### 14.3 Annotation enforcement

When the Action's `inputs` schema references a Field marked `sensitive: true`, the value at that key path is replaced with `"[REDACTED]"` regardless of whether the global pattern catches it. Annotation is a per-Field declaration that travels with the Field across all Actions that touch it.

### 14.4 Redaction marker

The marker is the literal string `"[REDACTED]"` for V1. The marker is part of the V1 wire surface; changing it is a minor bump.

A future RFC MAY introduce typed markers (e.g., `{ "$redacted": true, "reason": "sensitive-field-annotation" }`) for downstream tooling. V1 keeps the marker a primitive string to avoid downstream consumers parsing redaction structure.

### 14.5 Redaction does not protect against side-channel leakage in `outputs`

If an Action returns a derived value computed from a sensitive Field (e.g., `password_strength_score`), redaction does not catch it. Redaction is a Field-name + path-pattern mechanism, not a taint-tracking mechanism. Plugin authors are responsible for avoiding derived leaks in outputs.

### 14.6 Cannot be opted out per Action

Per Amendment-01 §A-1.6 / RFC-001 §8.3, mutating Actions cannot opt out of audit emission; they can only narrow what is recorded via redaction. A plugin MUST NOT define a per-Action "skip redaction" flag. Per-Action redaction can only *add* to the global+annotation set, never remove.

V1 ships a per-Action additive redaction in the Action descriptor:

```
Action::make('issue')->redactInputs(['comments', 'tax_id'])
```

This is union'd with global patterns and Field annotations. There is no subtraction.

### 14.7 Subject is never redacted

`subject.identity_handle`, `subject.tenant_id`, `subject.entity_fqn`, and BulkSubject's `affected_count` / `sample_handles` are NEVER redacted. The audit log requires a Subject to be auditable. Sensitive identifiers belong in `inputs` / `outputs` and are subject to §14.1–14.4; the Subject itself is the auditing key.

---

## 15. Read-side opt-in

### 15.1 Entity opt-in

A FieldDescriptor (per RFC-001 §2.1 Entity) MAY declare `audited_reads: true`. When set, every read operation on the Entity emits an Audit Entry with:

- `actionFqn = "kernel.entity.read"`
- `invocationClass = "Standard"`
- `subject = SingleSubject(read target's reference)`
- `inputs = { "filter": <filter descriptor> }` for `findMany` / `iterate`, or `{ "ref": <reference> }` for `find`
- `outputs = { "found": bool, "rowCount": int }` — never the actual data

The Auditor's `kernel.entity.read` is not an Action in the kernel's Action registry; it is a sentinel FQN reserved for read audits.

### 15.2 Read auditor

The Repository (RFC-002 §5) checks the Entity's `audited_reads` flag and calls the Auditor on read success or failure. Per §3.2, the Repository is an authorized caller of the Auditor.

### 15.3 Read audit primary failure

If the primary sink fails on a read audit emission, the read operation MUST fail with `AuditEmissionFailed`. The Repository surfaces this as a read failure; the caller sees a `PersistenceError` of type `AuditEmissionFailed` (added to the RFC-002 §12.1 taxonomy as a downstream change).

This means audited-read Entities depend on a healthy primary sink for read availability. Operators MUST size the primary accordingly.

### 15.4 No bulk read audit

`Repository::iterate` over an audited-read Entity produces one Audit Entry per chunk fetched, not one per row. The chunk audit's `outputs.rowCount` reflects the chunk size.

### 15.5 Cost note

Audited reads are expensive. The flag should be set sparingly, typically on Entities holding regulated data (PII, PHI, financial records).

---

## 16. Configuration

### 16.1 `config/ausus.php` keys

```
audit:
  primary_sink:           ausus.audit.database
  secondary_sinks:        []
  redact:                 []
  primary_ack_timeout_ms: 5000
  retry_max_attempts:     100
  retry_base_ms:          1000
  retry_max_delay_ms:     3600000
  max_sample_handles:     100
  reconcile_window:       "1 hour"
  reconcile_interval:     "5 minutes"
  retry_worker_interval:  "30 seconds"
  retry_reservation_ttl:  "5 minutes"
```

### 16.2 Boot-time validation

`ausus:doctor` MUST verify:

- `primary_sink` is set and refers to a registered sink.
- The configured primary's `role()` returns `PRIMARY`.
- Every `secondary_sink` is registered and `role()` returns `SECONDARY`.
- If the primary's `kind()` is `EXTERNAL`, the `kernel.audit_pending` table exists.
- All retry configurations parse as positive durations / integers.

Failure aborts boot.

---

## 17. Alternatives considered

### 17.1 Audit-as-first-class-Action

Treat every Audit Entry as an Action invocation through the Invoker. **Rejected:** infinite regress (audit emissions would themselves emit audit), and adds Policy-chain evaluation overhead to every audit.

### 17.2 Push-based secondary delivery without a queue

Have the Auditor synchronously push to every secondary. **Rejected:** would make Action latency the sum of all secondary latencies, and secondary failures would surface as Action failures (contradicting Amendment-01 §A-1.6).

### 17.3 Single audit log shared across sinks via fan-out

Write one canonical log; sinks are projections. **Rejected:** ties the sink contract to a specific canonical store and defeats the "any sink" plug-in model. The retry queue already serves the fan-out role at a lower coupling.

### 17.4 Exactly-once semantics via distributed two-phase commit

Coordinate external sinks via XA / 2PC with the PersistenceDriver. **Rejected:** few stores support 2PC well; the operational cost is enormous; the recommended in-tx primary already gives exactly-once for the common case. The §6.2 three-phase protocol (prepare / commit-data / confirm) is the honest compromise.

### 17.5 Audit emission asynchronous from the Action

Mutate first, audit later. **Rejected:** violates Amendment-01 §A-1.6's commit-only-after-audit-ack invariant. Compliance-driven environments require audit to gate mutation, not lag it.

### 17.6 Per-Action audit opt-out

Allow a mutating Action to declare `audited: false`. **Rejected** explicitly by RFC-001 §8.3 and restated here. The only narrowing is redaction.

### 17.7 SequenceId from a global counter

Use a single global monotonic sequence across all entries. **Rejected:** requires coordination on every emit; per-correlation counters serve the actual use case (in-correlation ordering) without coordination.

### 17.8 Cryptographic chaining of entries (hash chains)

Tamper-evidence via Merkle / hash-chain. **Considered, deferred.** Useful for tamper detection but adds emission cost. A sink MAY implement it; the Kernel contract does not.

### 17.9 Reads emit asynchronously

Optimize audited reads by enqueueing audit to the retry queue instead of synchronous primary write. **Rejected:** an audited read whose audit was queued and lost would mean the read happened without an audit trail. The compliance promise requires synchronous primary ACK for audited reads too.

---

## 18. Trade-offs

1. **In-tx primary is the recommended default**, biasing first-party support toward database-shared sinks. External primaries are supported but operationally heavier (§12). Accepted.
2. **At-least-once with dedup** is the honest commitment; sinks that don't dedup produce duplicate downstream rows. Accepted; documented at every relevant section.
3. **Per-CorrelationId ordering only**; no total order. Accepted; correlation is the unit of operational reasoning.
4. **`__ausus_trace` and `__ausus_tenant` are reserved field paths** on queue payloads. Modest constraint; conformance gate at registration.
5. **Audited reads block on the primary sink**, multiplying read latency. Accepted; the flag is sparse by design.
6. **The retry worker is a scheduled MaintenanceAction**, sharing the worker mechanics of RFC-003 §11.4. Operationally simpler than a custom daemon; latency-bounded by the schedule interval.
7. **Process-scoped `correlationId`** is simpler than cross-process. TraceId carries the cross-process link.
8. **Redaction marker is a primitive string** (`"[REDACTED]"`), forcing downstream consumers to recognize the literal. Accepted; structured markers are post-V1.
9. **Field-level `sensitive: true` annotation requires an extension to RFC-001 §2.2.** Surfaced as a dependency for RFC-001 follow-up (§19.1).

---

## 19. Open questions and downstream impacts

### 19.1 RFC-001 follow-up amendment

This RFC introduces:

- `entryId`, `sequence`, `emitterVersion` on the Audit Entry shape (additive; covered by Amendment-01 §A-1.8's sum type extensibility).
- `sensitive: true` annotation on FieldDescriptor (§14.3). This is a small addition to RFC-001 §2.2 that requires a follow-up amendment to RFC-001 to be normative.
- `AuditEmissionFailed` error in the RFC-002 §12.1 taxonomy (§15.3).

The amendments are minor and additive; no contradiction surfaces. They are scheduled for an Amendment-02 to RFC-001 (or Amendment-01 to RFC-002).

### 19.2 RFC-009 (telemetry)

The retry queue, dead-letter rate, primary-ack latency, and reconciliation lag are operational metrics RFC-009 will need to expose.

### 19.3 Post-V1 — Hash-chain tamper-evidence

Cryptographic chaining for tamper detection. Adds emission cost; deferred.

### 19.4 Post-V1 — Typed redaction markers

Structured `{ "$redacted": true, "reason": "..." }` markers in place of the V1 primitive string.

### 19.5 Post-V1 — Per-Entity sample selection strategy

Stratified or randomized sample selection for BulkSubject in place of driver-iteration-order head.

### 19.6 Post-V1 — Audit log search and retention plugins

Out of RFC-007 scope; downstream of the audit log surface this RFC defines.

### 19.7 Cross-process `correlationId`

If a deployment requires correlation across processes (rare; most use TraceId), a post-V1 RFC may define propagation. V1 commits to process-scoped only.

### 19.8 Confirmation-retry exhaustion for External primary

If `confirm` fails permanently after the retry budget exhausts, the External primary holds a `prepared` entry whose data is committed in the driver but unconfirmed in the sink. The reconciler (§12.2) eventually issues `confirm` again; if the External sink has GC'd the prepared entry, the reconciler emits a `kernel.audit.confirm_lost` entry recording the loss. Operators are notified. This is the worst-case loss surface; no V1 mechanism prevents it. Choosing a Transactional primary avoids the entire class.

---

## 20. Challenger review — attack matrix

Each contract is attacked against: **layer violations**, **tenancy bypass**, **audit bypass**, **delivery loss**, **dedup failure**, **ordering loss**, **redaction bypass**, **SemVer traps**.

### 20.1 `Auditor` (§3)

| Attack | Defence |
|---|---|
| Layer violation: a plugin acquires the Auditor and emits a forged entry. | §3.2: container binding is private to L2. Static analysis on the kernel contracts package forbids plugin imports of `Ausus\Kernel\Runtime\Auditor`. |
| Tenancy bypass: caller passes a different `tenant` than the active context. | The Auditor validates that `entry.tenant == active TenantContext.tenant.value()`; mismatch raises `AuditTenantMismatch`. |
| Audit bypass: caller skips Auditor entirely. | Mutations go through the Invoker (Amendment-01 §A-1.4); the Invoker emits audit unconditionally. Mutations outside the Invoker are architecturally impossible (no plugin can construct a `PersistenceContext` itself per RFC-002 §4). |
| Delivery loss: primary `emit` returns success but the row was never inserted. | Transactional sinks insert inside the data tx; commit either commits both or neither. External sinks use prepare/confirm/cancel; orphan reconciliation (§12) catches misses. |
| Dedup failure: same `entryId` reused. | `entryId` is UUID v7, assigned per-emission. Reuse is a logic error in the Invoker; §3.5 says the Auditor MAY assert. |
| Ordering loss: `sequence` reset across processes. | §2.2 sequence is per-process keyed by correlationId; cross-process spans use new correlations (§9.3). |
| Redaction bypass: caller passes pre-redacted inputs that include sensitive material. | §14: redaction is applied by the Auditor; pre-redacted inputs are simply re-redacted (idempotent). The Auditor does not trust caller-side redaction. |
| SemVer trap: adding a new mandatory field to AuditEntry. | Mandatory fields require a major bump. Optional fields are minor (existing sinks ignore). §2.1's shape is closed for V1. |

### 20.2 `AuditSink` (§4)

| Attack | Defence |
|---|---|
| Layer violation: sink reaches into Runtime to inspect Tenant Context. | Sink is L3; it consumes the AuditEntry passed to it, nothing else. Static analysis rejects Runtime imports. |
| Tenancy bypass: sink misroutes entries to a Tenant-shared store. | Sink-internal. The contract requires entries to be stored as-is; routing decisions are sink implementation. Conformance test verifies entries-read-back-by-tenant-filter return correct results. |
| Audit bypass: sink silently drops entries. | `writeInTransaction` / `prepare` MUST raise on failure; silent drops are a conformance failure detected by §22 tests. |
| Delivery loss: External sink confirms then GC's the prepared entry before retention. | Sink-internal retention is the sink's contract; the Kernel cannot enforce it. Operators choose sinks with adequate retention. |
| Dedup failure: sink advertises `supportsDedupByEntryId: true` but doesn't dedup. | Misadvertisement is a §4.6 conformance failure. |
| Ordering loss: sink reorders within a correlation. | Consumers reconstruct order via `(correlationId, sequence)` regardless of sink-side order. |
| Redaction bypass: sink stores unredacted values it received. | Auditor redacts BEFORE submission (§14). The sink never sees unredacted sensitive data. |
| SemVer trap: adding a fourth `RetryOperation` value. | Sealed enum; new values require new RFC and major bump. |

### 20.3 Primary/secondary protocol (§5, §6)

| Attack | Defence |
|---|---|
| Layer violation: deployment configures two primaries. | §5.2: rejected at boot. |
| Tenancy bypass: a secondary inherits a different Tenant than primary. | Auditor enqueues the same entry for every secondary. Tenant cannot differ. |
| Audit bypass: a deployment configures zero primaries. | §5.1 + §16.2: boot fails. |
| Delivery loss: primary ACKs but data commit fails on External primary; reconciler also fails. | §12.5 / §19.8: `kernel.audit.confirm_lost` recorded. Acknowledged worst-case for External primaries; Transactional primaries don't have this surface. |
| Dedup failure: primary's `prepare` is retried under network partition and writes duplicate. | Sinks SHOULD dedup by `entryId`. Sinks that don't produce duplicates that downstream readers reconcile. |
| Ordering loss: secondary processes its queue out of order due to backoff. | Consumers reconstruct order; secondary-side order is best-effort. |
| Redaction bypass: a secondary stores raw inputs that primary redacted. | Both primary and secondary receive the same redacted entry; redaction happens once in the Auditor. |
| SemVer trap: shifting recommendation from in-tx primary to external. | Operational guidance, not contract. Recommendation can be revised without surface change. |

### 20.4 Delivery guarantees (§7)

| Attack | Defence |
|---|---|
| Layer violation: a plugin attempts to dedupe by something other than entryId. | Dedup is sink-internal; plugins outside sinks have no dedup role. |
| Tenancy bypass | n/a; delivery is per-entry. |
| Audit bypass: caller assumes exactly-once and counts on no duplicates. | §7.3: honest at-least-once commitment. Plugins relying on exactly-once MUST use entryId dedup. |
| Delivery loss | §7.1 table is the commitment. Operators choose configurations matching their compliance tolerance. |
| Dedup failure | §7.2: documented and enforced via §4.6. |
| Ordering loss | §8: per-CorrelationId only. |
| Redaction bypass | n/a; redaction is orthogonal. |
| SemVer trap: tightening the commitment from at-least-once to exactly-once. | Would require sink contract changes; major bump. Not promised. |

### 20.5 Ordering (§8)

| Attack | Defence |
|---|---|
| Layer violation | Ordering is consumer-side; no layer concern. |
| Tenancy bypass | n/a. |
| Audit bypass | n/a. |
| Delivery loss | n/a. |
| Dedup failure | n/a. |
| Ordering loss: process A and process B emit under the same correlationId. | §9.3: correlationId is process-scoped; same correlationId across processes is forbidden by the propagation rules. The dispatcher always starts a fresh correlationId on the consumer side. |
| Redaction bypass | n/a. |
| SemVer trap: promising total order in V1.x. | Major bump; current contract excludes total order. |

### 20.6 Correlation, Trace, Elevation (§9, §10)

| Attack | Defence |
|---|---|
| Layer violation: a plugin emits an entry with a fabricated traceId. | TraceId comes from the framework's request context; plugins cannot forge it without bypassing the Invoker (which they can't, §3.2). |
| Tenancy bypass: elevated entries omit the elevation slot. | The Auditor reads from the active ElevatedContext (RFC-003); plugins cannot construct ElevatedContext. Omission is impossible. |
| Audit bypass: nested elevation forges a chain. | §9.5 / RFC-003 §10.3: nested elevation forbidden; second `Ausus::elevate` raises `ElevationAlreadyActive`. |
| Delivery loss: elevation close entry lost. | §12.5: reconciler emits `kernel.tenant.elevate_close_late`; original close retried via §11. |
| Dedup failure | EntryId dedup applies. |
| Ordering loss: elevated operations interleave with non-elevated. | Each correlation is independent; `(correlationId, sequence)` reconstructs within-correlation order. Cross-correlation interleaving is by `timestamp`. |
| Redaction bypass: elevation reason carries sensitive content. | Per §14: `elevation.reason` is part of the entry payload but not redacted by default. Operators using sensitive reasons SHOULD add reason-redaction patterns. Consider this a known gap; a future RFC may add a dedicated reason-redaction rule. |
| SemVer trap: changing the Elevation slot shape. | Sealed shape per RFC-003 §10.5 + §2.1 here. Major bump required. |

### 20.7 Retry and dead-letter (§11)

| Attack | Defence |
|---|---|
| Layer violation: a plugin reaches into the retry queue to mutate state. | The queue is a `system`-scoped Entity; mutations go through Kernel-registered Actions (RFC-001 §2.4). |
| Tenancy bypass: a queued entry for Tenant A is delivered as Tenant B. | The entry payload carries `entry.tenant`; the sink's call uses that field. Misuse is a sink conformance failure. |
| Audit bypass: queue worker silently drops items. | `ack` requires sink success; `nack` increments attempt. Dead-letter is itself audited (`kernel.audit.dead_letter`). |
| Delivery loss: worker crashes after sink success but before `ack`. | Reservation TTL (§11.5) reclaims the item; the sink's dedup on entryId prevents duplicate. Sinks without dedup duplicate; documented in §7.2. |
| Dedup failure: same item processed by two workers concurrently. | Reservation contract: each worker reserves disjoint items (§11.6). Concurrent reservations are partitioned; double-processing is an implementation bug detected at scale. |
| Ordering loss: items processed out of enqueue order under backoff. | Per-CorrelationId ordering is preserved at the consumer side via `(correlationId, sequence)`. |
| Redaction bypass | Queued entries are already redacted; retry does not unredact. |
| SemVer trap: changing retry config defaults. | Operational tuning; not contract. |

### 20.8 Orphan reconciliation (§12)

| Attack | Defence |
|---|---|
| Layer violation: reconciler reaches into sink-internal state. | Reconciler invokes `listPreparedOlderThan` and `reconcileFromKernel` — both L3 contracts. No layer crossing. |
| Tenancy bypass: reconciler resolves an orphan for the wrong Tenant. | `kernel.audit_pending` carries `correlationId` and `txnFingerprint`, which the reconciler maps to a Tenant via the catalog. Cross-Tenant misresolution requires both fingerprint collision AND Tenant lookup failure — extreme tail. |
| Audit bypass: orphan stays in `pending` indefinitely. | Reconciliation interval (default 5 min) bounds the staleness. Items past the window are resolved. |
| Delivery loss: reconciler decides `cancel` for an entry whose data actually committed. | Decision tree (§12.2): if `kernel.audit_pending` records `confirmed`, reconciler issues `confirm`; if `cancelled`, issues `cancel`; if `pending` (mid-flight crash), issues `cancel`. The cautious default may discard valid audits for committed data. This is the documented worst case for External primaries. Operators choosing External primaries accept it. |
| Dedup failure | EntryId dedup; reconciler calls `confirm`/`cancel` with the original handle. |
| Ordering loss | Reconciliation does not assert ordering across resolutions. |
| Redaction bypass | n/a; reconciler does not modify entries. |
| SemVer trap: `ReconciliationDecision` enum extension. | Sealed; new values require major bump. |

### 20.9 BulkSubject serialization (§13)

| Attack | Defence |
|---|---|
| Layer violation: caller selects samples from outside the driver's iterator. | §13.2: sample comes from `BulkResult::sampleHandles()`. Auditor does not reshuffle. |
| Tenancy bypass | `BulkSubject.tenant_id` matches the active Tenant; verified by Auditor (§20.1). |
| Audit bypass: caller emits Standard subject for a Maintenance Action to hide the count. | The Invoker emits per InvocationClass; Maintenance Actions get BulkSubject by construction. |
| Delivery loss: sample handles truncated. | §13.1: truncation is recorded in `outputs.bulk_truncated`. |
| Dedup failure | EntryId dedup applies. |
| Ordering loss | n/a; sample is unordered set. |
| Redaction bypass: a sample handle contains sensitive substring (e.g., email-as-id). | Handles are opaque per RFC-002 §6.1. If an identity handle is naturally sensitive (e.g., raw email), the driver should hash before storing — but the Kernel does not enforce this. Identified as a downstream concern for plugin authors. |
| SemVer trap: changing default sample bound from 100. | Operational tuning, configurable. Not contract. |

### 20.10 Redaction (§14)

| Attack | Defence |
|---|---|
| Layer violation: a plugin disables redaction at runtime. | §14.6: per-Action redaction is additive only. No subtraction API exists. |
| Tenancy bypass | Redaction is content-level, not Tenant-level. |
| Audit bypass: Field annotation `sensitive: true` is removed at runtime. | Field annotations are descriptor-time declarations; runtime mutation is forbidden by §5.8 (no I/O / domain logic at definition time, and descriptors are immutable in the compiled graph per RFC-001 §4.3). |
| Delivery loss | n/a. |
| Dedup failure | n/a. |
| Ordering loss | n/a. |
| Redaction bypass: derived output that recomputes sensitive value. | §14.5: documented as out-of-scope. Plugin authors are responsible. |
| Redaction bypass: pattern misses (e.g., `Password` not matching `*.password`). | Glob matching is case-sensitive by default; case-insensitive is configurable. Documented in pattern grammar. |
| SemVer trap: marker string change. | §14.4: marker is part of V1 surface; change is minor (downstream tooling tolerant via dual recognition). |

### 20.11 Read-side opt-in (§15)

| Attack | Defence |
|---|---|
| Layer violation: an L4 caller invokes Repository reads on an audited Entity and audit fails to emit. | §15.3: the Repository surfaces `AuditEmissionFailed`; L4 handles. |
| Tenancy bypass: audited read across Tenants. | Read happens inside Tenant Context; audit entry carries the active Tenant. |
| Audit bypass: caller bypasses Repository to read storage directly. | Per RFC-002 §3.2.5, plugins cannot import Eloquent directly; reads outside the Repository violate that and are caught by static analysis. |
| Delivery loss: chunked iteration emits audits per chunk; a chunk audit lost means a chunk of reads is unaudited. | Primary ACK is required per chunk; primary failure aborts the chunk fetch. Acceptable cost for the use case (audited reads are sparse). |
| Dedup failure | EntryId dedup. |
| Ordering loss | Each chunk audit is its own entry with its own sequence; in-correlation order is preserved. |
| Redaction bypass: `outputs.rowCount` reveals existence of restricted rows. | §15.1: outputs include only `found` and `rowCount`, never row data. RowCount is a count, not a leak of which rows — but it leaks aggregate existence. Acknowledged; this is the design intent of read auditing. |
| SemVer trap: changing `actionFqn` for reads. | Sentinel `kernel.entity.read` is part of V1 surface. |

---

## 21. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §2, §3, §4, §6, §7, §11, §12, §14.
2. RFC-001 Amendment-02 (or equivalent) commits to adding `entryId`, `sequence`, `emitterVersion` to AuditEntry and `sensitive: true` to FieldDescriptor.
3. RFC-002 Amendment-01 (or equivalent) commits to adding `AuditEmissionFailed` to the PersistenceError taxonomy.
4. RFC-003 confirms the `Elevation` slot integration in §10.5 matches §10.1 of this RFC.
5. RFC-009 inherits the audit metrics surface in §19.2.
6. A conformance test suite is scoped (not built) before V1: at minimum one test per "MUST" clause in §2, §3, §4, §6, §7, §11, §12, §14, §15.
7. Appendix C re-run before each subsequent draft.

Once accepted, this RFC is the source of truth for the audit subsystem.

---

## Appendix A — V1 public surface enumeration

```
Ausus\Kernel\Contracts\Audit\
  AuditEntry                          (final value object)
  ActorRef, SingleSubject, BulkSubject, ElevationRef
                                      (final value objects; sum-type members)
  EmissionOutcome                     (final value object)
  SecondaryDispatchRef                (final value object)
  Auditor                             (interface; L2; private to Runtime container)

  AuditSink                           (interface; L3 base)
  TransactionalSink                   (interface extends AuditSink)
  ExternalSink                        (interface extends AuditSink)
  ExternalSinkWithReconciliation      (interface extends ExternalSink)
  PreparedHandle                      (final value object)
  SinkRole, SinkKind                  (closed enums)
  SinkCapabilities                    (final value object)

  AuditRetryQueue                     (interface)
  QueuedEntry                         (final value object)
  RetryOperation                      (closed enum)
  ReconciliationDecision              (closed enum)

Ausus\Kernel\Contracts\Audit\Errors\
  AuditError                          (abstract)
  AuditTenantMismatch
  UnauthorizedAuditorAccess
  AuditEmissionFailed                 (also added to RFC-002 §12.1)
  SinkRejected
  SinkUnreachable
  RetryReservationConflict

Ausus\Kernel\Actions\Audit\           (Kernel-registered Action FQNs)
  kernel.audit.retry_worker           (scheduled MaintenanceAction)
  kernel.audit.reconcile_external_primary
  kernel.audit.dead_letter            (emitted; not invokable)
  kernel.audit.retry_dead_letter      (MaintenanceAction)
  kernel.audit.confirm_lost           (emitted; not invokable)

  kernel.entity.read                  (sentinel FQN for read audits; not an Action)

Reserved Action namespace:            kernel.audit.*  (no plugin may register)
Reserved field paths on payloads:     __ausus_tenant (RFC-003), __ausus_trace
Reserved redaction marker:            "[REDACTED]"
```

Anything not enumerated is not part of the V1 surface.

---

## Appendix B — Worked emission sequences

### B.1 Transactional primary, no secondaries

```
Invoker:                begin tx
Action effect:          insert invoice row
Invoker:                auditor.emit(entry, tx)
  Auditor:              redact, assign entryId, sequence
  Auditor:              sink.writeInTransaction(entry, tx)
  Sink (db):            insert into kernel_audit_log within tx
  Auditor:              returns EmissionOutcome(primaryAcked=true, secondaryDispatched=[])
Invoker:                tx.commit() → both rows visible
```

### B.2 External primary, with reconciliation

```
Invoker:                begin tx
Action effect:          insert invoice row
Invoker:                auditor.emit(entry, tx)
  Auditor:              redact, assign entryId, sequence
  Auditor:              sink.prepare(entry) → handle
  Sink (kafka):         publish with provisional marker, ack
  Auditor:              persist kernel.audit_pending row (pending)
  Auditor:              returns EmissionOutcome(primaryAcked=true, ...)
Invoker:                tx.commit() succeeds
Invoker:                auditor.confirmPending(tx)
  Auditor:              sink.confirm(handle); kernel.audit_pending → confirmed
                        on confirm failure: enqueue CONFIRM_EXTERNAL
```

### B.3 External primary, post-prepare process crash

```
Invoker:                begin tx
Action effect:          insert invoice row
Invoker:                auditor.emit(entry, tx)
  Auditor:              sink.prepare(entry) → handle
  Auditor:              persist kernel.audit_pending (pending)
  Auditor:              returns OK
Invoker:                tx.commit()    ← process crashes here
... time passes ...
kernel.audit.reconcile_external_primary scheduled run:
  Reconciler:           listPreparedOlderThan(now - 1h)
  Sink:                 returns [handle for orphan]
  Reconciler:           looks up kernel.audit_pending for entryId
                        status = pending; no commit/rollback recorded
  Reconciler:           decision = CANCEL (cautious default)
  Sink:                 cancel(handle, "reconciler-cautious")
  Reconciler:           kernel.audit_pending → cancelled
  Reconciler:           emit kernel.audit.confirm_lost entry (Standard, with elevation=null)
```

### B.4 Bulk MaintenanceAction

```
Invoker (Maintenance):  begin tx
Effect:                 repository.updateMany(filter, patch)
  Driver:               BulkResult(affected_count=8123, sample_handles=[...100...])
Invoker:                auditor.emit(entry with BulkSubject, tx)
  Auditor:              truncate sample_handles to min(100, sink cap, config cap)
                        if truncated, set outputs.bulk_truncated=true
  Auditor:              sink.writeInTransaction(entry, tx)  (Transactional primary)
Invoker:                tx.commit() → bulk and audit both committed
```

---

## Appendix C — Contradiction scan

| ID    | Description | Status |
|-------|-------------|--------|
| C7-01 | §3.2 (Auditor caller authorization) vs §15.2 (Repository calls Auditor). | Consistent; §3.2 enumerates the Repository as authorized for read audits. |
| C7-02 | §7.1 ("Transactional primary = exactly-once") vs §7.3 ("no exactly-once claim across systems"). | Consistent; §7.1 is per-sink, §7.3 is cross-sink. |
| C7-03 | §8.4 ("no total ordering") vs §13.3 ("affected_count is exact"). | Independent concerns; count exactness ≠ inter-entry ordering. |
| C7-04 | §10.2 (elevated entries carry elevation slot, tenant=target) vs RFC-003 §10.5 (Elevation slot definition). | Aligned; this RFC restates and elaborates. |
| C7-05 | §11.4 (dead-letter is a state, not deletion) vs RFC-001 §8.3 (append-only). | Consistent; dead-letter is an additional row, not a mutation. |
| C7-06 | §12.5 (`elevate_close_late` emitted by reconciler) vs §10.1 (close emitted on scope close). | Consistent; the late entry is a compensating record, not a replacement. |
| C7-07 | §14.6 (no per-Action subtraction of redaction) vs §14.6 (per-Action additive redaction). | Same clause; consistent. |
| C7-08 | §15.3 (read audit failure aborts read) vs RFC-002 §5.3.3 (find returns null for missing). | Consistent; null-return is for non-existence, audit failure is a distinct error type. |
| C7-09 | §16.1 `audit.secondary_sinks` empty default vs Amendment-01 §A-1.6 ("zero or more secondary"). | Consistent; zero is permitted. |
| C7-10 | §19.1 (FieldDescriptor `sensitive` requires RFC-001 amendment) vs §14.3 (used here as if normative). | Acknowledged; §19.1 explicitly schedules the amendment. RFC-007 acceptance is gated on the RFC-001 amendment (§21.2). |

**Result.** No contradictions. One scheduled downstream amendment (C7-10) is the documented dependency.

---

## Appendix D — Layer boundary scan

| Component | Layer | Inbound | Outbound | Result |
|---|---|---|---|---|
| `Auditor` | L2 Runtime | invoked by Invoker, Repository, Kernel bootstrap | calls L3 sinks, L3 driver (for `audit_pending` and retry queue) | OK |
| `AuditSink` (and subtypes) | L3 plugin | invoked by Auditor, retry worker | depends on L0 contracts | OK |
| `AuditRetryQueue` | L2 Runtime; realized via L3 driver | invoked by Auditor, retry worker | L3 driver | OK |
| `kernel.audit_pending` Entity | Kernel-owned, in `system` scope | written by Auditor, read by reconciler | L3 driver | OK |
| Retry worker (`kernel.audit.retry_worker`) | scheduled MaintenanceAction (L2 invoked via Invoker) | scheduler | retry queue, sinks | OK |
| Reconciler (`kernel.audit.reconcile_external_primary`) | scheduled MaintenanceAction | scheduler | `kernel.audit_pending`, External sinks | OK |

**Findings.**

| ID | Description | Resolution |
|---|---|---|
| L7-01 | The Auditor is called by the Repository (§15.2). Does L2 → L3 → L0 → L2 cycle violate layering? | The Repository (L3) calls the Auditor (L2 contract bound at L2). The Repository depends on the L0 contract for Auditor; L2 binds the implementation. Direction is L3 → L0 ← L2. No cycle. |
| L7-02 | The retry worker runs through the Invoker as a MaintenanceAction but also accesses sink internals. | Sink access is through the L0 AuditSink contract; the worker does not import sink-internal classes. OK. |
| L7-03 | `kernel.audit_pending` is an L0 Entity used to track L2 state. | This is the same pattern as RFC-003's `kernel.tenant` and `kernel.tenant_override`: Kernel-owned Entities used to persist L2 runtime state via L3 driver. Established pattern. |
| L7-04 | Reconciler is a MaintenanceAction emitting compensating Audit Entries (`confirm_lost`). Recursive audit emission? | The reconciler's own emissions go through the Auditor normally; they audit the reconciliation Action itself, not any nested operation. No recursion. |
| L7-05 | The retry queue worker invokes secondary sinks via their `AuditSink::writeInTransaction` or `ExternalSink::prepare`. For a secondary External sink, who calls confirm/cancel? | A secondary sink does not participate in the data transaction; for an External secondary, the worker calls `prepare` followed immediately by `confirm` (the secondary's commitment is its own write, not gated by the originating Action's commit). The secondary's prepare/confirm pair happens entirely in the retry worker context. Documented as an implementation note for sink authors. |

**Result.** No violations. Five findings resolve cleanly under existing patterns.
