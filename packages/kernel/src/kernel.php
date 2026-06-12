<?php
declare(strict_types=1);

namespace Ausus;

// =============================================================================
// VALUE OBJECTS  (Reference, TenantId, Tenant, ActorRef, Subject, Decision)
// =============================================================================

final readonly class TenantId {
    public function __construct(public string $value) {}
}

final readonly class Tenant {
    public function __construct(public TenantId $id) {}
    public function value(): string { return $this->id->value; }
}

final readonly class ActorRef {
    public function __construct(
        public string $type,        // 'user' | 'system' | 'service'
        public string $id,
        public string $homeTenant,
    ) {}
}

final readonly class Reference {
    public function __construct(
        public string $tenantId,
        public string $entityFqn,
        public string $identityHandle,
    ) {}

    /**
     * Backward-compatibility shim (RFC-015).
     *
     * `Subject` was historically a byte-identical twin of `Reference` used only
     * at the Policy boundary; `Subject::fromReference()` copied a Reference into
     * a Subject. RFC-015 unifies the two into one canonical identity value
     * object: `Ausus\Subject` is now a `class_alias` of `Ausus\Reference`
     * (declared just below). With a single class this copy is the identity
     * function — it returns the same value object. Retained so existing
     * `Subject::fromReference($ref)` call sites keep compiling unchanged.
     */
    public static function fromReference(self $r): self {
        return $r;
    }
}

/**
 * RFC-015 — Reference / Subject unification.
 *
 * `Subject` used to be a distinct, byte-identical readonly class referenced only
 * by the Policy contract (`Policy::evaluate(..., ?Subject $subject, ...)`).
 * Maintaining two value objects of identical shape was a standing contradiction:
 * it forced a `Subject::fromReference()` copy on every Policy evaluation and
 * split the single "instance identity" concept into two types. RFC-015 makes
 * `Reference` the one canonical identity value object and exposes `Ausus\Subject`
 * as an alias of it.
 *
 * This is fully backward-compatible — because the alias resolves to the *same*
 * class, every existing usage keeps working unchanged:
 *   - `new Subject($t, $e, $h)`         constructs a Reference (identical ctor);
 *   - `Subject::fromReference($ref)`     returns the Reference (shim above);
 *   - `?Subject` parameter / return types accept a Reference;
 *   - `instanceof Subject`               is true for any Reference.
 *
 * New code SHOULD use `Reference`; `Subject` is retained as a deprecated alias.
 */
class_alias(\Ausus\Reference::class, 'Ausus\\Subject');

enum Decision: string {
    case Permit = 'permit';
    case Deny = 'deny';
    case Abstain = 'abstain';
}

// =============================================================================
// RFC-018 — GUARD KERNEL (Phase 1: contracts only — no runtime, no behavior)
//
// A Guard is ⟨operation, declared facts, predicate⟩ → permit | deny | abstain.
// Phase 1 introduces ONLY the kernel-level value/contract types; the runtime
// (FactResolver / GuardComposer / CondGuard) and the Invoker wiring land in a
// later phase. These types are additive and reference only other kernel
// symbols (Decision above) — there is NO kernel → dsl dependency (R-1).
// =============================================================================

/** Open set of fact origins (R-1 §invariant 2 — extensible). */
enum Provenance: string {
    case Actor           = 'actor';          // roles / permissions / server-resolved attributes
    case SubjectIdentity = 'subject.id';     // tenantId / entityFqn / identityHandle
    case SubjectField    = 'subject.field';  // the subject's own declared fields
    case OperationInput  = 'op.input';       // the affect-operation's proposed inputs
    case Context         = 'context';        // clock / tenant / correlationId
}

/** A declared reference to a fact ⟨provenance, key⟩ — the unit of closure. */
final readonly class FactRef {
    public function __construct(
        public Provenance $provenance,
        public string $key,
    ) {}
}

/**
 * An observed fact ⟨provenance, key, value⟩. Values are SCALAR (pure,
 * serializable). Static factories return a {@see FactRef} for the predicate
 * DSL — the value ctor is used by the runtime FactResolver in a later phase.
 */
final readonly class Fact {
    public function __construct(
        public Provenance $provenance,
        public string $key,
        public int|string|float|bool|null $value,
    ) {}

    public static function subject(string $key): FactRef { return new FactRef(Provenance::SubjectField, $key); }
    public static function actor(string $key): FactRef   { return new FactRef(Provenance::Actor, $key); }
    public static function input(string $key): FactRef   { return new FactRef(Provenance::OperationInput, $key); }
}

/** Immutable, runtime-supplied snapshot read by a pure Guard predicate. */
interface FactSet {
    public function get(Provenance $p, string $key): int|string|float|bool|null;
    public function has(Provenance $p, string $key): bool;
    /** @return list<Fact> the captured decision basis */
    public function all(): array;
}

