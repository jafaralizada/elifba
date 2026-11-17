<?php

use Illuminate\Support\Facades\DB;

// The suite runs on in-memory SQLite unless ELIFBA_TEST_PGSQL_URL asks for
// PostgreSQL (tests/bootstrap.php); a run that asked for PostgreSQL and got
// SQLite fails here instead of passing on the wrong engine.
it('runs on the engine the run asked for', function () {
    $pgsqlUrl = getenv('ELIFBA_TEST_PGSQL_URL');
    $askedForPgsql = is_string($pgsqlUrl) && $pgsqlUrl !== '';

    expect(DB::connection()->getDriverName())->toBe($askedForPgsql ? 'pgsql' : 'sqlite');

    if (! $askedForPgsql) {
        expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    }
});
