<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Content\Support\NameVoices;
use App\Domain\Rooms\Models\RoomPlayer;

/**
 * The clip of a room member's first name, for the final on the big screen
 * (docs/architecture.md §12 "Room games", ADR 030): the built-in name
 * list's clip of the account's name, «Nihat!», as `NameVoices` matches a
 * display name — `users.name` is whatever the account wrote, and this
 * class adds no matching of its own. Null for a row the host removed (no
 * longer listed in the room), a row whose account is gone, and a name off
 * the list or without a clip.
 *
 * It gives a path on disk, never a URL: a clip's hash can be computed from
 * the public list, so it must not travel (ADR 016 decision 8). The screen
 * gets the file by ids only (`RoomMemberVoiceController`); when it is said
 * is the page's rule (`roomCues()`), so this reads no ranking.
 */
final readonly class RoomMemberVoice
{
    public function __construct(private NameVoices $names) {}

    public function file(RoomPlayer $member): ?string
    {
        $account = $member->removed_at === null ? $member->user : null;

        return $account === null ? null : $this->names->file($account->name);
    }
}
