<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;

/**
 * `prompt.audio` of a built round (docs/architecture.md §3 "Voice", ADR
 * 015): the URL of the clip that says the prompt, or null when there is
 * none and the board is silent for it.
 *
 * Every prompt whose clip is listed in `voice-clips.json` is voiced; a
 * teacher word without a clip (ADR 007) returns null from `VoiceClips::url()`
 * and the board stays silent for it.
 */
final readonly class PromptVoice
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
    ) {}

    /**
     * @param  string|null  $target  the content key the prompt names (`word:dovşan`); null when it names none
     * @param  PackData  $pack  the pack as the game's owner plays it (`TeacherPacks`)
     */
    public function url(PromptTemplateData $template, ?string $target, PackData $pack): ?string
    {
        if (! $template->takesText()) {
            $target = null;
        }

        $builtIn = $this->content->hasPack($pack->key) ? $this->content->pack($pack->key) : $pack;
        $line = $this->clips->prompt($template, $target, $builtIn);

        return $line === null ? null : $this->clips->url($line);
    }
}
