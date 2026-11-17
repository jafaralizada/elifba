<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\NameListData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Data\RoomCategoryData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Data\RoomShapeData;
use App\Domain\Content\Data\VoiceData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\Unit;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The one reader of built-in content (docs/architecture.md §3, ADR 004):
 * levels, prompts, credits, theme packs, the voice (`voice.json` with
 * the clip list of `voice-clips.json`, §3 "Voice") and the built-in name
 * list (`names.json`, ADR 016) from a content root; and the adults' room
 * content under `rooms/` (§12 "Room content", ADR 023): from B3 the room
 * levels (`rooms/levels.json`), from B4 the shapes (`rooms/shapes.json`),
 * from B5 the groups of words (`rooms/categories.json`).
 *
 * With a compiled file (`php artisan content:cache` writes
 * `bootstrap/cache/content.php`, which OPcache keeps in memory) it reads
 * only that file. Without one it reads the JSON files, once per request
 * (the container holds one instance), so an edited pack shows on the next
 * reload. The files are trusted to be valid: `ContentValidator` checks them
 * in the test suite and before every `content:cache`.
 *
 * `content/alphabet.json` and `content/avatars.json` are read by
 * `Language\Support\Alphabet` and `Content\Support\Avatars`.
 *
 * @phpstan-import-type VoiceArray from VoiceData
 * @phpstan-import-type RoomLevelArray from RoomLevelData
 */
final class ContentRepository
{
    /**
     * The clip list `php artisan content:voice` writes, beside `voice.json`.
     */
    public const string VOICE_CLIPS = 'voice-clips.json';

    /**
     * The built-in name list (ADR 016).
     */
    public const string NAMES = 'names.json';

    /**
     * The room levels (§12 "Room content").
     */
    public const string ROOM_LEVELS = 'rooms/levels.json';

    /**
     * The shapes of memory rounds (§12 "Room content", B4).
     */
    public const string ROOM_SHAPES = 'rooms/shapes.json';

    /**
     * The groups of words of `odd_one_out` (§12 "Room content", B5).
     */
    public const string ROOM_CATEGORIES = 'rooms/categories.json';

    /**
     * @var array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>, names: mixed, rooms: array{levels: mixed, shapes: mixed, categories: mixed}}|null
     */
    private ?array $data = null;

    /**
     * @var array<string, PackData>|null
     */
    private ?array $packs = null;

    /**
     * @var array<string, LevelData>|null
     */
    private ?array $levels = null;

    /**
     * @var list<PromptTemplateData>|null
     */
    private ?array $prompts = null;

    private ?VoiceData $voice = null;

    private ?NameListData $names = null;

    /**
     * By `{mechanic}.{difficulty}`, in file order.
     *
     * @var array<string, RoomLevelData>|null
     */
    private ?array $roomLevels = null;

    /**
     * @var list<RoomShapeData>|null
     */
    private ?array $roomShapes = null;

    /**
     * @var list<RoomCategoryData>|null
     */
    private ?array $roomCategories = null;

    /**
     * @param  string  $root  the content directory (`content/`, or a fixture copy)
     * @param  string|null  $compiled  where `content:cache` writes; null = never read a compiled file
     */
    public function __construct(
        private readonly string $root,
        private readonly ?string $compiled = null,
    ) {}

    public function root(): string
    {
        return $this->root;
    }

    public function compiledPath(): ?string
    {
        return $this->compiled;
    }

    /**
     * Whether this instance reads the compiled file instead of the JSON files.
     */
    public function readsCompiled(): bool
    {
        return $this->compiled !== null && is_file($this->compiled);
    }

    /**
     * Every pack by key, in key order.
     *
     * @return array<string, PackData>
     */
    public function packs(): array
    {
        return $this->packs ??= array_map(PackData::fromArray(...), $this->data()['packs']);
    }

    public function hasPack(string $key): bool
    {
        return isset($this->packs()[$key]);
    }

    /**
     * @throws InvalidArgumentException when there is no such pack
     */
    public function pack(string $key): PackData
    {
        return $this->packs()[$key] ?? throw new InvalidArgumentException("There is no pack \"{$key}\".");
    }

