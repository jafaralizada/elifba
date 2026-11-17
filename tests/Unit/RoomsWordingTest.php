<?php

use App\Domain\Language\Support\Az;

/*
 * No room text promises a benefit (docs/architecture.md §12 "No promise",
 * ADR 023 decision 12, ADR 024 decision 8): no string of the player area,
 * of the welcome page's section for rooms, of the privacy notice's
 * «Otaqlar» or of the READMEs' sections for rooms says that a game improves
 * memory, the mind, attention or health. A game is named by what one does.
 */

/**
 * Stems of a promise, matched inside words: «inkişaf» catches «inkişaf
 * etdirir», "improve" catches "improves". Each is a stem that no room text
 * needs for anything else.
 */
const ROOM_PROMISES = [
    // Azerbaijani: develop, strengthen, healthy, improve, sharpen, exercise,
    // intelligence, benefit, cure, dementia, rejuvenate, brain.
    'inkişaf', 'gücləndir', 'güclənir', 'sağlam', 'yaxşılaşdır', 'yaxşılaşır', 'itiləşdir', 'məşq',
    'zəka', 'intellekt', 'fayda', 'müalicə', 'demensiya', 'alsheymer', 'cavanlaş', 'beyin', 'beyn',
    // English.
    'improve', 'boost', 'train your', 'brain', 'sharpen', 'enhance', 'cognitive', 'smarter',
    'intelligence', 'health', 'benefit', 'dementia', 'alzheimer', 'workout', 'keep your mind', 'mental',
];

/**
 * The stems of a promise a text holds, whatever its case: an Azerbaijani
 * text is lowered with Azerbaijani casing («İnkişaf» → «inkişaf»), an
 * English one with the plain rules ("IMPROVE" → "improve"), and a stem
 * found by either counts.
 *
 * @return list<string>
 */
function promisesInRoomText(string $text): array
{
    $lowered = [Az::lower($text), mb_strtolower($text)];

    return array_values(array_filter(
        ROOM_PROMISES,
        static fn (string $stem): bool => array_any($lowered, static fn (string $lower): bool => str_contains($lower, $stem)),
    ));
}

/**
 * A README's section for rooms: from its heading to the next one. Its other
 * sections are not room texts — README.az.md's «İnkişaf» is "Develop".
 */
function roomSectionOf(string $readme, string $heading): string
{
    $text = (string) file_get_contents(base_path($readme));
    $start = strpos($text, "\n## {$heading}\n");

    expect($start)->not->toBeFalse("{$readme} has no section «{$heading}»");

    $end = strpos($text, "\n## ", (int) $start + 1);

    return substr($text, (int) $start, $end === false ? null : $end - (int) $start);
}

it('promises no benefit in any room text', function (): void {
    $texts = [];

    foreach (['az', 'en'] as $locale) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach ($translations as $key => $text) {
            $room = str_starts_with($key, 'rooms.')
                || str_starts_with($key, 'welcome.rooms.')
                || str_starts_with($key, 'privacy.rooms.')
                || str_starts_with($key, 'roles.player.')
                || in_array($key, ['roles.agreement.player', 'roles.settings.add.player', 'nav.rooms'], true);

            if ($room) {
                $texts["{$locale}: {$key}"] = $text;
            }
        }
    }

    $texts['README.md: Rooms'] = roomSectionOf('README.md', 'Rooms, for adults');
    $texts['README.az.md: Otaqlar'] = roomSectionOf('README.az.md', 'Otaqlar, böyüklər üçün');

    expect(count($texts))->toBeGreaterThan(300);

    $found = array_filter(array_map(promisesInRoomText(...), $texts));

    expect($found)->toBe([], 'docs/architecture.md §12 "No promise": a room text names what one does, never a benefit.');
});

it('finds a promise however it is cased, and nothing in a plain name', function (): void {
    expect(promisesInRoomText('YADDAŞINIZI İNKİŞAF ETDİRİN'))->toBe(['inkişaf'])
        ->and(promisesInRoomText('Beyninizi gücləndirin'))->toBe(['gücləndir', 'beyn'])
        ->and(promisesInRoomText('Sağlam düşüncə'))->toBe(['sağlam'])
        ->and(promisesInRoomText('IMPROVES your Memory'))->toBe(['improve'])
        ->and(promisesInRoomText('Train your BRAIN'))->toBe(['train your', 'brain'])
        ->and(promisesInRoomText('Artıq olanı tap · Ardıcıllığı yadda saxla · İki sıra'))->toBe([])
        ->and(promisesInRoomText('Remember the order · Find the odd one out'))->toBe([]);
});
