<?php
declare(strict_types=1);

/**
 * Issue tracker — HTTP front controller.
 *
 *   php -S 127.0.0.1:8787 -t public public/server.php
 *
 * Persistence: SQLite at $AUSUS_DB_PATH (default: ../tracker.sqlite).
 * Schema is applied idempotently on boot; data must be seeded via
 * `composer seed` (or `php bin/seed.php`) before the UI has anything to render.
 */

$autoload = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
foreach ($autoload as $f) { if (file_exists($f)) { require $f; break; } }

use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use IssueTracker\IssueTrackerPlugin;

$dbPath = getenv('AUSUS_DB_PATH') ?: (__DIR__ . '/../tracker.sqlite');
$factory = new Psr17Factory();

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->actorId('http')
            ->roles(['tracker.member', 'tracker.admin', 'tracker.viewer'])
            ->sqlite($dbPath)
            ->psr17($factory)
    )
    ->register(new IssueTrackerPlugin())
    ->boot();

$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