/**
 * A declarative predicate tree over {@see FactRef}s and scalar literals — the
 * KERNEL representation of a guard condition (R-1). Pure data: inspectable by
 * the compiler (closure) and serializable; it does no I/O and is never a
 * closure. Operands are `FactRef | Cond | scalar | array` (the latter for
 * `in`).
 */
final readonly class Cond {
    /** @param list<mixed> $args */
    public function __construct(
        public string $op,
        public array $args,
    ) {}

    public static function eq(mixed $a, mixed $b): self  { return new self('eq',  [$a, $b]); }
    public static function ne(mixed $a, mixed $b): self  { return new self('ne',  [$a, $b]); }
    public static function lte(mixed $a, mixed $b): self { return new self('lte', [$a, $b]); }
    public static function lt(mixed $a, mixed $b): self  { return new self('lt',  [$a, $b]); }
    public static function gte(mixed $a, mixed $b): self { return new self('gte', [$a, $b]); }
    public static function gt(mixed $a, mixed $b): self  { return new self('gt',  [$a, $b]); }
    /** @param list<int|string|float|bool|null> $literals */
    public static function in(mixed $a, array $literals): self { return new self('in', [$a, $literals]); }
    public static function mul(mixed $a, float $k): self { return new self('mul', [$a, $k]); }
    public static function not(Cond $c): self            { return new self('not', [$c]); }
    public static function and(Cond ...$c): self         { return new self('and', $c); }
    public static function or(Cond ...$c): self          { return new self('or',  $c); }

    /**
     * Recursively collect the declared fact references (closure surface). Used
     * by the compiler's static validation and the runtime FactResolver in a
     * later phase.
     *
     * @return list<FactRef>
     */
    public function factRefs(): array {
        $refs = [];
        foreach ($this->args as $a) {
            if ($a instanceof FactRef) {
                $refs[] = $a;
            } elseif ($a instanceof Cond) {
                $refs = array_merge($refs, $a->factRefs());
            } elseif (is_array($a)) {
                foreach ($a as $x) {
                    if ($x instanceof FactRef)      { $refs[] = $x; }
                    elseif ($x instanceof Cond)     { $refs = array_merge($refs, $x->factRefs()); }
                }
            }
        }
        return $refs;
    }
}

/** A pure predicate bound to an operation over declared facts → a Decision. */
interface Guard {
    /** @return list<FactRef> declared reads — closure surface */
    public function reads(): array;
    public function decide(FactSet $facts): Decision;
}

final readonly class Version {
    public function __construct(public string $value) {}
}

final readonly class Instant {
    public function __construct(public float $epochSeconds) {}
    public function toRfc3339(): string {
        $secs = (int) $this->epochSeconds;
        $micros = (int) round(($this->epochSeconds - $secs) * 1_000_000);
        return gmdate('Y-m-d\\TH:i:s', $secs) . sprintf('.%06dZ', $micros);
    }
}

// =============================================================================
// ACTOR + CONTEXT
// =============================================================================

interface Actor {
    public function ref(): ActorRef;
    public function roleHash(): string;
    /** @return string[] */ public function roles(): array;
    /** @return string[] */ public function permissions(): array;
    /** RFC-018 (R-2) — server-resolved actor attribute, or null when absent. */
    public function attribute(string $key): int|string|float|bool|null;
}

