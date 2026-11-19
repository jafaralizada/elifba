<?php

namespace App\Domain\Content\Data;

/**
 * One clip the voice says (docs/architecture.md §3 "Voice"): the phrase as
 * the adult would read it, the text the provider is given for it (its audio
 * tag in front, a respelling where `voice.json` has one) and the hash of
 * that text with the voice, which names the file. `group` says where it
 * comes from: a template (`find.picture`), `praise`, `retry`, `greeting`,
 * `finish`, `finish_class`, `name`, a first name of the built-in list
 * (ADR 016), whose clip lives apart and never has a public URL, or
 * `taunt`, a room's taunt (ADR 029 as amended by T3), whose clip lives under
 * `content/assets/rooms/taunts` and is made with the taunts' own context.
 */
final readonly class VoiceLineData
{
    public const string PRAISE = 'praise';

    public const string RETRY = 'retry';

    public const string GREETING = 'greeting';

    public const string FINISH = 'finish';

    public const string FINISH_CLASS = 'finish_class';

    public const string NAME = 'name';

    public const string TAUNT = 'taunt';

    public function __construct(
        public string $group,
        public string $phrase,
        public string $text,
        public string $hash,
    ) {}

    public function isName(): bool
    {
        return $this->group === self::NAME;
    }

    public function isTaunt(): bool
    {
        return $this->group === self::TAUNT;
    }
}
