<?php

namespace App\Domain\Rooms\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The voice server did not answer a call in time, refused it or answered
 * with an error (docs/architecture.md §12 "Live voice", ADR 031 decisions
 * 19 and 22). It names the call, the voice room by its name — a room id
 * and an epoch — and the cause by its class or status only: never a
 * ticket, a token, a secret, a name or an identity. A ticket request that
 * meets it answers 503 `{status: "unavailable"}`; an action's cut turns it
 * into `RoomVoiceNotCut`. Reported as one line without the account
 * (`report()`).
 */
final class RoomVoiceUnreachable extends RuntimeException
{
    public function __construct(
        public readonly string $call,
        public readonly ?string $voiceRoom,
        public readonly string $cause,
    ) {
        parent::__construct('The voice server did not answer '.$call.($voiceRoom === null ? '' : " for {$voiceRoom}").": {$cause}.");
    }

    /**
     * One line with the call, the voice room and the cause, and no
     * context: Laravel would add the account's id to a reported
     * exception, and a refused ticket would then keep who tried to join
     * which room's voice and when (ADR 031 decision 7; C5, the audit).
     */
    public function report(): void
    {
        Log::warning($this->getMessage());
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['status' => 'unavailable'], 503, ['Cache-Control' => 'no-store, private']);
    }
}
