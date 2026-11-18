<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Where a phone joins an open room (docs/architecture.md §12 "Rooms and
 * joining", "The big screen", "Games first"): `url`, the QR's address
 * `{APP_URL}/oyun/{code}`, and `entry_url`, the code field `{APP_URL}/oyun`
 * for a code typed instead (`/otaq` only redirects there, ADR 026 decision
 * 6). Built on `APP_URL`, not on the request's host, as a pairing card's
 * are (§4), so `composer dev:lan` and a proxy give phones an address they
 * can open. A closed room has no code, so no link.
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
            'url' => URL::format(Config::string('app.url'), route('rooms.join', ['code' => $room->code], false)),
            'entry_url' => self::entrance(),
        ];
    }

    /**
     * The code field's address, `{APP_URL}/oyun`: also where «Oyunlar»'s
     * scanner accepts a scanned address from (ADR 026 decision 15).
     */
    public static function entrance(): string
    {
        return URL::format(Config::string('app.url'), route('rooms.entrance', [], false));
    }
}
