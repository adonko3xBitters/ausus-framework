<?php
declare(strict_types=1);
namespace Ausus\Persistence\Postgres\Tests;

function sqlite_pdo(): \PDO {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function pgsql_pdo(): \PDO {
    $pdo = new \PDO(
        (string) getenv('AUSUS_PG_DSN'),
        (string) getenv('AUSUS_PG_USER'),
        (string) getenv('AUSUS_PG_PASS'),
    );
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    return $pdo;
}
