<?php
declare(strict_types=1);

namespace Ausus\Persistence\Sql;

use Ausus\{
    PersistenceDriver, PersistenceContext, Repository, TransactionHandle,
    Tenant, Reference, Version, Entity, MetadataGraph, EntityNode, FieldNode,
    Ulid, NotFound, ConcurrencyConflict, TenantBoundaryViolation
};

// =============================================================================
// SQLite-backed PersistenceDriver  (V0 minimal)
// =============================================================================

/**
 * @internal Package-private. Public only because PHP cannot model
 *           package visibility — constructed by {@see SqlitePersistenceDriver}
 *           and passed back to it as a {@see TransactionHandle}. Consumers
 *           MUST depend on the `TransactionHandle` interface (the public
 *           contract); the concrete class and its public `$closed` flag
 *           carry no backward-compatibility guarantee.
 */
final class SqliteTransactionHandle implements TransactionHandle {
    public function __construct(public readonly Tenant $tenantBound, public bool $closed = false) {}
    public function tenant(): Tenant { return $this->tenantBound; }
}

/**
 * @internal Package-private. Public only because PHP cannot model
 *           package visibility — manufactured by {@see SqlitePersistenceDriver}
 *           once per transaction. Consumers MUST depend on the
 *           {@see PersistenceContext} interface (the public contract); the
 *           concrete class, its constructor signature, and its dependency
 *           on the `MetadataGraph` are intra-package details.
 */
final class SqliteContext implements PersistenceContext {
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Tenant $tenant,
        private readonly SqliteTransactionHandle $tx,
        private readonly MetadataGraph $graph,
    ) {}
    public function tenant(): Tenant { return $this->tenant; }
    public function repository(string $entityFqn): Repository {
        $entity = $this->graph->entities[$entityFqn] ?? null;
        if ($entity === null) {
            throw new \RuntimeException("UnknownEntity: {$entityFqn}");
        }
        return new SqliteRepository($this->pdo, $this->tenant, $entity);
    }
}

/**
 * @internal Package-private. Public only because PHP cannot model
 *           package visibility — instantiated by {@see SqliteContext::repository()}.
 *           Consumers MUST depend on the {@see Repository} interface
 *           (the public contract); the concrete class, its constructor
 *           signature, and its private SQL emission are intra-package
 *           details. Custom drivers ship their own Repository class.
 */
