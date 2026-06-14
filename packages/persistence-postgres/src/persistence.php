<?php
declare(strict_types=1);
namespace Ausus\Persistence\Postgres;

use Ausus\{
    PersistenceDriver, PersistenceContext, Repository, PagedRepository, TransactionHandle,
    Tenant, MetadataGraph, EntityNode, FieldNode, Reference, Version, Entity,
    Filter, Sort, Ulid, TenantBoundaryViolation, NotFound, ConcurrencyConflict,
    ReferentialIntegrityViolation, AuditSink, AuditEntry,
};

// L3 PersistenceDriver — PostgreSQL.
// Behaviour-compatible with ausus/persistence-sql (SQLite) across the full
// kernel persistence contract; cross-driver parity is asserted by tests/compat.php.

final class PostgresTransactionHandle implements TransactionHandle {
    public function __construct(public readonly Tenant $tenantBound) {}
    public function tenant(): Tenant { return $this->tenantBound; }
}

final class PostgresRepository implements Repository, PagedRepository {
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Tenant $tenant,
        private readonly EntityNode $entity,
    ) {}

    private function tableName(): string {
        return str_replace('.', '_', $this->entity->fqn);
    }

    private function assertReferenceExists(FieldNode $f, mixed $value): void {
        if ($value === null) {
            return;
        }
        $target = $f->typeOptions['targetEntityFqn'] ?? null;
        if ($target === null || $target === '') {
            return;
        }
        $targetTable = str_replace('.', '_', $target);
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM \"{$targetTable}\" WHERE id = :id AND tenant_id = :tid LIMIT 1"
        );
        $stmt->execute(['id' => (string) $value, 'tid' => $this->tenant->value()]);
        if ($stmt->fetchColumn() === false) {
            throw new ReferentialIntegrityViolation(
                $this->entity->fqn,
                $f->name,
                $target,
                (string) $value,
                $this->tenant->value(),
            );
        }
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

        // RFC-015 — enforce referential integrity before the INSERT.
        foreach ($this->entity->fields as $f) {
            if ($f->type === 'reference' && array_key_exists($f->name, $row)) {
                $this->assertReferenceExists($f, $row[$f->name]);
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
            if ($field === null) {
                throw new \RuntimeException("UnknownField: {$this->entity->fqn}.{$k}");
            }
            $serialized = $this->serializeField($field, $v);
            // RFC-015 — re-validate referential integrity when a reference is repointed.
            if ($field->type === 'reference') {
                $this->assertReferenceExists($field, $serialized);
            }
            $sets[] = "\"{$k}\" = :{$k}";
            $params[$k] = $serialized;
        }
        $params['id']   = $ref->identityHandle;
        $params['tid']  = $this->tenant->value();
        $params['oldv'] = $expected->value;

        $sql = sprintf(
            'UPDATE "%s" SET %s WHERE id = :id AND tenant_id = :tid AND _version = :oldv',
            $table,
            implode(', ', $sets),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare("SELECT _version FROM \"{$table}\" WHERE id = :id AND tenant_id = :tid");
            $check->execute(['id' => $ref->identityHandle, 'tid' => $this->tenant->value()]);
            $existing = $check->fetch(\PDO::FETCH_ASSOC);
            if ($existing === false) {
                throw new NotFound($ref);
            }
            throw new ConcurrencyConflict($ref, $expected->value, (string) $existing['_version']);
        }

        $reread = $this->find($ref);
        if ($reread === null) {
            throw new \RuntimeException("PostUpdateMissingRow: {$ref->identityHandle}");
        }
        return $reread;
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

    /**
     * @param list<Filter> $filters
     * @param list<Sort>   $sort
     * @return array{items: list<Entity>, totalCount: int}
     */
    public function findPaged(int $limit, int $offset, array $filters = [], array $sort = []): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException("findPaged: limit must be >= 1, got {$limit}");
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException("findPaged: offset must be >= 0, got {$offset}");
        }
        $table = $this->tableName();

        $whereSql = ['tenant_id = :tid'];
        $params   = [':tid' => $this->tenant->value()];
        $paramIdx = 0;
        foreach ($filters as $filter) {
            if (!$filter instanceof Filter) {
                throw new \InvalidArgumentException('findPaged: every filter must be an Ausus\Filter');
            }
            $col = $this->resolveColumn($filter->field);
            switch ($filter->op) {
                case Filter::OP_EQ:
                    $placeholder = ':f' . $paramIdx++;
                    $whereSql[] = "\"{$col}\" = {$placeholder}";
                    $params[$placeholder] = $filter->value;
                    break;
                case Filter::OP_CONTAINS:
                    $placeholder = ':f' . $paramIdx++;
                    $whereSql[] = "LOWER(\"{$col}\") LIKE LOWER({$placeholder})";
                    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filter->value);
                    $params[$placeholder] = '%' . $escaped . '%';
                    break;
                case Filter::OP_IN:
                    $inPlaceholders = [];
                    foreach ($filter->value as $v) {
                        $p = ':f' . $paramIdx++;
                        $inPlaceholders[] = $p;
                        $params[$p] = $v;
                    }
                    $whereSql[] = "\"{$col}\" IN (" . implode(',', $inPlaceholders) . ')';
                    break;
            }
        }
        $where = implode(' AND ', $whereSql);

        $orderParts  = [];
        $sortColsSeen = [];
        foreach ($sort as $s) {
            if (!$s instanceof Sort) {
                throw new \InvalidArgumentException('findPaged: every sort entry must be an Ausus\Sort');
            }
            $col = $this->resolveColumn($s->field);
            if (isset($sortColsSeen[$col])) {
                throw new \InvalidArgumentException("findPaged: duplicate sort column '{$s->field}'");
            }
            $sortColsSeen[$col] = true;
            $dir = $s->direction === Sort::DIR_DESC ? 'DESC' : 'ASC';
            $orderParts[] = "\"{$col}\" {$dir}";
        }
        if (!isset($sortColsSeen['id'])) {
            $orderParts[] = '"id" ASC';
        }
        $orderBy = implode(', ', $orderParts);

        $sql = "SELECT * FROM \"{$table}\" WHERE {$where} ORDER BY {$orderBy} LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        foreach ($params as $name => $val) {
            $type = match (true) {
                is_int($val)  => \PDO::PARAM_INT,
                is_bool($val) => \PDO::PARAM_BOOL,
                $val === null => \PDO::PARAM_NULL,
                default       => \PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $val, $type);
        }
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->hydrate($row);
        }

        $countSql  = "SELECT COUNT(*) FROM \"{$table}\" WHERE {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $name => $val) {
            $type = match (true) {
                is_int($val)  => \PDO::PARAM_INT,
                is_bool($val) => \PDO::PARAM_BOOL,
                $val === null => \PDO::PARAM_NULL,
                default       => \PDO::PARAM_STR,
            };
            $countStmt->bindValue($name, $val, $type);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return ['items' => $items, 'totalCount' => $total];
    }

    private function resolveColumn(string $field): string {
        if ($field === 'id') {
            return 'id';
        }
        foreach ($this->entity->fields as $f) {
            if ($f->name === $field) {
                return $field;
            }
        }
        throw new \InvalidArgumentException(
            "findPaged: unknown column '{$field}' on entity {$this->entity->fqn}"
        );
    }

    private function serializeField(FieldNode $f, mixed $value): mixed {
        if ($value === null) {
            return null;
        }
        return match ($f->type) {
            'money'    => is_array($value) ? (string) $value['amount'] : (string) $value,
            'integer'  => (int) $value,
            'datetime' => (string) $value,
            default    => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    private function hydrate(array $row): Entity {
        $fields = $this->unwrapFields($row);
        $ref = new Reference((string) $row['tenant_id'], $this->entity->fqn, (string) $row['id']);
        return new Entity($ref, new Version((string) $row['_version']), $fields);
    }

    private function unwrapFields(array $row): array {
        $out = [];
        foreach ($this->entity->fields as $f) {
            if (!array_key_exists($f->name, $row)) continue;
            $v = $row[$f->name];
            if ($v === null) { $out[$f->name] = null; continue; }
            $out[$f->name] = match ($f->type) {
                'integer' => (int) $v,
                'money'   => ['amount' => (string) $v, 'currency' => $f->typeOptions['currency'] ?? 'USD'],
                default   => $v,
            };
        }
        return $out;
    }
}

final class PostgresContext implements PersistenceContext {
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Tenant $tenant,
        private readonly TransactionHandle $tx,
        private readonly MetadataGraph $graph,
    ) {}

    public function tenant(): Tenant { return $this->tenant; }

    public function repository(string $entityFqn): Repository {
        $entity = $this->graph->entities[$entityFqn] ?? null;
        if ($entity === null) {
            throw new \RuntimeException("UnknownEntity: {$entityFqn}");
        }
        return new PostgresRepository($this->pdo, $this->tenant, $entity);
    }
}

