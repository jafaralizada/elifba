<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomMemberVoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * `GET /rooms/{room}/members/{member}/voice` (docs/architecture.md §12
 * "Room games", ADR 030): the clip of a member's first name, which the big
 * screen says after the final's fanfare when the member holds the first
 * place — two ids in the URL, never a name or a hash. `{room}` binds only
 * a room the account is in, `{member}` only a row of it
 * (`scopeBindings()`), and the route passes `can:screen`: the host of an
 * open room, who may open the screen; a phone's session is refused. A
 * member without a clip (`RoomMemberVoice`) is a 404 before a file is
 * read. It writes nothing, not even a read of the room.
 *
 * As the board's clip (`PlayerVoiceController`, ADR 016 decision 8): kept
 * by no shared cache and by no disk (`no-store, private`), never sniffed as
 * anything but audio, and with no header that names the file. The file is
 * read quietly, so a failed read is a 404 and never a warning that would
 * carry its path, whose hash names the name.
 */
final class RoomMemberVoiceController extends Controller
{
    public function __invoke(Room $room, RoomPlayer $member, RoomMemberVoice $voice): Response
    {
        $file = $voice->file($member);
        $audio = $file === null ? false : @file_get_contents($file);

        abort_if($audio === false, 404);

        return new Response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen($audio),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
