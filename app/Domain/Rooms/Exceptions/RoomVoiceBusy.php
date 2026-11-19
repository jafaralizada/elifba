<?php

namespace App\Domain\Rooms\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The instance has no room for another voice in use (docs/architecture.md
 * §12 "Live voice", ADR 031 decision 38 as amended 2026-11-19): a ticket
 * for a voice room nobody is in yet, while `elifba.rooms.voice.max_rooms`
 * other voice rooms have someone in them. A ticket request that meets it
 * answers 503 `{status: "busy"}`, and the page says
 * `rooms.voice.errors.busy` with «Yenidən cəhd et» and never tries again
 * by itself. It is the limit doing its work, not a failure: never
 * reported, and it names nothing.
 */
final class RoomVoiceBusy extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('No room for another live voice in use.');
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['status' => 'busy'], 503, ['Cache-Control' => 'no-store, private']);
    }
}
