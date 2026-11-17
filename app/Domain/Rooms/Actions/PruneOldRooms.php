<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the rooms created longer than `elifba.retention_days` ago
 * (docs/architecture.md §1 "Scheduled", §12 "Scoring and what is kept",
 * ADR 022 decision 6), with everything under them by cascade. Scheduled
 * daily (`rooms:prune` in routes/console.php).
 */
final readonly class PruneOldRooms
{
    /**
     * @return int how many rooms were deleted
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(Config::integer('elifba.retention_days'));

        return DB::transaction(static fn (): int => Room::query()->where('created_at', '<', $cutoff)->delete());
    }
}
