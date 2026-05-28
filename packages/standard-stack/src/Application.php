<?php
declare(strict_types=1);

namespace Ausus;

use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex, EffectDispatcher,
    DefaultAuditor, SequenceCounter, Invoker, ProjectionRenderer,
};
use Ausus\Api\Http\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Application — high-level bootstrap facade for the AUSUS Standard Stack.
 *
 * `Application` composes the kernel {@see Compiler}, the default runtime
 * ({@see Invoker} plus the policy / workflow / effect / audit services) and the
 * SQLite persistence driver behind a four-call lifecycle:
 *
 * ```php
 * $app = Application::create([
 *         'tenant' => 'acme',
 *         'roles'  => ['invoice.creator', 'invoice.issuer'],
 *     ])
 *     ->register(new HelloInvoiceDsl())
 *     ->boot();
 *
 * $created = $app->invoke('billing.invoice.create', null, [...]);
 * ```
 *
 * It is a convenience layer only — it adds **no behaviour**. Every object it
 * assembles ({@see Invoker}, {@see SqlitePersistenceDriver}, {@see PolicyEngine},
 * …) stays directly constructable for advanced wiring; nothing here removes or
 * replaces the low-level API. Booted services are exposed through
 * {@see invoker()}, {@see driver()}, {@see graph()} and {@see renderer()} so an
 * application can drop a layer at any point (custom transactions, the HTTP
 * Router, a second tenant, …).
 *
 * Scope (v0.1.x): one tenant and one actor per `Application`, matching the
 * v0.1.0 `Invoker` contract. To act as another tenant or actor, build a second
 * `Application` (or a second `Invoker` by hand).
 */
final class Application
{
    /** Config keys accepted by {@see create()}. */
    private const KNOWN_CONFIG = [
        'tenant', 'actor', 'actorId', 'roles', 'permissions',
        'database', 'kernelVersion', 'migrate', 'driver', 'auditSink',
        'apiPrefix', 'responseFactory', 'streamFactory',
    ];

    /** Default URL prefix used when {@see http()} or {@see router()} are called without one. */
    private const DEFAULT_API_PREFIX = '/api';

    /** @var list<Plugin> */
    private array $plugins = [];

    private bool $booted = false;

    private ?MetadataGraph $graph = null;
    private ?PersistenceDriver $driver = null;
    private ?Invoker $invoker = null;
    private ?ProjectionRenderer $renderer = null;
    private ?AuditSink $sink = null;
    private ?\PDO $pdo = null;
    private ?string $databasePath = null;

    /** Lazily-built Router shared by every {@see http()} call. */
    private ?Router $httpRouter = null;

    /**
     * @param \PDO|string|null $database A live PDO, a SQLite file path, or
     *                                   null / ':memory:' for an in-memory DB.
     */
    private function __construct(
        private readonly Tenant $tenant,
        private readonly Actor $actor,
        private readonly string $kernelVersion,
        private readonly bool $migrate,
        \PDO|string|null $database,
        private readonly ?PersistenceDriver $driverOverride,
        private readonly ?AuditSink $auditSinkOverride,
        private readonly ?string $apiPrefix,
        private readonly ?ResponseFactoryInterface $responseFactory,
        private readonly ?StreamFactoryInterface $streamFactory,
    ) {
        if ($database instanceof \PDO) {
            $this->pdo = $database;
        } elseif (is_string($database) && $database !== '' && $database !== ':memory:') {
            $this->databasePath = $database;
        }
    }

