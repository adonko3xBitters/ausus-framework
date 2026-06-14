<?php
declare(strict_types=1);
namespace Ausus\Persistence\Postgres\Tests;

use Ausus\MetadataGraph;
use Ausus\Persistence\Sql\SchemaDeriver;
use Ausus\Persistence\Postgres\PostgresSchemaDeriver;

function compat_create_schema(\PDO $pdo, MetadataGraph $graph): void {
    $ddl = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql'
        ? PostgresSchemaDeriver::deriveAll($graph)
        : SchemaDeriver::deriveAll($graph);
    foreach ($ddl as $stmt) {
        $pdo->exec($stmt);
    }
}
