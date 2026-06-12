<?php
declare(strict_types=1);

namespace Ausus;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * ApplicationConfig — fluent, immutable, typed builder for {@see Application}.
 *
 * Replaces the loose array form of `Application::create()` with named, typed
 * methods. Every setter returns a **new instance** with the value applied —
 * the receiver is never mutated, so configs can be safely shared and partially
 * specialized.
 *
 * ```php
 * $config = ApplicationConfig::make()
 *     ->tenant('acme')
 *     ->actor('boot')
 *     ->roles(['invoice.creator', 'invoice.issuer'])
 *     ->sqlite('/tmp/app.sqlite');
 *
 * $app = Application::create($config);
 * ```
 *
 * `Application::create(array $config)` continues to work unchanged. Pass either
 * an ApplicationConfig or an array — the array shape is documented on
 * {@see Application::create()} and is exactly what {@see toArray()} produces.
 *
 * Validation happens at the setter level so misuse is reported close to the
 * call that caused it. Conflicting persistence config (`sqlite()` + `pdo()`)
 * is rejected at the second call rather than at boot time.
 */
final class ApplicationConfig
{
    /**
     * @param string[] $roles
     * @param string[] $permissions
     */
    private function __construct(
        public readonly ?Tenant $tenant = null,
        public readonly ?Actor $actor = null,
        public readonly string $actorId = 'app',
        public readonly array $roles = [],
        public readonly array $permissions = [],
        /** RFC-018 (R-2) — default actor attributes for the built-in StubActor. @var array<string,int|string|float|bool|null> */
        public readonly array $actorAttributes = [],
        public readonly ?\PDO $pdo = null,
        public readonly ?string $sqlitePath = null,
        public readonly bool $migrate = true,
        public readonly string $kernelVersion = '1.0.0',
        public readonly ?PersistenceDriver $driver = null,
        public readonly ?AuditSink $auditSink = null,
        public readonly ?string $apiPrefix = null,
        public readonly ?ResponseFactoryInterface $responseFactory = null,
        public readonly ?StreamFactoryInterface $streamFactory = null,
    ) {}

    /** Start a fresh config. Every setter returns a new ApplicationConfig. */
    public static function make(): self
    {
        return new self();
    }

    // ─── identity / tenancy ──────────────────────────────────────────────────

    /** The active tenant. Pass a non-empty string id or a built {@see Tenant}. */
    public function tenant(string|Tenant $tenant): self
    {
        if (is_string($tenant) && $tenant === '') {
            throw new \InvalidArgumentException("ApplicationConfig::tenant(): tenant id must be a non-empty string.");
        }
        $obj = $tenant instanceof Tenant ? $tenant : new Tenant(new TenantId($tenant));
        return $this->with(['tenant' => $obj]);
    }

    /**
     * Set the acting actor. Overloaded for convenience:
     *
     *  - **string** — sets the default `StubActor`'s id (alias of {@see actorId()}).
     *  - **Actor**  — uses the given actor verbatim, overriding `actorId`,
     *                 `roles` and `permissions`.
     */
    public function actor(string|Actor $actor): self
    {
        if ($actor instanceof Actor) {
            return $this->with(['actor' => $actor]);
        }
        return $this->actorId($actor);
    }