final class StubActor implements Actor {
    public function __construct(
        private readonly ActorRef $ref,
        /** @var string[] */ private readonly array $roles,
        /** @var string[] */ private readonly array $permissions = [],
        /**
         * RFC-018 (R-2) — server-resolved actor attributes. Final, defaulted —
         * every existing `new StubActor($ref, $roles[, $permissions])` keeps
         * compiling unchanged. Deliberately EXCLUDED from {@see roleHash()} so
         * attributes never influence the role-decision cache key.
         * @var array<string,int|string|float|bool|null>
         */
        private readonly array $attributes = [],
    ) {}
    public function ref(): ActorRef { return $this->ref; }
    public function roles(): array { return $this->roles; }
    public function permissions(): array { return $this->permissions; }
    public function attribute(string $key): int|string|float|bool|null { return $this->attributes[$key] ?? null; }
    public function roleHash(): string {
        // RFC-014 §3 canonical hash
        $payload = json_encode([
            'permissions' => $this->sortUnique($this->permissions),
            'roles'       => $this->sortUnique($this->roles),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $payload);
    }
    private function sortUnique(array $a): array { $a = array_values(array_unique($a)); sort($a, SORT_STRING); return $a; }
}

final readonly class Context {
    public function __construct(
        public Tenant $tenant,
        public string $correlationId,
        public ?string $traceId,
        public Instant $clock,
    ) {}
}

// =============================================================================
// POLICY + EFFECT CONTRACTS
// =============================================================================

interface Policy {
    public function evaluate(Actor $actor, string $actionFqn, ?Subject $subject, Context $context): Decision;
}

interface EffectContext {
    public function repository(string $entityFqn): Repository;
    public function actor(): Actor;
    public function tenant(): Tenant;
    public function correlationId(): string;
    public function traceId(): ?string;
    public function clock(): Instant;
}

interface Effect {
    /** @param array<string,mixed> $inputs @return array<string,mixed> */
    public function execute(EffectContext $context, ?Reference $subject, array $inputs): array;
}

/**
 * Built-in effect identifiers.
 *
 * An {@see ActionNode}'s `effectClass` is overloaded: it carries **either** a
 * PHP class FQN that implements {@see Effect}, **or** a sentinel naming one of
 * the kernel's built-in effects (`Create`, `Transition`). This enum names the
 * sentinel values so DSL writers and effect dispatchers do not pass raw strings
 * around. The underlying string values are stable and form the public wire
 * format of `ActionNode::effectClass`.
 */
enum BuiltinEffect: string {
    case Create     = 'kernel.builtin.create';
    case Transition = 'kernel.builtin.transition';
    case Update     = 'kernel.builtin.update';
}

/**
 * Public contract for {@see ActionNode::$effectClass} (v0.1.x).
 *
 * The string in `$effectClass` is one of exactly two shapes — the runtime's
 * {@see EffectDispatcher} distinguishes them by exact string match, in this order:
 *
 * 1. **Built-in sentinel** — equal to one of the `BuiltinEffect::*->value`
 *    strings (`'kernel.builtin.create'`, `'kernel.builtin.transition'`,
 *    `'kernel.builtin.update'`). The dispatcher routes to the matching
 *    runtime-shipped effect; `effectConfig` carries kind-specific configuration
 *    (e.g. `createStateField`, `createInitial`, `updatableFields`). The DSL
 *    produces these via {@see Action::create()}, {@see Action::transition()},
 *    {@see Action::update()}.
 *
 * 2. **Custom effect FQN** — any other string is treated as a fully-qualified
 *    PHP class name. The class MUST implement {@see Effect} and MUST be
 *    instantiable with **no constructor arguments** — the dispatcher
 *    `new`s it lazily per invocation. `effectConfig` is *not* passed to the
 *    constructor; custom effects receive their inputs through
 *    {@see Effect::execute()} and read configuration from elsewhere (closure,
 *    service locator, environment). v0.1.x does **not** define a registration
 *    hook for constructor injection.
 *
 * The two shapes share a single field deliberately — `effectClass` is the
 * canonical address of "which effect runs", and the dispatcher's first job
 * is to disambiguate the sentinel set from the custom FQN set. The string
 * values of the sentinels are stable wire metadata; renaming or moving them
 * is a public-API break.
 */

// =============================================================================
// PERSISTENCE CONTRACTS (subset of RFC-002)
// =============================================================================

/**
 * Per-entity, per-tenant CRUD-shaped contract.
 *
 * `findAll()` was added in v0.1.1 so the projection renderer can enumerate
 * entities through the repository contract instead of reaching into the SQLite
 * PDO via reflection. This is a documented contract addition — any custom
 * `Repository` implementation must add it.
 */
interface Repository {
    public function find(Reference $ref): ?Entity;
    /** @param array<string,mixed> $payload */ public function create(array $payload, ?string $identity = null): Entity;
    /** @param array<string,mixed> $patch */ public function update(Reference $ref, array $patch, Version $expected): Entity;
    /** List all entities of this kind in the active tenant, ordered by id. @return list<Entity> */
    public function findAll(): array;
}

/**
 * SPI for repositories that natively support pagination. Implementations that
 * realise this interface get LIMIT/OFFSET pushdown at the driver level; the
 * default `Repository` contract remains a complete fallback for adapters that
 * only know how to return every row.
 *
 * @phpstan-type PagedResult array{items: list<Entity>, totalCount: int}
 */
interface PagedRepository extends Repository {
    /**
     * Return a deterministic page of entities for the active tenant, optionally
     * narrowed by `$filters` and ordered by `$sort`, together with the total
     * row count BEFORE the limit/offset window but AFTER any filters.
     *
     * Contract:
     *   - `$limit >= 1` and `$offset >= 0` — implementations may assume the
     *     caller has already validated/clamped these values.
     *   - Filters and sort entries are pre-validated by the caller against
     *     the declared field whitelist; implementations must STILL refuse
     *     unknown columns as a defensive defence-in-depth check.
     *   - Ordering is stable across calls (same query → same items). A trailing
     *     `id ASC` is appended by the adapter when `$sort` does not already
     *     pin a deterministic key.
     *   - `offset >= totalCount` returns an empty items list, NOT an error.
     *
     * @param list<Filter> $filters
     * @param list<Sort>   $sort
     * @return array{items: list<Entity>, totalCount: int}
     */
    public function findPaged(int $limit, int $offset, array $filters = [], array $sort = []): array;
}

/**
 * Whitelisted filter primitive used by the projection / repository contract.
 *
 * Three operators (no boolean trees, no OR groups, no arbitrary fragments):
 *
 *   - `eq`        — exact match on a scalar value;
 *   - `in`        — membership in a small list of scalars (max 100 entries);
 *   - `contains`  — substring search (case-insensitive) on string fields.
 *
 * Field validity is enforced by the caller against the projection's declared
 * field list; the value object itself only checks operator legality and value
 * shape so a malformed Filter cannot ever reach the SQL adapter.
 */
final readonly class Filter {
    public const OP_EQ       = 'eq';
    public const OP_IN       = 'in';
    public const OP_CONTAINS = 'contains';

    public const OPS = [self::OP_EQ, self::OP_IN, self::OP_CONTAINS];

    /** Maximum cardinality of an `in` list — beyond this, callers should narrow upstream. */
    public const IN_MAX_VALUES = 100;

    public function __construct(
        public string $field,
        public string $op,
        public mixed  $value,
    ) {
        if ($field === '') {
            throw new \InvalidArgumentException("Filter: field must not be empty");
        }
        if (!in_array($op, self::OPS, true)) {
            throw new \InvalidArgumentException(
                "Filter: unknown operator '{$op}' (allowed: " . implode(',', self::OPS) . ')'
            );
        }
        match ($op) {
            self::OP_EQ, self::OP_CONTAINS => $this->assertScalar(),
            self::OP_IN                    => $this->assertScalarList(),
        };
    }

    private function assertScalar(): void {
        if (!is_scalar($this->value)) {
            throw new \InvalidArgumentException(
                "Filter[{$this->field} {$this->op}]: value must be scalar, got " . get_debug_type($this->value)
            );
        }
    }

    private function assertScalarList(): void {
        if (!is_array($this->value)) {
            throw new \InvalidArgumentException(
                "Filter[{$this->field} in]: value must be an array, got " . get_debug_type($this->value)
            );
        }
        if ($this->value === []) {
            throw new \InvalidArgumentException(
                "Filter[{$this->field} in]: value list must not be empty"
            );
        }
        if (count($this->value) > self::IN_MAX_VALUES) {
            throw new \InvalidArgumentException(
                "Filter[{$this->field} in]: value list has " . count($this->value)
                . " entries (max " . self::IN_MAX_VALUES . ")"
            );
        }
        foreach ($this->value as $v) {
            if (!is_scalar($v)) {
                throw new \InvalidArgumentException(
                    "Filter[{$this->field} in]: every list entry must be scalar"
                );
            }
        }
    }
}

/**
 * Whitelisted sort primitive used by the projection / repository contract.
 *
 * Direction is `asc` or `desc`; the field is validated by the caller against
 * the entity's declared columns. The value object refuses any other input so
 * the SQL adapter can rely on the shape.
 */
final readonly class Sort {
    public const DIR_ASC  = 'asc';
    public const DIR_DESC = 'desc';

    public const DIRS = [self::DIR_ASC, self::DIR_DESC];

    public function __construct(
        public string $field,
        public string $direction,
    ) {
        if ($field === '') {
            throw new \InvalidArgumentException("Sort: field must not be empty");
        }
        if (!in_array($direction, self::DIRS, true)) {
            throw new \InvalidArgumentException(
                "Sort: invalid direction '{$direction}' (allowed: " . implode(',', self::DIRS) . ')'
            );
        }
    }
}

final readonly class Entity {
    public function __construct(
        public Reference $reference,
        public Version $version,
        /** @var array<string,mixed> */ public array $fields,
    ) {}
    public function field(string $name): mixed { return $this->fields[$name] ?? null; }
}

interface PersistenceDriver {
    public function beginTransaction(Tenant $tenant): TransactionHandle;
    public function commit(TransactionHandle $h): void;
    public function rollback(TransactionHandle $h): void;
    public function context(Tenant $tenant, TransactionHandle $h): PersistenceContext;
    public function generateIdentity(string $entityFqn): string;
}

interface PersistenceContext {
    public function repository(string $entityFqn): Repository;
    public function tenant(): Tenant;
}

interface TransactionHandle {
    public function tenant(): Tenant;
}

// =============================================================================
// AUDIT CONTRACTS (subset of RFC-007)
// =============================================================================

final readonly class SingleSubject {
    public function __construct(
        public string $tenantId, public string $entityFqn, public string $identityHandle,
    ) {}
}

final readonly class AuditEntry {
    public function __construct(
        public string $entryId,
        public int $sequence,
        public ActorRef $actor,
        public string $tenant,
        public string $actionFqn,
        public SingleSubject $subject,
        /** @var array<string,mixed> */ public array $inputs,
        /** @var array<string,mixed> */ public array $outputs,
        public string $timestamp,
        public string $correlationId,
        public ?string $traceId,
        public string $invocationClass,   // 'Standard' | 'Maintenance'
        public string $emitterVersion,
        /** @var list<Fact> RFC-018 captured decision basis (Phase 1: carried, default empty) */
        public array $decisionBasis = [],
    ) {}
}

interface AuditSink {
    public function writeInTransaction(AuditEntry $entry, TransactionHandle $tx): void;
}

interface Auditor {
    public function emit(AuditEntry $entry, TransactionHandle $tx): void;
}

// =============================================================================
// METADATA GRAPH (subset)
// =============================================================================

final readonly class FieldNode {
    /**
     * @param ?string $label  Human-friendly label for this field. When null
     *                        (the v0.1.x default for fields built without
     *                        `FieldBuilder::label(...)`), the
     *                        ProjectionRenderer auto-humanizes the name
     *                        (`project_id` → "Project id"). Additive; placed
     *                        last so existing positional callers — including
     *                        the manual HelloInvoicePlugin — keep compiling.
     */
    public function __construct(
        public string $name,
        public string $type,              // 'string'|'integer'|'enum'|'money'|'datetime'|'identity'|'version'|'system_string'
        public bool $system,
        public bool $nullable,
        /** @var array<string,mixed> */ public array $typeOptions,
        public mixed $default,
        public ?string $label = null,
    ) {}
}

final readonly class TransitionNode {
    public function __construct(
        public string $source,      // or '*'
        public string $target,
        public string $viaActionFqn,
    ) {}
}

final readonly class WorkflowNode {
    public function __construct(
        public string $fqn,
        public string $ownerEntityFqn,
        public string $stateField,
        /** @var string[] */ public array $states,
        public string $initial,
        /** @var TransitionNode[] */ public array $transitions,
    ) {}
}

final readonly class PolicyNode {
    public function __construct(
        public string $fqn,
        public string $implementationClass,
        /** @var array<string,mixed> */ public array $constructorArgs,
    ) {}
}

final readonly class ActionNode {
    /**
     * @param string $effectClass
     *     One of two shapes (see the docblock above {@see BuiltinEffect}):
     *     a {@see BuiltinEffect} sentinel value (`kernel.builtin.create`,
     *     `kernel.builtin.transition`, `kernel.builtin.update`), **or** a
     *     custom FQN whose class implements {@see Effect} and has a no-arg
     *     constructor. The runtime {@see EffectDispatcher} disambiguates by
     *     exact string match against the sentinel set.
     * @param array<string,mixed> $effectConfig
     *     Built-in-effect configuration consumed by the dispatcher, not by
     *     the effect class' constructor. Empty for custom-FQN effects.
     * @param FieldNode[] $inputs
     * @param string $kind  `'standard'` | `'maintenance'`.
     */
    public function __construct(
        public string $fqn,
        public string $entityFqn,
        public string $policyFqn,
        public bool $subjectRequired,
        public string $effectClass,
        public array $effectConfig,
        public array $inputs,
        public string $kind,
        /** @var list<Cond> RFC-018 data-aware guards (Phase 1: carried, not yet evaluated) */
        public array $guards = [],
    ) {}
}

final readonly class ProjectionNode {
    /**
     * @param string[] $fields
     * @param string[] $actionFqns
     * @param array<string,string> $expand  RFC-015 relation expansion. Maps a
     *        `reference` field name declared on the owner entity to the display
     *        field of the *target* entity to fold into each rendered row as
     *        `{refField}_label`. Empty by default — additive and
     *        backward-compatible for every existing positional/named caller.
     */
    public function __construct(
        public string $fqn,
        public string $ownerEntityFqn,
        public array $fields,
        public array $actionFqns,
        public array $expand = [],
        /** Declared read-role; null = unrestricted. Enforced at the read path. */
        public ?string $role = null,
    ) {}
}

final readonly class EntityNode {
    public function __construct(
        public string $fqn,
        public bool $tenantScoped,
        /** @var FieldNode[] */ public array $fields,
        /** @var string[] */ public array $actionFqns,
        /** @var string[] */ public array $projectionFqns,
        /** @var string[] */ public array $workflowFqns,
    ) {}
    public function field(string $name): ?FieldNode {
        foreach ($this->fields as $f) if ($f->name === $name) return $f;
        return null;
    }
}

final readonly class MetadataGraph {
    public function __construct(
        public string $hash,
        public string $kernelVersion,
        /** @var array<string,EntityNode> */ public array $entities,
        /** @var array<string,ActionNode> */ public array $actions,
        /** @var array<string,PolicyNode> */ public array $policies,
        /** @var array<string,WorkflowNode> */ public array $workflows,
        /** @var array<string,ProjectionNode> */ public array $projections,
        /** @var array<string,FieldNode> RFC-018 declared actor-attribute schema (Phase 1: carried, default empty) */
        public array $actorAttributes = [],
    ) {}
}

// =============================================================================
// PLUGIN CONTRACT
// =============================================================================

interface Plugin {
    public function name(): string;            // 'billing'
    public function phpNamespace(): string;    // 'Acme\\Billing'
    /** Returns a normalized descriptor array; the compiler turns it into MetadataGraph. */
    public function describe(): array;
}

// =============================================================================
// COMPILER (minimal — accepts plugin descriptors, produces MetadataGraph)
// =============================================================================

final class Compiler {
    /** @param Plugin[] $plugins */
    public function compile(array $plugins, string $kernelVersion = '1.0.0'): MetadataGraph {
        $entities = []; $actions = []; $policies = []; $workflows = []; $projections = []; $actorAttributes = [];
        foreach ($plugins as $plugin) {
            $desc = $plugin->describe();
            foreach ($desc['entities'] ?? [] as $e) {
                $entities[$e->fqn] = $e;
            }
            foreach ($desc['actions'] ?? [] as $a) {
                if (isset($actions[$a->fqn])) {
                    throw new \RuntimeException("DuplicateRegistration: action {$a->fqn}");
                }
                $actions[$a->fqn] = $a;
            }
            foreach ($desc['policies'] ?? [] as $p) {
                $policies[$p->fqn] = $p;
            }
            foreach ($desc['workflows'] ?? [] as $w) {
                $workflows[$w->fqn] = $w;
            }
            foreach ($desc['projections'] ?? [] as $pr) {
                $projections[$pr->fqn] = $pr;
            }
            // RFC-018 (Phase 3) — collect the declared actor-attribute schema.
            foreach ($desc['actorAttributes'] ?? [] as $k => $v) {
                $actorAttributes[(string) $k] = $v;
            }
        }
        // Validate references
        // RFC-015 — relation (reference) fields MUST target a declared entity.
        // This rejects dangling relation *definitions* at compile time, before
        // any data is written; the persistence layer enforces the per-row
        // referential integrity at write time.
        foreach ($entities as $entity) {
            foreach ($entity->fields as $f) {
                if ($f->type !== 'reference') {
                    continue;
                }
                $target = $f->typeOptions['targetEntityFqn'] ?? null;
                if ($target === null || $target === '') {
                    throw new \RuntimeException(
                        "RelationTargetMissing: entity {$entity->fqn} field '{$f->name}' is a reference "
                        . "with no target entity FQN (use Field::reference('<plugin>.<entity>'))."
                    );
                }
                if (!isset($entities[$target])) {
                    throw new \RuntimeException(
                        "DanglingRelation: entity {$entity->fqn} field '{$f->name}' references entity "
                        . "'{$target}', which is not registered."
                    );
                }
            }
        }
        foreach ($actions as $a) {
            if (!isset($policies[$a->policyFqn])) {
                throw new \RuntimeException("DanglingReference: action {$a->fqn} → policy {$a->policyFqn} (not registered)");
            }
            if (!isset($entities[$a->entityFqn])) {
                throw new \RuntimeException("DanglingReference: action {$a->fqn} → entity {$a->entityFqn} (not registered)");
            }
        }
        foreach ($workflows as $w) {
            if (!isset($entities[$w->ownerEntityFqn])) {
                throw new \RuntimeException("DanglingReference: workflow {$w->fqn} → entity {$w->ownerEntityFqn}");
            }
            $entity = $entities[$w->ownerEntityFqn];
            if ($entity->field($w->stateField) === null) {
                throw new \RuntimeException("WorkflowCoherence: workflow {$w->fqn} state field '{$w->stateField}' not on entity {$w->ownerEntityFqn}");
            }
            if (!in_array($w->initial, $w->states, true)) {
                throw new \RuntimeException("WorkflowCoherence: workflow {$w->fqn} initial state '{$w->initial}' not in declared states [" . implode(',', $w->states) . "]");
            }
            foreach ($w->transitions as $t) {
                if ($t->source !== '*' && !in_array($t->source, $w->states, true)) {
                    throw new \RuntimeException("WorkflowCoherence: transition source '{$t->source}' not in states");
                }
                if (!in_array($t->target, $w->states, true)) {
                    throw new \RuntimeException("WorkflowCoherence: transition target '{$t->target}' not in states");
                }
                if (!isset($actions[$t->viaActionFqn])) {
                    throw new \RuntimeException("DanglingReference: transition via '{$t->viaActionFqn}' not registered");
                }
            }
        }
        // RFC-018 (Phase 3) — static guard-closure validation (NO runtime eval).
        // Runs after entities + actions are collected, before the graph is built.
        $this->validateGuardClosure($actions, $entities, $actorAttributes);

        // Canonicalize + hash
        $sortByKey = function(array $a) { ksort($a); return $a; };
        $entities    = $sortByKey($entities);
        $actions     = $sortByKey($actions);
        $policies    = $sortByKey($policies);
        $workflows   = $sortByKey($workflows);
        $projections = $sortByKey($projections);

        $canonical = json_encode([
            'actions'       => array_keys($actions),
            'entities'      => array_keys($entities),
            'kernelVersion' => $kernelVersion,
            'policies'      => array_keys($policies),
            'projections'   => array_keys($projections),
            'workflows'     => array_keys($workflows),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $canonical);

        return new MetadataGraph($hash, $kernelVersion, $entities, $actions, $policies, $workflows, $projections, $actorAttributes);
    }

    /**
     * RFC-018 (Phase 3) — STATIC closure check (no runtime evaluation).
     *
     * Every {@see FactRef} referenced by every {@see Cond} carried on an action
     * must be resolvable from declared metadata, else {@see DanglingFactReference}
     * is thrown at compile time:
     *   - SubjectField    → must be a field of the action's entity;
     *   - OperationInput  → must be one of the action's declared inputs;
     *   - Actor           → reserved {id, roles, permissions} OR a declared actor attribute;
     *   - Context         → reserved {now, tenant, actor};
     *   - SubjectIdentity → reserved {id, type, tenant}.
     *
     * @param array<string,ActionNode> $actions
     * @param array<string,EntityNode> $entities
     * @param array<string,mixed>      $actorAttributes
     */
    private function validateGuardClosure(array $actions, array $entities, array $actorAttributes): void {
        $reservedActor     = ['id', 'roles', 'permissions'];
        $reservedContext   = ['now', 'tenant', 'actor'];
        $reservedSubjectId = ['id', 'type', 'tenant'];
        $attrKeys          = array_keys($actorAttributes);
        foreach ($actions as $a) {
            if ($a->guards === []) { continue; }
            $entity       = $entities[$a->entityFqn] ?? null;
            $entityFields = $entity !== null ? array_map(static fn($f) => $f->name, $entity->fields) : [];
            $inputNames   = array_map(static fn($f) => $f->name, $a->inputs);
            foreach ($a->guards as $cond) {
                if (!$cond instanceof Cond) { continue; }
                foreach ($cond->factRefs() as $ref) {
                    $ok = match ($ref->provenance) {
                        Provenance::SubjectField    => in_array($ref->key, $entityFields, true),
                        Provenance::OperationInput  => in_array($ref->key, $inputNames, true),
                        Provenance::Actor           => in_array($ref->key, $reservedActor, true) || in_array($ref->key, $attrKeys, true),
                        Provenance::Context         => in_array($ref->key, $reservedContext, true),
                        Provenance::SubjectIdentity => in_array($ref->key, $reservedSubjectId, true),
                    };
                    if (!$ok) {
                        throw new DanglingFactReference($a->fqn, $ref->provenance->value, $ref->key);
                    }
                }
            }
        }
    }
}

// =============================================================================
// ULID GENERATOR  (Crockford base32, 26 chars)
// =============================================================================

final class Ulid {
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string {
        $timestampMs = (int) round(microtime(true) * 1000);
        $bytes = pack('J', $timestampMs);     // 8 bytes big-endian
        $bytes = substr($bytes, 2);            // take last 6 bytes (48 bits)
        $bytes .= random_bytes(10);            // 80 bits randomness
        // 16 bytes → 26 base32 chars
        return self::encodeCrockford($bytes);
    }