    /**
     * Every level by key, in file order.
     *
     * @return array<string, LevelData>
     */
    public function levels(): array
    {
        if ($this->levels === null) {
            $this->levels = [];

            foreach ($this->data()['levels'] as $level) {
                $level = LevelData::fromArray($level);
                $this->levels[$level->key] = $level;
            }
        }

        return $this->levels;
    }

    /**
     * @throws InvalidArgumentException when there is no such level
     */
    public function level(string $key): LevelData
    {
        return $this->levels()[$key] ?? throw new InvalidArgumentException("There is no level \"{$key}\".");
    }

    /**
     * @return list<PromptTemplateData>
     */
    public function prompts(): array
    {
        return $this->prompts ??= array_map(PromptTemplateData::fromArray(...), $this->data()['prompts']);
    }

    /**
     * @throws InvalidArgumentException when no template exists for the pair
     */
    public function prompt(Mechanic $mechanic, Unit $unit): PromptTemplateData
    {
        foreach ($this->prompts() as $prompt) {
            if ($prompt->mechanic === $mechanic && $prompt->unit === $unit) {
                return $prompt;
            }
        }

        throw new InvalidArgumentException("There is no prompt for {$mechanic->value} × {$unit->value}.");
    }

    /**
     * @return list<CreditData>
     */
    public function credits(): array
    {
        return array_map(CreditData::fromArray(...), $this->data()['credits']);
    }

    /**
     * The voice and its clips (ADR 015). Read through `VoiceClips`.
     */
    public function voice(): VoiceData
    {
        if ($this->voice === null) {
            /** @var VoiceArray $data checked by `ContentValidator` */
            $data = $this->data()['voice'];
            $this->voice = VoiceData::fromArray($data);
        }

        return $this->voice;
    }

    /**
     * The first names the board can say (ADR 016). Read through
     * `NameVoices`.
     */
    public function names(): NameListData
    {
        if ($this->names === null) {
            /** @var array{version: int, sources: list<string>, names: list<string>} $data checked by `ContentValidator` */
            $data = $this->data()['names'];
            $this->names = NameListData::fromArray($data);
        }

        return $this->names;
    }

    /**
     * Every room level, in file order: one per mechanic and difficulty.
     *
     * @return list<RoomLevelData>
     */
    public function roomLevels(): array
    {
        return array_values($this->roomLevelsByKey());
    }

    /**
     * @throws InvalidArgumentException when the mechanic has no level of that difficulty
     */
    public function roomLevel(RoomMechanic $mechanic, int $difficulty): RoomLevelData
    {
        return $this->roomLevelsByKey()["{$mechanic->value}.{$difficulty}"]
            ?? throw new InvalidArgumentException("There is no room level {$mechanic->value} × {$difficulty}.");
    }

    /**
     * The version of the room content (`rooms/levels.json`'s `version`),
     * which a room game keeps as `content_version`.
     */
    public function roomVersion(): int
    {
        /** @var array{version: int} $data checked by `ContentValidator` */
        $data = $this->data()['rooms']['levels'];

        return $data['version'];
    }

    /**
     * The shapes a `recall_order` round may show, in file order.
     *
     * @return list<RoomShapeData>
     */
    public function roomShapes(): array
    {
        if ($this->roomShapes === null) {
            /** @var array{version: int, shapes: list<array{key: string, image: string}>} $data checked by `ContentValidator` */
            $data = $this->data()['rooms']['shapes'];
            $this->roomShapes = array_map(static fn (array $shape): RoomShapeData => RoomShapeData::fromArray($shape, $data['version']), $data['shapes']);
        }

        return $this->roomShapes;
    }

    /**
     * The groups of words `odd_one_out` draws from, in file order (B5).
     *
     * @return list<RoomCategoryData>
     */
    public function roomCategories(): array
    {
        if ($this->roomCategories === null) {
            /** @var array{version: int, groups: list<array{key: string, words: list<string>}>} $data checked by `ContentValidator` */
            $data = $this->data()['rooms']['categories'];
            $this->roomCategories = array_map(RoomCategoryData::fromArray(...), $data['groups']);
        }

        return $this->roomCategories;
    }

