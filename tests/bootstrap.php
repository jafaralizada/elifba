<?php

/*
| phpunit.xml forces in-memory SQLite over whatever the shell exports (this
| machine's shell carries another project's DB_*). PHPUnit applies those
| values before it loads this file, so a leaked DB_* never reaches the suite.
|
| The one way onto PostgreSQL is an explicit opt-in that no other project
| sets: ELIFBA_TEST_PGSQL_URL=pgsql://user:password@host:port/database. The
| CI `pgsql` job and `composer test:pgsql` set it; the URL wins over every
| DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.
| tests/Feature/DatabaseEngineTest.php fails when the engine is not the one
| asked for.
*/

require __DIR__.'/../vendor/autoload.php';

$pgsqlUrl = getenv('ELIFBA_TEST_PGSQL_URL');

if (is_string($pgsqlUrl) && $pgsqlUrl !== '') {
    if (! str_starts_with($pgsqlUrl, 'pgsql://')) {
        fwrite(STDERR, "ELIFBA_TEST_PGSQL_URL must be a pgsql:// URL.\n");

        exit(1);
    }

    foreach (['DB_CONNECTION' => 'pgsql', 'DB_URL' => $pgsqlUrl] as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
