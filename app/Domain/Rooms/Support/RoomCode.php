<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Every room code (docs/architecture.md §12 "Rooms and joining", ADR 020
 * decision 3): 6 digits, unique by the `rooms.code` column, valid while its
 * room is open and cleared when it closes. A code space and an entrance of
 * its own (`/otaq`): it knows nothing of the pairing codes, so the same six
 * digits may be a room and a board at once, each unknown at the other's
 * entrance. A code admits a signed-in, confirmed player into that one room
 * (`JoinRoom`), and nothing else.
 *
 * `issue()` relies on the unique index alone: a code still held by any
 * room — an idle one too — makes the store fail, and the next code is
 * tried.
 */
final class RoomCode
{
    public const int DIGITS = 6;

    private const int ATTEMPTS = 20;

    /**
     * @param  (Closure(): string)|null  $generator  tests force codes with it
     */
    public function __construct(private ?Closure $generator = null) {}

    /**
     * Calls `$store` with a new code until one is stored. `$store` writes in
     * its own transaction, so a unique violation rolls back only that
     * attempt (a savepoint inside an outer transaction).
     *
     * @template T
     *
     * @param  Closure(string): T  $store
     * @return T
     */
    public function issue(Closure $store): mixed
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                return $store($this->next());
            } catch (UniqueConstraintViolationException) {
                // Held by another room: try the next one.
            }
        }

        throw new RuntimeException('No free room code after '.self::ATTEMPTS.' attempts.');
    }

    /**
     * The open room a code opens, or null — unknown, malformed, or its room
     * closed or idle — so the caller answers one 404 for all of them.
     */
    public static function resolve(mixed $code): ?Room
    {
        $code = self::normalize($code);

        if ($code === null) {
            return null;
        }

        return Room::query()->open()->where('code', $code)->first();
    }

    /**
     * The 6 digits of what a player typed ("482 913", "482-913"), or null.
     */
    public static function normalize(mixed $input): ?string
    {
        if (! is_string($input) && ! is_int($input)) {
            return null;
        }

        $digits = preg_replace('/[\s\-]+/u', '', (string) $input) ?? '';

        return preg_match('/^\d{'.self::DIGITS.'}$/D', $digits) === 1 ? $digits : null;
    }

    private function next(): string
    {
        if ($this->generator !== null) {
            return ($this->generator)();
        }

        return str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