final class SqliteRepository implements Repository {
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Tenant $tenant,
        private readonly EntityNode $entity,
    ) {}

    private function tableName(): string {
        return str_replace('.', '_', $this->entity->fqn);
    }

    public function find(Reference $ref): ?Entity {
        if ($ref->tenantId !== $this->tenant->value()) {
            throw new TenantBoundaryViolation("ref tenant={$ref->tenantId} active={$this->tenant->value()}");
        }
        $table = $this->tableName();
        $stmt = $this->pdo->prepare("SELECT * FROM \"{$table}\" WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['id' => $ref->identityHandle, 'tid' => $this->tenant->value()]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->hydrate($row);
    }

    public function create(array $payload, ?string $identity = null): Entity {
        $id = $identity ?? Ulid::generate();
        $version = Ulid::generate();
        $now = gmdate('Y-m-d\\TH:i:s\\Z');

        $row = [
            'id'         => $id,
            'tenant_id'  => $this->tenant->value(),
            '_version'   => $version,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        // Apply defaults from FieldNodes
        foreach ($this->entity->fields as $f) {
            if ($f->system) continue;
            if (array_key_exists($f->name, $payload)) {
                $row[$f->name] = $this->serializeField($f, $payload[$f->name]);
            } elseif ($f->default !== null) {
                $row[$f->name] = $this->serializeField($f, $f->default);
            } elseif (!$f->nullable) {
                throw new \RuntimeException("FieldRequired: {$this->entity->fqn}.{$f->name} not in payload and no default");
            }
        }

        $columns = array_keys($row);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO "%s" (%s) VALUES (%s)',
            $this->tableName(),
            implode(', ', array_map(fn($c) => "\"{$c}\"", $columns)),
            implode(', ', $placeholders),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($row);

        $ref = new Reference($this->tenant->value(), $this->entity->fqn, $id);
        return new Entity($ref, new Version($version), $this->unwrapFields($row));
    }

    public function update(Reference $ref, array $patch, Version $expected): Entity {
        if ($ref->tenantId !== $this->tenant->value()) {
            throw new TenantBoundaryViolation("ref tenant={$ref->tenantId} active={$this->tenant->value()}");
        }
        $table = $this->tableName();
        $newVersion = Ulid::generate();
        $now = gmdate('Y-m-d\\TH:i:s\\Z');

        $sets = ['_version = :_new_version', 'updated_at = :_now'];
        $params = ['_new_version' => $newVersion, '_now' => $now];
        foreach ($patch as $k => $v) {
            $field = $this->entity->field($k);
            if ($field === null) throw new \RuntimeException("UnknownField: {$this->entity->fqn}.{$k}");
            $sets[] = "\"{$k}\" = :{$k}";
            $params[$k] = $this->serializeField($field, $v);
        }
        $params['id']     = $ref->identityHandle;
        $params['tid']    = $this->tenant->value();
        $params['oldv']   = $expected->value;

        $sql = sprintf(
            'UPDATE "%s" SET %s WHERE id = :id AND tenant_id = :tid AND _version = :oldv',
            $table,
            implode(', ', $sets),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        if ($affected === 0) {
            // Disambiguate NotFound vs ConcurrencyConflict
            $check = $this->pdo->prepare("SELECT _version FROM \"{$table}\" WHERE id = :id AND tenant_id = :tid");
            $check->execute(['id' => $ref->identityHandle, 'tid' => $this->tenant->value()]);
            $existing = $check->fetch(\PDO::FETCH_ASSOC);
            if ($existing === false) throw new NotFound($ref);
            throw new ConcurrencyConflict($ref, $expected->value, (string) $existing['_version']);
        }

        // Re-read for full row
        $reread = $this->find($ref);
        if ($reread === null) throw new \RuntimeException("PostUpdateMissingRow: {$ref->identityHandle}");
        return $reread;
    }

    private function serializeField(FieldNode $f, mixed $value): mixed {
        // Bypass every per-type coercion when the caller explicitly passed null.
        //
        // Why this guard exists, before any other branch:
        //   - `is_scalar(null)` is FALSE in PHP — null is neither a scalar nor
        //     an array. The default branch's `is_scalar(...) ? (string) ... : json_encode(...)`
        //     therefore routes null into `json_encode(null)`, which returns the
        //     4-character string `"null"` — silently corrupting nullable
        //     columns with a literal text value.
        //   - `(string) null` is the empty string `""`, which would corrupt
        //     the `datetime` / `money` branches in the same way.
        //   - `(int) null` is `0`, which would erase the null state of a
        //     nullable integer column.
        //
        // PDO::execute() binds PHP null as SQL NULL, so returning null here
        // produces a real SQL NULL on disk and a real PHP null on read-back —
        // which is the entire purpose of `Field::*()->nullable()`.
        if ($value === null) {
            return null;
        }
        return match ($f->type) {
            'money' => is_array($value)
                ? (string) $value['amount']                   // store amount only; currency in $value['currency']
                : (string) $value,
            'integer' => (int) $value,
            'datetime' => (string) $value,
            default   => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    public function findAll(): array {
        $table = $this->tableName();
        $stmt = $this->pdo->prepare("SELECT * FROM \"{$table}\" WHERE tenant_id = :tid ORDER BY id");
        $stmt->execute(['tid' => $this->tenant->value()]);
        $entities = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entities[] = $this->hydrate($row);
        }
        return $entities;
    }

    private function hydrate(array $row): Entity {
        $fields = $this->unwrapFields($row);
        $ref = new Reference(
            (string) $row['tenant_id'],
            $this->entity->fqn,
            (string) $row['id'],
        );
        return new Entity($ref, new Version((string) $row['_version']), $fields);
    }

    private function unwrapFields(array $row): array {
        $out = [];
        foreach ($this->entity->fields as $f) {
            if (!array_key_exists($f->name, $row)) continue;
            $v = $row[$f->name];
            // Symmetric with serializeField's null guard: a column that is
            // SQL NULL on disk reads back as PHP null on every type — including
            // `money`, which would otherwise be wrapped into
            // `['amount' => '', 'currency' => '…']` and break the nullable
            // contract.
            if ($v === null) {
                $out[$f->name] = null;
                continue;
            }
            $out[$f->name] = match ($f->type) {
                'integer' => (int) $v,
                'money'   => ['amount' => (string) $v, 'currency' => $f->typeOptions['currency'] ?? 'USD'],
                default   => $v,
            };
        }
        return $out;
    }
}

final class SqlitePersistenceDriver implements PersistenceDriver {
    public function __construct(
        private readonly \PDO $pdo,
        private readonly MetadataGraph $graph,
    ) {}
    public function beginTransaction(Tenant $tenant): TransactionHandle {
        $this->pdo->beginTransaction();
        return new SqliteTransactionHandle($tenant);
    }
    public function commit(TransactionHandle $h): void {
        if ($this->pdo->inTransaction()) $this->pdo->commit();
    }
    public function rollback(TransactionHandle $h): void {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }
    public function context(Tenant $tenant, TransactionHandle $h): PersistenceContext {
        if ($h->tenant()->value() !== $tenant->value()) {
            throw new TenantBoundaryViolation("context tenant={$tenant->value()} handle={$h->tenant()->value()}");
        }
        return new SqliteContext($this->pdo, $tenant, $h, $this->graph);
    }
    public function generateIdentity(string $entityFqn): string {
        return Ulid::generate();
    }
}

// =============================================================================
// Schema Deriver
// =============================================================================

final class SchemaDeriver {
    public static function deriveAll(MetadataGraph $graph): array {
        $sql = [];
        foreach ($graph->entities as $entity) {
            $sql[] = self::deriveEntity($entity);
        }
        // Kernel-internal audit log table
        $sql[] = self::auditTable();
        return $sql;
    }

    private static function deriveEntity(EntityNode $entity): string {
        $tableName = str_replace('.', '_', $entity->fqn);
        $cols = [];
        foreach ($entity->fields as $f) {
            $cols[] = self::columnFor($f);
        }
        $colSql = implode(",\n  ", $cols);
        return "CREATE TABLE IF NOT EXISTS \"{$tableName}\" (\n  {$colSql},\n  PRIMARY KEY (id)\n);";
    }

    private static function columnFor(FieldNode $f): string {
        $type = match ($f->type) {
            'identity', 'version'   => 'TEXT NOT NULL',
            'string', 'system_string', 'datetime' => 'TEXT',
            'integer'               => 'INTEGER',
            'enum'                  => 'TEXT',
            'money'                 => 'NUMERIC',
            default                 => 'TEXT',
        };
        $nullable = $f->nullable ? '' : ' NOT NULL';
        if ($f->type === 'identity' || $f->type === 'version') $nullable = ''; // already NOT NULL
        $default = '';
        if ($f->default !== null && !$f->system) {
            $default = ' DEFAULT ' . self::quoteDefault($f->default);
        }
        return "\"{$f->name}\" {$type}{$nullable}{$default}";
    }

    private static function quoteDefault(mixed $v): string {
        if (is_int($v) || is_float($v)) return (string) $v;
        return "'" . str_replace("'", "''", (string) $v) . "'";
    }

    private static function auditTable(): string {
        return <<<SQL
        CREATE TABLE IF NOT EXISTS "kernel_audit_log" (
          "entry_id" TEXT NOT NULL,
          "sequence" INTEGER NOT NULL,
          "actor_type" TEXT NOT NULL,
          "actor_id" TEXT NOT NULL,
          "actor_home_tenant" TEXT NOT NULL,
          "tenant_id" TEXT NOT NULL,
          "action_fqn" TEXT NOT NULL,
          "subject_tenant_id" TEXT NOT NULL,
          "subject_entity_fqn" TEXT NOT NULL,
          "subject_identity_handle" TEXT NOT NULL,
          "inputs" TEXT NOT NULL,
          "outputs" TEXT NOT NULL,
          "timestamp" TEXT NOT NULL,
          "correlation_id" TEXT NOT NULL,
          "trace_id" TEXT,
          "invocation_class" TEXT NOT NULL,
          "emitter_version" TEXT NOT NULL,
          PRIMARY KEY (entry_id)
        );
        SQL;
    }
}

// =============================================================================
// Database Audit Sink (Transactional primary)
// =============================================================================

final class DatabaseAuditSink implements \Ausus\AuditSink {
    public function __construct(private readonly \PDO $pdo) {}
    public function writeInTransaction(\Ausus\AuditEntry $entry, TransactionHandle $tx): void {
        $sql = 'INSERT INTO "kernel_audit_log"
          (entry_id, sequence, actor_type, actor_id, actor_home_tenant,
           tenant_id, action_fqn, subject_tenant_id, subject_entity_fqn, subject_identity_handle,
           inputs, outputs, timestamp, correlation_id, trace_id, invocation_class, emitter_version)
          VALUES (:entry_id, :sequence, :actor_type, :actor_id, :actor_home_tenant,
                  :tenant_id, :action_fqn, :s_tid, :s_efqn, :s_id,
                  :inputs, :outputs, :timestamp, :correlation_id, :trace_id, :invocation_class, :emitter_version)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'entry_id'           => $entry->entryId,
            'sequence'           => $entry->sequence,
            'actor_type'         => $entry->actor->type,
            'actor_id'           => $entry->actor->id,
            'actor_home_tenant'  => $entry->actor->homeTenant,
            'tenant_id'          => $entry->tenant,
            'action_fqn'         => $entry->actionFqn,
            's_tid'              => $entry->subject->tenantId,
            's_efqn'             => $entry->subject->entityFqn,
            's_id'               => $entry->subject->identityHandle,
            'inputs'             => json_encode($entry->inputs),
            'outputs'            => json_encode($entry->outputs),
            'timestamp'          => $entry->timestamp,
            'correlation_id'     => $entry->correlationId,
            'trace_id'           => $entry->traceId,
            'invocation_class'   => $entry->invocationClass,
            'emitter_version'    => $entry->emitterVersion,
        ]);
    }
}
