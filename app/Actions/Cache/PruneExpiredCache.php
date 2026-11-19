<?php

namespace App\Actions\Cache;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the database cache's expired rows and locks (docs/architecture.md
 * §10, §12 "Live voice"; ADR 031 decision 7 as C5's audit found it):
 * `cache:prune-expired`, every minute. Laravel's database store deletes an
 * expired row only when that same key is read again, so a rate limiter's
 * count — `room-voice`'s is keyed by the account — would otherwise stay in
 * the table, and in every backup, until that account's next request. With
 * this a count lives at most its minute and one more; the backup leaves
 * the cache out (`docker/backup/elifba-backup.sh`). A row stored forever
 * stays.
 */
final class PruneExpiredCache
{
    /** @return int how many rows and locks were deleted */
    public function handle(): int
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $connection = self::name('connection');
        $deleted = DB::connection($connection)
            ->table(self::name('table') ?? 'cache')
            ->where('expiration', '<=', $now)
            ->delete();

        return $deleted + DB::connection(self::name('lock_connection') ?? $connection)
            ->table(self::name('lock_table') ?? 'cache_locks')
            ->where('expiration', '<=', $now)
            ->delete();
    }

    /** A name the database store is configured with, or null for the default. */
    private static function name(string $key): ?string
    {
        $value = Config::get("cache.stores.database.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