final class PostgresPersistenceDriver implements PersistenceDriver {
    public function __construct(
        private readonly \PDO $pdo,
        private readonly MetadataGraph $graph,
    ) {}

    public function beginTransaction(Tenant $tenant): TransactionHandle {
        $this->pdo->beginTransaction();
        return new PostgresTransactionHandle($tenant);
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
        return new PostgresContext($this->pdo, $tenant, $h, $this->graph);
    }

    public function generateIdentity(string $entityFqn): string {
        return Ulid::generate();
    }
}

// PostgreSQL schema deriver — reproduces the SqliteSchemaDeriver behaviour
// (same types / columns / IF NOT EXISTS).
final class PostgresSchemaDeriver {
    public static function deriveAll(MetadataGraph $graph): array {
        $sql = [];
        foreach ($graph->entities as $entity) {
            $sql[] = self::deriveEntity($entity);
        }
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
        if ($f->type === 'identity' || $f->type === 'version') $nullable = '';
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

// PostgreSQL audit sink — reproduces DatabaseAuditSink exactly (same INSERT,
// same 17 columns, inputs/outputs json_encode, nullable trace_id).
final class PostgresAuditSink implements AuditSink {
    public function __construct(private readonly \PDO $pdo) {}
    public function writeInTransaction(AuditEntry $entry, TransactionHandle $tx): void {
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
