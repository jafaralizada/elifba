<?php

namespace App\Domain\Rooms\Enums;

/**
 * The shape of an answer in a room (docs/architecture.md §12 "Room games",
 * ADR 023 decision 4), always made of the round's own item ids — a player
 * never types: `choice` one id, `set` ids in any order, `sequence` ids in
 * order, judged by what the items say. `RoomJudge` knows all three.
 */
enum RoomAnswerShape: string
{
    case Choice = 'choice';
    case Set = 'set';
    case Sequence = 'sequence';
}
