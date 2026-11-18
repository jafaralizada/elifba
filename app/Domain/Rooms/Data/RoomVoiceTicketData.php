<?php

namespace App\Domain\Rooms\Data;

/**
 * The answer to a ticket request (docs/architecture.md §12 "Live voice",
 * ADR 031 decision 19): the address a browser opens and the ticket it
 * opens it with. JSON with `no-store`, to the one page that asked; never
 * logged, never in a redirect, a URL of the app or a broadcast.
 *
 * @phpstan-type RoomVoiceTicketArray array{url: string, token: string}
 */
final readonly class RoomVoiceTicketData
{
    public function __construct(
        public string $url,
        public string $token,
    ) {}

    /**
     * @return RoomVoiceTicketArray
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'token' => $this->token,
        ];
    }
}
