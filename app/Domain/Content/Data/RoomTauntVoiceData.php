<?php

namespace App\Domain\Content\Data;

/**
 * The `voice` block of `content/rooms/taunts.json` (ADR 029 decisions 45,
 * 46 and 50): the Azerbaijani context the provider is given before every
 * taunt's phrase and never says — a game, not the board's context, which
 * speaks to children — and the credits entry the taunts' clips get with
 * the first one (`content/assets/rooms/taunts/*`), `{month}` in its
 * `changes` the month of generation.
 *
 * @phpstan-type CreditArray array{title: string, author: string, source: string, license: string, changes: string}
 * @phpstan-type RoomTauntVoiceArray array{context: string, credit: CreditArray}
 */
final readonly class RoomTauntVoiceData
{
    /**
     * @param  CreditArray  $credit
     */
    public function __construct(
        public string $context,
        public array $credit,
    ) {}

    /**
     * @param  RoomTauntVoiceArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['context'], $data['credit']);
    }
}