    /** Set the default `StubActor`'s id. Ignored if {@see actor()} carries an Actor. */
    public function actorId(string $id): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException("ApplicationConfig::actorId(): id must be non-empty.");
        }
        return $this->with(['actorId' => $id]);
    }

    /**
     * Roles for the default `StubActor`. Replaces any previous value.
     *
     * @param string[] $roles
     */
    public function roles(array $roles): self
    {
        return $this->with(['roles' => $this->validateStringList($roles, 'roles')]);
    }

    /**
     * Permissions for the default `StubActor`. Replaces any previous value.
     *
     * @param string[] $permissions
     */
    public function permissions(array $permissions): self
    {
        return $this->with(['permissions' => $this->validateStringList($permissions, 'permissions')]);
    }

    /**
     * RFC-018 (R-2) — default attributes for the built-in `StubActor`. Replaces
     * any previous value. Values must be scalar or null (RFC-018 facts are
     * scalar); non-scalar entries are dropped. NOT yet wired to RFC-018 runtime
     * authorization — this only seeds the default actor.
     *
     * @param array<string,mixed> $attributes
     */
    public function actorAttributes(array $attributes): self
    {
        $clean = [];
        foreach ($attributes as $k => $v) {
            if ($v === null || is_int($v) || is_string($v) || is_float($v) || is_bool($v)) {
                $clean[(string) $k] = $v;
            }
        }
        return $this->with(['actorAttributes' => $clean]);
    }

    // ─── persistence ─────────────────────────────────────────────────────────

    /**
     * Use a SQLite database at the given file path. `':memory:'` is valid.
     * Mutually exclusive with {@see pdo()}.
     */
    public function sqlite(string $path): self
    {
        if ($path === '') {
            throw new \InvalidArgumentException("ApplicationConfig::sqlite(): path must be non-empty.");
        }
        if ($this->pdo !== null) {
            throw new \InvalidArgumentException(
                "ApplicationConfig::sqlite(): cannot set a sqlite path when pdo() has already been set — they are mutually exclusive."
            );
        }
        return $this->with(['sqlitePath' => $path]);
    }

    /** Use an already-opened PDO connection. Mutually exclusive with {@see sqlite()}. */
    public function pdo(\PDO $pdo): self
    {
        if ($this->sqlitePath !== null) {
            throw new \InvalidArgumentException(
                "ApplicationConfig::pdo(): cannot set a PDO when sqlite() has already been set — they are mutually exclusive."
            );
        }
        return $this->with(['pdo' => $pdo]);
    }

    /** Apply the derived schema on boot. Default: true. */
    public function migrate(bool $migrate = true): self
    {
        return $this->with(['migrate' => $migrate]);
    }

    /** Advanced: replace the SQLite persistence driver entirely. */
    public function driver(PersistenceDriver $driver): self
    {
        return $this->with(['driver' => $driver]);
    }

    /** Advanced: replace the database audit sink. */
    public function auditSink(AuditSink $sink): self
    {
        return $this->with(['auditSink' => $sink]);
    }

    // ─── HTTP (used only by {@see Application::http()} / ::router()) ─────────

    /**
     * URL path prefix the {@see \Ausus\Api\Http\Router} mounts under.
     * Default (when unset) matches the Router's own default: `'/api'`.
     */
    public function apiPrefix(string $prefix): self
    {
        if ($prefix === '' || $prefix[0] !== '/') {
            throw new \InvalidArgumentException(
                "ApplicationConfig::apiPrefix(): prefix must be a non-empty string starting with '/'."
            );
        }
        if (strlen($prefix) > 1 && str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException(
                "ApplicationConfig::apiPrefix(): prefix must not end with '/'."
            );
        }
        return $this->with(['apiPrefix' => $prefix]);
    }

    /**
     * Combined PSR-17 factory — convenient when a single object (e.g.
     * `Nyholm\Psr7\Factory\Psr17Factory`) implements both
     * {@see ResponseFactoryInterface} and {@see StreamFactoryInterface}. Sets
     * both slots at once. For split factories, use
     * {@see responseFactory()}/{@see streamFactory()}.
     */
    public function psr17(object $factory): self
    {
        if (!$factory instanceof ResponseFactoryInterface) {
            throw new \InvalidArgumentException(
                'ApplicationConfig::psr17(): factory must implement Psr\\Http\\Message\\ResponseFactoryInterface; got '
                . get_debug_type($factory) . '.'
            );
        }
        if (!$factory instanceof StreamFactoryInterface) {
            throw new \InvalidArgumentException(
                'ApplicationConfig::psr17(): factory must implement Psr\\Http\\Message\\StreamFactoryInterface; got '
                . get_debug_type($factory) . '.'
            );
        }
        return $this->with(['responseFactory' => $factory, 'streamFactory' => $factory]);
    }

    /** PSR-17 response factory used by the HTTP layer (only one of psr17() / responseFactory() / streamFactory() is needed). */
    public function responseFactory(ResponseFactoryInterface $factory): self
    {
        return $this->with(['responseFactory' => $factory]);
    }

    /** PSR-17 stream factory used by the HTTP layer. */
    public function streamFactory(StreamFactoryInterface $factory): self
    {
        return $this->with(['streamFactory' => $factory]);
    }

    // ─── kernel ──────────────────────────────────────────────────────────────

    /** Kernel version recorded in the compiled graph. Default: `'1.0.0'`. */
    public function kernelVersion(string $version): self
    {
        if ($version === '') {
            throw new \InvalidArgumentException("ApplicationConfig::kernelVersion(): version must be non-empty.");
        }
        return $this->with(['kernelVersion' => $version]);
    }

    // ─── output ──────────────────────────────────────────────────────────────

    /**
     * Render this config as the array {@see Application::create()} expects.
     *
     * Only non-default values are included, so the output is the minimal
     * representation of the choices the caller made.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->tenant !== null)      $out['tenant']      = $this->tenant;
        if ($this->actor !== null)       $out['actor']       = $this->actor;
        if ($this->actorId !== 'app')    $out['actorId']     = $this->actorId;
        if ($this->roles !== [])         $out['roles']       = $this->roles;
        if ($this->permissions !== [])   $out['permissions'] = $this->permissions;
        if ($this->actorAttributes !== []) $out['actorAttributes'] = $this->actorAttributes;

        if ($this->pdo !== null) {
            $out['database'] = $this->pdo;
        } elseif ($this->sqlitePath !== null) {
            $out['database'] = $this->sqlitePath;
        }

        if ($this->kernelVersion !== '1.0.0') $out['kernelVersion']   = $this->kernelVersion;
        if ($this->migrate !== true)          $out['migrate']         = $this->migrate;
        if ($this->driver !== null)           $out['driver']          = $this->driver;
        if ($this->auditSink !== null)        $out['auditSink']       = $this->auditSink;
        if ($this->apiPrefix !== null)        $out['apiPrefix']       = $this->apiPrefix;
        if ($this->responseFactory !== null)  $out['responseFactory'] = $this->responseFactory;
        if ($this->streamFactory !== null)    $out['streamFactory']   = $this->streamFactory;

        return $out;
    }

    // ─── internals ───────────────────────────────────────────────────────────

    /**
     * Build a new instance with the supplied changes merged in. Null-coalescing
     * (`??`) lets unspecified keys carry through from `$this`; arrays and
     * booleans pass through cleanly because the right-hand side is only used
     * when the left key is missing or null.
     *
     * @param array<string,mixed> $c
     */
    private function with(array $c): self
    {
        return new self(
            tenant:          $c['tenant']          ?? $this->tenant,
            actor:           $c['actor']           ?? $this->actor,
            actorId:         $c['actorId']         ?? $this->actorId,
            roles:           $c['roles']           ?? $this->roles,
            permissions:     $c['permissions']     ?? $this->permissions,
            actorAttributes: $c['actorAttributes'] ?? $this->actorAttributes,
            pdo:             $c['pdo']             ?? $this->pdo,
            sqlitePath:      $c['sqlitePath']      ?? $this->sqlitePath,
            migrate:         $c['migrate']         ?? $this->migrate,
            kernelVersion:   $c['kernelVersion']   ?? $this->kernelVersion,
            driver:          $c['driver']          ?? $this->driver,
            auditSink:       $c['auditSink']       ?? $this->auditSink,
            apiPrefix:       $c['apiPrefix']       ?? $this->apiPrefix,
            responseFactory: $c['responseFactory'] ?? $this->responseFactory,
            streamFactory:   $c['streamFactory']   ?? $this->streamFactory,
        );
    }

    /**
     * @param mixed[] $values
     * @return string[]
     */
    private function validateStringList(array $values, string $field): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            if (!is_string($v)) {
                throw new \InvalidArgumentException(
                    "ApplicationConfig::{$field}(): entry at index {$i} must be a string, got "
                    . get_debug_type($v) . '.'
                );
            }
            if ($v === '') {
                throw new \InvalidArgumentException(
                    "ApplicationConfig::{$field}(): entry at index {$i} must be a non-empty string."
                );
            }
            $out[] = $v;
        }
        return $out;
    }
}
