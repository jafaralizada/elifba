<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\RoomTauntData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Data\VoiceSettingsData;
use Illuminate\Support\Facades\Lang;

/**
 * The rooms' taunts in the board's voice (docs/architecture.md §3 "Voice",
 * §12 "Taunts in the middle, said aloud (T3)", ADR 029 decisions 44–50):
 * one clip per taunt of `content/rooms/taunts.json`, Nigar — the voice of
 * `content/voice.json`, its model, language and settings — saying the
 * taunt's Azerbaijani phrase (`rooms.taunts.say.{key}` of `lang/az.json`,
 * built-in text, through `voice.json`'s `respell`) after the taunt's own
 * audio tag: `[mischievously] Tısbağa da səndən tezdir!`. The provider is
 * given the taunts' own context (`taunts.json`'s `voice.context`) before
 * it and nothing after, since the board's context speaks to children; the
 * context is part of the hash, as every setting is.
 *
 * The clips are public room content with no name in them, under
 * `content/assets/rooms/taunts/{hash}.mp3`, listed under
 * `voice-clips.json`'s `taunts` by `php artisan content:voice --only=taunt`,
 * which reads no row. A room page gets a clip only by its prop's URL
 * (`url()`, versioned with `taunts.json`'s version); a taunt whose clip is
 * not made yet has none and is silent. One clip in every locale.
 */
final readonly class TauntVoices
{
    /**
     * Under `content/assets`.
     */
    public const string FOLDER = 'rooms/taunts';

    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
    ) {}

    /**
     * The voice's settings with the taunts' context before every phrase and
     * none after: what the provider is given and the hash is made of.
     */
    public function settings(): VoiceSettingsData
    {
        return self::settingsIn($this->clips, $this->content->roomTauntVoice()->context);
    }

    /**
     * The settings of a voice with a taunts' context (the validator checks
     * a voice it has not loaded through the repository).
     */
    public static function settingsIn(VoiceClips $clips, string $context): VoiceSettingsData
    {
        $voice = $clips->voice()->settings;

        return new VoiceSettingsData(
            provider: $voice->provider,
            model: $voice->model,
            voiceId: $voice->voiceId,
            voiceName: $voice->voiceName,
            language: $voice->language,
            stability: $voice->stability,
            similarity: $voice->similarity,
            previousText: $context,
            nextText: '',
        );
    }

    /**
     * A taunt's clip, or null when `lang/az.json` has no phrase for it.
     */
    public function line(RoomTauntData $taunt): ?VoiceLineData
    {
        return self::lineIn($this->clips, $this->content->roomTauntVoice()->context, $taunt->key, $taunt->voice);
    }

    /**
     * The clip of a taunt's key and tag in a voice with a taunts' context.
     */
    public static function lineIn(VoiceClips $clips, string $context, string $key, string $tag): ?VoiceLineData
    {
        $phrase = self::phrase($key);

        return $phrase === null ? null : $clips->line(VoiceLineData::TAUNT, $phrase, $tag, self::settingsIn($clips, $context));
    }

    /**
     * A taunt's Azerbaijani phrase, `rooms.taunts.say.{key}` of
     * `lang/az.json`, whatever the app's locale; null when it has none.
     */
    public static function phrase(string $key): ?string
    {
        $line = "rooms.taunts.say.{$key}";

        if (! Lang::hasForLocale($line, 'az')) {
            return null;
        }

        $phrase = Lang::get($line, [], 'az');

        return is_string($phrase) && $phrase !== '' ? $phrase : null;
    }

    /**
     * The clip of every taunt of the list, by hash, in the list's order.
     *
     * @return array<string, VoiceLineData>
     */
    public function needed(): array
    {
        $lines = [];

        foreach ($this->content->roomTaunts() as $taunt) {
            $line = $this->line($taunt);

            if ($line !== null) {
                $lines[$line->hash] ??= $line;
            }
        }

        return $lines;
    }

    /**
     * The URL of a taunt's clip when it has been made (`voice-clips.json`'s
     * `taunts` lists it), versioned with the list; null otherwise.
     */
    public function url(RoomTauntData $taunt): ?string
    {
        $line = $this->line($taunt);

        return $line !== null && isset($this->clips->voice()->taunts[$line->hash])
            ? ContentAssets::url(self::path($line->hash), $taunt->version)
            : null;
    }

    /**
     * The file of a hash, relative to `content/assets`.
     */
    public static function path(string $hash): string
    {
        return self::FOLDER.'/'.$hash.'.'.VoiceClips::EXTENSION;
    }
}
