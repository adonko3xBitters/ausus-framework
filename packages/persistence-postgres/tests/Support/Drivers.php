<?php
declare(strict_types=1);
namespace Ausus\Persistence\Postgres\Tests;

use Ausus\MetadataGraph;
use Ausus\Persistence\Sql\SqlitePersistenceDriver;
use Ausus\Persistence\Postgres\PostgresPersistenceDriver;

/** @return list<array{0:string,1:\Ausus\PersistenceDriver,2:\PDO}> */
function compat_drivers(MetadataGraph $graph): array {
    $sqlitePdo = sqlite_pdo();
    $drivers = [
        ['sqlite', new SqlitePersistenceDriver($sqlitePdo, $graph), $sqlitePdo],
    ];

    // PostgreSQL branch — activates automatically once the driver class exists
    // (C1) AND the runtime/env support it. Until then, returns SQLite only.
    if (extension_loaded('pdo_pgsql')
        && getenv('AUSUS_PG_DSN') !== false
        && getenv('AUSUS_PG_USER') !== false
        && getenv('AUSUS_PG_PASS') !== false
        && class_exists(PostgresPersistenceDriver::class)
    ) {
        $pgPdo = pgsql_pdo();
        $drivers[] = ['postgres', new PostgresPersistenceDriver($pgPdo, $graph), $pgPdo];
    }

    return $drivers;
}