    private static function encodeCrockford(string $bytes): string {
        // Convert 16 bytes (128 bits) to 26 chars (130 bits, top 2 zero-padded)
        // Simple bit-by-bit; 26 chars * 5 bits = 130 bits.
        $bits = '';
        foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        $bits = str_pad($bits, 130, '0', STR_PAD_LEFT);   // pad to 130 from left
        $out = '';
        for ($i = 0; $i < 130; $i += 5) {
            $chunk = substr($bits, $i, 5);
            $out .= self::ALPHABET[(int) bindec($chunk)];
        }
        return $out;
    }
}

// =============================================================================
// INVOCATION RESULT  (typed wrapper around the loose Invoker output array)
// =============================================================================

/**
 * Typed outcome of an invocation.
 *
 * `Invoker::invoke()` returns `array<string,mixed>` (the raw effect outputs) —
 * useful but loosely typed. `InvocationResult` wraps that array together with
 * the post-action {@see Reference} (the new subject for a `create`, the input
 * subject for a transition) so callers get an IDE-discoverable surface.
 *
 * Produced by {@see \Ausus\Application::run()}. The underlying `outputs` array
 * is still available for callers that need it.
 */
final readonly class InvocationResult
{
    /** @param array<string,mixed> $outputs effect outputs, as returned by the runtime */
    public function __construct(
        public string $actionFqn,
        public ?Reference $subject,
        public array $outputs,
    ) {}

    /** The identity of the affected entity, when one exists. */
    public function id(): ?string
    {
        $id = $this->subject?->identityHandle ?? ($this->outputs['id'] ?? null);
        return $id === null ? null : (string) $id;
    }

    /** Read a single output value by key, or null if absent. */
    public function output(string $key): mixed
    {
        return $this->outputs[$key] ?? null;
    }
}

// =============================================================================
// EXCEPTIONS  (V0 minimal closed-ish taxonomy)
// =============================================================================

class AususError extends \RuntimeException {}

class UnknownAction extends AususError implements Errors\NotFoundError {}
class PolicySubjectRequired extends AususError implements Errors\BadRequestError {}
/** Raised when an action invocation carries an input the action did not declare. */
class UndeclaredActionInput extends AususError implements Errors\BadRequestError {}
/**
 * @internal Reserved exception class — not raised by any v0.1.x runtime path.
 *           Declared so future Invoker code paths (notably the policy
 *           bootstrap when actor resolution becomes pluggable) can raise it
 *           without a wire/taxonomy break. Do not catch it in v0.1.x consumer
 *           code — there is nothing to catch.
 */
class ActorRequired extends AususError implements Errors\BadRequestError {}
/**
 * @internal Reserved exception class — not raised by any v0.1.x runtime path.
 *           Declared so future PersistenceContext bootstraps can raise it
 *           without a wire/taxonomy break. Do not catch in v0.1.x consumer
 *           code.
 */
class TenantContextRequired extends AususError implements Errors\BadRequestError {}
class TenantBoundaryViolation extends AususError implements Errors\ForbiddenError {}
class PolicyDenied extends AususError implements Errors\ForbiddenError {}
class WorkflowStateMismatch extends AususError implements Errors\ConflictError {}
class WorkflowSubjectNotFound extends AususError implements Errors\NotFoundError {}
class EffectFailed extends AususError implements Errors\InternalError {
    public function __construct(string $actionFqn, public readonly \Throwable $causeError) {
        parent::__construct("EffectFailed: {$actionFqn}: " . $causeError->getMessage(), 0, $causeError);
    }
}
class ConcurrencyConflict extends AususError implements Errors\ConflictError {
    public function __construct(public readonly Reference $ref, public readonly string $expected, public readonly string $actual) {
        parent::__construct("ConcurrencyConflict: {$ref->entityFqn}/{$ref->identityHandle} expected={$expected} actual={$actual}");
    }
}
class NotFound extends AususError implements Errors\NotFoundError {
    public function __construct(public readonly Reference $ref) {
        parent::__construct("NotFound: {$ref->entityFqn}/{$ref->identityHandle} in tenant {$ref->tenantId}");
    }
}
/**
 * RFC-015 — raised by the persistence driver when a `reference` field is written
 * with an identity that does not resolve to an existing row of the target entity
 * in the active tenant. A dangling foreign reference is a client error
 * (BadRequest), not a server fault: the caller supplied an id that does not
 * exist, or that belongs to another tenant. This is the runtime half of the
 * "ghost references are impossible" guarantee; the compile-time half is the
 * `DanglingRelation` check in {@see Compiler::compile()}.
 */
class ReferentialIntegrityViolation extends AususError implements Errors\BadRequestError {
    public function __construct(
        public readonly string $entityFqn,
        public readonly string $field,
        public readonly string $targetEntityFqn,
        public readonly string $missingId,
        public readonly string $tenantId,
    ) {
        parent::__construct(
            "ReferentialIntegrityViolation: {$entityFqn}.{$field} → {$targetEntityFqn} "
            . "'{$missingId}' does not exist in tenant {$tenantId}."
        );
    }
}
/**
 * RFC-018 (Phase 3) — raised at compile time by {@see Compiler::validateGuardClosure()}
 * when a guard's {@see Cond} references a {@see FactRef} that does not resolve to
 * declared metadata (an unknown subject field, operation input, actor attribute,
 * or reserved key). A build-time closure error — the static half of "no guard
 * reads an undeclared fact"; no runtime evaluation is involved.
 */
class DanglingFactReference extends AususError implements Errors\BadRequestError {
    public function __construct(
        public readonly string $actionFqn,
        public readonly string $provenance,
        public readonly string $key,
    ) {
        parent::__construct(
            "DanglingFactReference: action {$actionFqn} guard references {$provenance} "
            . "'{$key}', which is not declared/resolvable."
        );
    }
}
class AuditEmissionFailed extends AususError implements Errors\InternalError {}
/**
 * @internal Reserved exception class — not raised by any v0.1.x runtime path.
 *           The v0.1.x WorkflowRuntime uses {@see WorkflowStateMismatch} for
 *           guard failures. This class exists so a future guard-as-policy
 *           split can introduce a distinct denial-by-guard signal without a
 *           wire/taxonomy break. Do not catch in v0.1.x consumer code.
 */
class WorkflowGuardDenied extends AususError implements Errors\ForbiddenError {}
