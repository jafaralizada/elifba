<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Where a phone joins an open room (docs/architecture.md §12 "Rooms and
 * joining", "The big screen", "Games first", "The invitation link (I2)"):
 * `url`, the QR's and the shared link's address `{APP_URL}/oyun/{code}?t={tag}`,
 * and `entry_url`, the code field `{APP_URL}/oyun` for a code typed instead
 * (`/otaq` only redirects there, ADR 026 decision 6). Built on `APP_URL`
 * (without a port its scheme implies), not on the request's host, as a
 * pairing card's are (§4), so `composer dev:lan` and a proxy give phones an
 * address they can open. A closed room has no code, so no link.
 *
 * The tag (ADR 033 decision 5) says which room a link was made for: codes
 * are unique only among open rooms, so a link opened weeks later from a
 * chat must not reach a stranger's room that drew the same six digits. It
 * is derived each time from the room's id and `APP_KEY`, stored nowhere,
 * and grants nothing — a code still admits, and a link without a tag is as
 * a typed code.
 *
 * @phpstan-type RoomJoinLinkArray array{url: string, entry_url: string}
 */
final class RoomJoinLink
{
    /**
     * @return RoomJoinLinkArray|null
     */
    public static function of(Room $room): ?array
    {
        if (! $room->isOpen() || $room->code === null) {
            return null;
        }

        return [
            'url' => URL::format(self::root(), route('rooms.join', ['code' => $room->code, 't' => self::tag($room)], false)),
            'entry_url' => self::entrance(),
        ];
    }

    /**
     * The code field's address, `{APP_URL}/oyun`: also where «Oyunlar»'s
     * scanner accepts a scanned address from (ADR 026 decision 15).
     */
    public static function entrance(): string
    {
        return URL::format(self::root(), route('rooms.entrance', [], false));
    }

    /**
     * `APP_URL` without a port its scheme already implies, as a browser
     * writes an address: docker-compose builds `https://elifba.io:443`, and
     * a link sent in a chat reads `https://elifba.io/oyun/…` (ADR 033 "As
     * built (I2)"). Any other port stays: `composer dev:lan`'s `:8000`.
     */
    private static function root(): string
    {
        return (string) preg_replace('#^(?:(https://[^/:]+):443|(http://[^/:]+):80)(?=/|$)#i', '$1$2', rtrim(Config::string('app.url'), '/'));
    }

    /** The room's tag: the first six lowercase hex characters of an HMAC of its id under `APP_KEY`. */
    public static function tag(Room $room): string
    {
        return substr(hash_hmac('sha256', 'room-link:'.$room->id, Config::string('app.key')), 0, 6);
    }

    /** Whether a link's tag is this room's; a link without one (null) is as a typed code. */
    public static function tagMatches(Room $room, ?string $tag): bool
    {
        return $tag === null || hash_equals(self::tag($room), $tag);
    }
}