    /**
     * Build an Application from a config.
     *
     * Accepts either an {@see ApplicationConfig} fluent builder (recommended —
     * typed and IDE-discoverable) or the legacy associative array form.
     *
     * Array keys (all optional):
     *
     *  - `tenant`        string|Tenant — active tenant. Default: `'default'`.
     *  - `actor`         Actor — the acting actor. Overrides `actorId`/`roles`.
     *  - `actorId`       string — id for the default {@see StubActor}. Default: `'app'`.
     *  - `roles`         string[] — roles for the default actor. Default: `[]`.
     *  - `permissions`   string[] — permissions for the default actor. Default: `[]`.
     *  - `database`      \PDO|string — SQLite file path or a live PDO.
     *                    Default: an in-memory SQLite database.
     *  - `kernelVersion` string — kernel version recorded in the graph. Default: `'1.0.0'`.
     *  - `migrate`       bool — derive + apply the SQL schema on boot. Default: `true`.
     *  - `driver`        PersistenceDriver — advanced: replace the SQLite driver.
     *  - `auditSink`     AuditSink — advanced: replace the database audit sink.
     *
     * @param ApplicationConfig|array<string,mixed> $config
     * @throws \InvalidArgumentException on an unknown key or a wrongly-typed value
     */
    public static function create(ApplicationConfig|array $config = []): self
    {
        if ($config instanceof ApplicationConfig) {
            $config = $config->toArray();
        }

        $unknown = array_diff(array_keys($config), self::KNOWN_CONFIG);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Application::create(): unknown config key(s): ' . implode(', ', $unknown)
                . '. Known keys: ' . implode(', ', self::KNOWN_CONFIG) . '.'
            );
        }

        $tenant = $config['tenant'] ?? 'default';
        $tenant = $tenant instanceof Tenant ? $tenant : new Tenant(new TenantId((string) $tenant));

        $actor = $config['actor'] ?? null;
        if ($actor !== null && !$actor instanceof Actor) {
            throw new \InvalidArgumentException("Application::create(): 'actor' must implement Ausus\\Actor.");
        }
        if ($actor === null) {
            $actor = new StubActor(
                new ActorRef('user', (string) ($config['actorId'] ?? 'app'), $tenant->value()),
                array_values((array) ($config['roles'] ?? [])),
                array_values((array) ($config['permissions'] ?? [])),
            );
        }

        $driver = $config['driver'] ?? null;
        if ($driver !== null && !$driver instanceof PersistenceDriver) {
            throw new \InvalidArgumentException("Application::create(): 'driver' must implement Ausus\\PersistenceDriver.");
        }

        $auditSink = $config['auditSink'] ?? null;
        if ($auditSink !== null && !$auditSink instanceof AuditSink) {
            throw new \InvalidArgumentException("Application::create(): 'auditSink' must implement Ausus\\AuditSink.");
        }

        $database = $config['database'] ?? null;
        if ($database !== null && !$database instanceof \PDO && !is_string($database)) {
            throw new \InvalidArgumentException("Application::create(): 'database' must be a PDO or a string path.");
        }

        $apiPrefix = $config['apiPrefix'] ?? null;
        if ($apiPrefix !== null && (!is_string($apiPrefix) || $apiPrefix === '' || $apiPrefix[0] !== '/')) {
            throw new \InvalidArgumentException("Application::create(): 'apiPrefix' must be a non-empty string starting with '/'.");
        }

        $responseFactory = $config['responseFactory'] ?? null;
        if ($responseFactory !== null && !$responseFactory instanceof ResponseFactoryInterface) {
            throw new \InvalidArgumentException("Application::create(): 'responseFactory' must implement Psr\\Http\\Message\\ResponseFactoryInterface.");
        }

        $streamFactory = $config['streamFactory'] ?? null;
        if ($streamFactory !== null && !$streamFactory instanceof StreamFactoryInterface) {
            throw new \InvalidArgumentException("Application::create(): 'streamFactory' must implement Psr\\Http\\Message\\StreamFactoryInterface.");
        }

        return new self(
            tenant: $tenant,
            actor: $actor,
            kernelVersion: (string) ($config['kernelVersion'] ?? '1.0.0'),
            migrate: (bool) ($config['migrate'] ?? true),
            database: $database,
            driverOverride: $driver,
            auditSinkOverride: $auditSink,
            apiPrefix: $apiPrefix,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );
    }

    /**
     * Register one or more domain plugins. Must be called before {@see boot()}.
     *
     * @throws \RuntimeException if called after boot()
     */
    public function register(Plugin ...$plugins): self
    {
        if ($this->booted) {
            throw new \RuntimeException('Application::register(): cannot register plugins after boot().');
        }
        foreach ($plugins as $plugin) {
            $this->plugins[] = $plugin;
        }
        return $this;
    }

    /**
     * Compile the registered plugins and wire the runtime. Idempotent — calling
     * it twice is a no-op. {@see invoke()} and the accessors boot lazily, so an
     * explicit `boot()` is only needed to control *when* compilation happens.
     *
     * @throws \RuntimeException if no plugins were registered
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }
        if ($this->plugins === []) {
            throw new \RuntimeException('Application::boot(): no plugins registered — call register() first.');
        }

        $graph = (new Compiler())->compile($this->plugins, $this->kernelVersion);

        // A PDO is needed unless BOTH persistence services are user-supplied.
        $needsPdo = $this->driverOverride === null || $this->auditSinkOverride === null;
        $pdo = $needsPdo ? $this->resolvePdo() : null;

        $driver = $this->driverOverride ?? new SqlitePersistenceDriver($pdo, $graph);
        $sink   = $this->auditSinkOverride ?? new DatabaseAuditSink($pdo);

        if ($this->migrate && $pdo !== null) {
            foreach (SchemaDeriver::deriveAll($graph) as $stmt) {
                $pdo->exec($stmt);
            }
        }

        $this->graph   = $graph;
        $this->driver  = $driver;
        $this->sink    = $sink;
        $this->invoker = new Invoker(
            $graph,
            $driver,
            new PolicyEngine($graph),
            new WorkflowRuntime(new TransitionSetIndex($graph)),
            new EffectDispatcher(),
            new DefaultAuditor($sink),
            new SequenceCounter(),
            $this->tenant,
            $this->actor,
        );
        $this->renderer = new ProjectionRenderer($graph, $driver, $this->tenant);
        $this->booted   = true;

        return $this;
    }

    /**
     * Invoke an action through the full runtime chain (preflight → policy →
     * workflow guard → effect → audit). Boots the Application on first use.
     *
     * Returns the raw effect outputs as an associative array. For an
     * IDE-discoverable typed wrapper, use {@see run()} instead.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed> the effect outputs
     */
    public function invoke(string $actionFqn, ?Reference $subject = null, array $inputs = []): array
    {
        return $this->ensureBooted()->invoker->invoke($actionFqn, $subject, $inputs);
    }

    /**
     * Invoke an action and return a typed {@see InvocationResult}.
     *
     * Identical semantics to {@see invoke()} — the only difference is the
     * return type. The result carries the post-action {@see Reference}
     * (newly-created for a `create` action, the input subject for a transition)
     * alongside the raw outputs.
     *
     * @param array<string,mixed> $inputs
     */
    public function run(string $actionFqn, ?Reference $subject = null, array $inputs = []): InvocationResult
    {
        $outputs = $this->invoke($actionFqn, $subject, $inputs);
        $resolved = $subject;
        if ($resolved === null && isset($outputs['id'])) {
            $action = $this->graph->actions[$actionFqn] ?? null;
            if ($action !== null) {
                $resolved = new Reference($this->tenant->value(), $action->entityFqn, (string) $outputs['id']);
            }
        }
        return new InvocationResult($actionFqn, $resolved, $outputs);
    }

    /**
     * Render a projection to a ViewSchema array. Boots the Application on first use.
     *
     * @return array<string,mixed>
     */
    public function render(string $projectionFqn, ?Reference $subject = null): array
    {
        return $this->ensureBooted()->renderer->render($projectionFqn, $subject);
    }

    /** Build a {@see Reference} scoped to this Application's tenant. */
    public function reference(string $entityFqn, string $identityHandle): Reference
    {
        return new Reference($this->tenant->value(), $entityFqn, $identityHandle);
    }

    /** The compiled metadata graph (boots the Application if needed). */
    public function graph(): MetadataGraph
    {
        return $this->ensureBooted()->graph;
    }

    /** The wired {@see Invoker} — drop down to the low-level API from here. */
    public function invoker(): Invoker
    {
        return $this->ensureBooted()->invoker;
    }

    /** The persistence driver (boots the Application if needed). */
    public function driver(): PersistenceDriver
    {
        return $this->ensureBooted()->driver;
    }

    /** The projection renderer (boots the Application if needed). */
    public function renderer(): ProjectionRenderer
    {
        return $this->ensureBooted()->renderer;
    }

    /**
     * Convenience wrapper around {@see ProjectionRenderer::render()}: render
     * a ViewSchema for the given projection FQN in the current tenant.
     *
     * Defaults match the HTTP API surface (limit=50, offset=0) so this method
     * is byte-equivalent to GET /projections/{fqn} with no query parameters.
     */
    /**
     * @param list<\Ausus\Filter> $filters
     * @param list<\Ausus\Sort>   $sort
     */
    public function renderProjection(
        string $projectionFqn,
        ?\Ausus\Reference $subject = null,
        int $limit = 50,
        int $offset = 0,
        array $filters = [],
        array $sort = [],
    ): array {
        return $this->renderer()->render($projectionFqn, $subject, $limit, $offset, $filters, $sort);
    }

    /** The audit sink the runtime writes through. */
    public function auditSink(): AuditSink
    {
        return $this->ensureBooted()->sink;
    }

    /**
     * Build a configured {@see Router} backed by this Application's graph,
     * driver and audit sink. The Router needs PSR-7 factories — pass the same
     * implementation as both `$responses` and `$streams` if your library
     * supplies a single factory (e.g. `nyholm/psr7`).
     *
     * Hides the `Ausus\Api\Http` namespace from front controllers — typical
     * `server.php` becomes:
     *
     * ```php
     * $factory = new Psr17Factory();
     * $router  = $app->router($factory, $factory);
     * Emitter::emit($router->handle($creator->fromGlobals()));
     * ```
     */
    public function router(
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        string $pathPrefix = self::DEFAULT_API_PREFIX,
    ): Router {
        $this->ensureBooted();
        return new Router($this->graph, $this->driver, $this->sink, $responses, $streams, $pathPrefix);
    }

    /**
     * One-call HTTP entry point.
     *
     * ```php
     * Emitter::emit($app->http($creator->fromGlobals()));
     * ```
     *
     * Internally constructs a {@see Router} once and reuses it for every call
     * in the process. The Router carries the booted graph, driver and audit
     * sink, so existing tenant / actor behaviour is preserved unchanged — the
     * request's `X-Tenant-ID` and `X-Actor-*` headers still drive per-request
     * resolution.
     *
     * The PSR-17 factories are resolved (in order):
     *  1. {@see ApplicationConfig::responseFactory()} / `streamFactory()` /
     *     `psr17()` if configured;
     *  2. `Nyholm\Psr7\Factory\Psr17Factory` if it is installed (the common
     *     case — every documented example uses nyholm);
     *  3. otherwise, an `InvalidArgumentException` with a fix-it message.
     *
     * The URL prefix comes from {@see ApplicationConfig::apiPrefix()} when set,
     * otherwise `'/api'` (the Router default).
     */
    public function http(ServerRequestInterface $request): ResponseInterface
    {
        return $this->httpRouter()->handle($request);
    }

    /** Lazily resolve + cache the Router used by {@see http()}. */
    private function httpRouter(): Router
    {
        if ($this->httpRouter !== null) {
            return $this->httpRouter;
        }
        $this->ensureBooted();
        $responses = $this->responseFactory ?? self::autoDetectPsr17('responseFactory');
        $streams   = $this->streamFactory   ?? self::autoDetectPsr17('streamFactory');
        $prefix    = $this->apiPrefix ?? self::DEFAULT_API_PREFIX;
        return $this->httpRouter = new Router(
            $this->graph, $this->driver, $this->sink, $responses, $streams, $prefix,
        );
    }

    /**
     * Auto-detect a usable PSR-17 factory when none was configured. Tries the
     * one implementation every documented AUSUS example uses; otherwise raises
     * an InvalidArgumentException with a clear remediation.
     */
    private static function autoDetectPsr17(string $slot): ResponseFactoryInterface|StreamFactoryInterface
    {
        $nyholm = 'Nyholm\\Psr7\\Factory\\Psr17Factory';
        if (class_exists($nyholm)) {
            /** @var ResponseFactoryInterface&StreamFactoryInterface $f */
            $f = new $nyholm();
            return $f;
        }
        throw new \InvalidArgumentException(
            "Application::http(): no PSR-17 factory is configured and none was found in the autoloader. "
            . "Either install one (`composer require nyholm/psr7`) or configure it via "
            . "ApplicationConfig::psr17(...) or ApplicationConfig::{$slot}(...)."
        );
    }

    /**
     * The underlying PDO connection, when the built-in SQLite path is used.
     * Returns null only if a fully-custom `driver` + `auditSink` were supplied.
     */
    public function pdo(): ?\PDO
    {
        $this->ensureBooted();
        return $this->pdo;
    }

    /** The active tenant. Available before boot(). */
    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    /** The acting actor. Available before boot(). */
    public function actor(): Actor
    {
        return $this->actor;
    }

    /** Whether {@see boot()} has run. */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    private function ensureBooted(): self
    {
        if (!$this->booted) {
            $this->boot();
        }
        return $this;
    }

    private function resolvePdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }
        $dsn = $this->databasePath !== null ? 'sqlite:' . $this->databasePath : 'sqlite::memory:';
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $this->pdo = $pdo;
    }
}