    /**
     * @return array<string, RoomLevelData>
     */
    private function roomLevelsByKey(): array
    {
        if ($this->roomLevels === null) {
            /** @var array{version: int, levels: list<RoomLevelArray>} $data checked by `ContentValidator` */
            $data = $this->data()['rooms']['levels'];
            $this->roomLevels = [];

            foreach ($data['levels'] as $level) {
                $level = RoomLevelData::fromArray($level);
                $this->roomLevels["{$level->mechanic->value}.{$level->difficulty}"] = $level;
            }
        }

        return $this->roomLevels;
    }

    /**
     * The content as the JSON files hold it now, ignoring any compiled file:
     * what `content:cache` compiles.
     *
     * @return array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>, names: mixed, rooms: array{levels: mixed, shapes: mixed, categories: mixed}}
     *
     * @throws RuntimeException when a file is missing or not valid JSON
     */
    public function sources(): array
    {
        $packs = [];

        foreach (self::packFiles($this->root) as $key => $path) {
            $packs[$key] = self::decode($path);
        }

        return [
            'levels' => self::listIn($this->root.'/levels.json', 'levels'),
            'prompts' => self::listIn($this->root.'/prompts.json', 'prompts'),
            'credits' => self::listIn($this->root.'/credits.json', 'credits'),
            'packs' => $packs,
            'voice' => self::voiceIn($this->root),
            'names' => self::decode($this->root.'/'.self::NAMES),
            'rooms' => [
                'levels' => self::decode($this->root.'/'.self::ROOM_LEVELS),
                'shapes' => self::decode($this->root.'/'.self::ROOM_SHAPES),
                'categories' => self::decode($this->root.'/'.self::ROOM_CATEGORIES),
            ],
        ];
    }

    /**
     * The pack files of a content root, by key (the file name), in key order.
     *
     * @return array<string, string>
     */
    public static function packFiles(string $root): array
    {
        $files = [];

        foreach (glob($root.'/packs/*.json') ?: [] as $path) {
            $files[basename($path, '.json')] = $path;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @throws RuntimeException when the file is missing or not valid JSON
     */
    public static function decode(string $path): mixed
    {
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new RuntimeException('Cannot read '.basename($path).'.');
        }

        try {
            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(basename($path).' is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>, names: mixed, rooms: array{levels: mixed, shapes: mixed, categories: mixed}}
     */
    private function data(): array
    {
        if ($this->data === null) {
            /** @var array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>, names: mixed, rooms: array{levels: mixed, shapes: mixed, categories: mixed}} $compiled */
            $compiled = $this->readsCompiled() ? require $this->compiled : $this->sources();
            $this->data = $compiled;
        }

        return $this->data;
    }

    /**
     * `voice.json` with `clips` and `name_clips`, the clip lists
     * `content:voice` writes to `voice-clips.json` (`clips`, `names`).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when a file is missing, not valid JSON or not an object
     */
    public static function voiceIn(string $root): array
    {
        $voice = self::decode($root.'/voice.json');
        $clips = self::decode($root.'/'.self::VOICE_CLIPS);

        if (! is_array($voice) || ! is_array($clips) || ! is_array($clips['clips'] ?? null) || ! is_array($clips['names'] ?? [])) {
            throw new RuntimeException('voice.json and '.self::VOICE_CLIPS.' must be objects, the second with `clips` and `names`.');
        }

        return [...$voice, 'clips' => $clips['clips'], 'name_clips' => $clips['names'] ?? []];
    }

    /**
     * @return list<mixed>
     */
    private static function listIn(string $path, string $key): array
    {
        $decoded = self::decode($path);
        $list = is_array($decoded) ? ($decoded[$key] ?? null) : null;

        if (! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException(basename($path)." has no list of {$key}.");
        }

        return $list;
    }
}
