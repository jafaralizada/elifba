<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Content\Support\TauntVoices;
use App\Domain\Content\Support\VoiceClips;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes `content/voice-clips.json` from the files of `content/assets/voice`,
 * `content/voice-names` and `content/assets/rooms/taunts`
 * (docs/architecture.md §3 "Voice", ADR 016, ADR 029 decision 45): `clips`,
 * every clip content needs that has a file, with the text it was made
 * from, in the order of `VoiceClips::needed()`; `names`, the same for the
 * names of the built-in list, in list order; `taunts`, the same for the
 * room's taunts, in the list's order. Then, in each, the listed
 * clips nothing needs any more whose file is still there, until
 * `content:voice --prune` deletes them. Flat objects, so the file is
 * already as the formatter writes it. `names` is the list, public anyway:
 * it says nothing about who bears a name.
 */
final readonly class WriteVoiceClipList
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
        private NameVoices $names,
        private TauntVoices $taunts,
        private Filesystem $files,
    ) {}

    /**
     * @return array{clips: array<string, string>, names: array<string, string>, taunts: array<string, string>} hash → text, as written
     */
    public function handle(): array
    {
        // The lists on disk now, not the ones this process started with.
        $listed = ContentRepository::voiceIn($this->content->root());
        $lists = [
            'clips' => $this->list($this->clips->needed($this->content), $listed['clips'], GenerateVoiceClips::folder(VoiceLineData::PRAISE)),
            'names' => $this->list($this->names->needed(), $listed['name_clips'], GenerateVoiceClips::folder(VoiceLineData::NAME)),
            'taunts' => $this->list($this->taunts->needed(), $listed['taunt_clips'], GenerateVoiceClips::folder(VoiceLineData::TAUNT)),
        ];

        $this->files->put(
            $this->content->root().'/'.ContentRepository::VOICE_CLIPS,
            json_encode(array_map(static fn (array $list): object => (object) $list, $lists), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        return $lists;
    }

    /**
     * @param  array<string, VoiceLineData>  $needed
     * @return array<string, string>
     */
    private function list(array $needed, mixed $listed, string $folder): array
    {
        $list = [];

        foreach ($needed as $hash => $line) {
            if ($this->files->isFile($this->file((string) $hash, $folder))) {
                $list[(string) $hash] = $line->text;
            }
        }

        foreach (is_array($listed) ? $listed : [] as $hash => $text) {
            $hash = (string) $hash;

            if (! isset($list[$hash]) && is_string($text) && $this->files->isFile($this->file($hash, $folder))) {
                $list[$hash] = $text;
            }
        }

        return $list;
    }

    private function file(string $hash, string $folder): string
    {
        return $this->content->root().'/'.$folder.'/'.$hash.'.'.VoiceClips::EXTENSION;
    }
}
