<?php

use App\Domain\Language\Support\Az;

/*
 * No room text promises a benefit (docs/architecture.md §12 "No promise",
 * ADR 023 decision 12, ADR 024 decision 8): no string of the player area,
 * of the welcome page's section for adults' games, of the privacy notice's
 * «Böyüklərin oyunları» or of the READMEs' «Böyüklər üçün oyunlar» / "Games
 * for adults" (ADR 026 decision 5) says that a game improves memory, the
 * mind, attention or health. A game is named by what one does.
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
                || in_array($key, ['roles.agreement.player', 'roles.settings.add.player', 'nav.rooms', 'nav.rooms_past'], true);

            if ($room) {
                $texts["{$locale}: {$key}"] = $text;
            }
        }
    }

    $texts['README.md: Games for adults'] = roomSectionOf('README.md', 'Games for adults');
    $texts['README.az.md: Böyüklər üçün oyunlar'] = roomSectionOf('README.az.md', 'Böyüklər üçün oyunlar');

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

it('says «otaq» only where the thing itself is named', function (): void {
    // ADR 026 decision 2: «oyun» is what a person does; «otaq» only where a
    // text must name the thing that holds the players and several games —
    // the host's tools, the standing across games, a closed room, the
    // player role's sentence and `/privacy`.
    $allowed = [
        'roles.agreement.player', 'roles.player.description',
        'rooms.flash.deleted', 'rooms.game.standing',
        'rooms.show.close', 'rooms.show.close_title', 'rooms.show.close_description', 'rooms.show.closed',
        'rooms.show.delete', 'rooms.show.delete_title', 'rooms.show.delete_line', 'rooms.show.forget_line',
        'rooms.show.locked', 'rooms.show.no_games', 'rooms.show.remove_description', 'rooms.show.tools',
    ];

    /** @var array<string, string> $az */
    $az = json_decode((string) file_get_contents(lang_path('az.json')), true);
    $found = [];

    foreach ($az as $key => $text) {
        $area = str_starts_with($key, 'rooms.') || str_starts_with($key, 'welcome.rooms.') || str_starts_with($key, 'nav.rooms')
            || str_starts_with($key, 'roles.player.') || $key === 'roles.agreement.player';

        if ($area && ! in_array($key, $allowed, true) && preg_match('/\botaq|\botağ/u', Az::lower($text)) === 1) {
            $found[] = $key;
        }
    }

    expect($found)->toBe([])
        ->and(Az::lower(roomSectionOf('README.az.md', 'Böyüklər üçün oyunlar')))->not->toContain('«otaq aç»')
        ->and($az)->not->toHaveKey('rooms.show.title');
});

it('words the rivalry strip in exactly the strings ADR 027 lists, in both languages', function (): void {
    // ADR 027 decision 19: the strip's and the lanes' words, nothing more.
    $keys = ['ahead', 'away', 'behind', 'label', 'leads', 'level', 'level_others', 'more', 'more_short', 'no_points', 'took_lead', 'took_lead_you', 'you'];

    foreach (['az', 'en'] as $locale) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        $rivalry = array_filter($translations, static fn (string $key): bool => str_starts_with($key, 'rooms.rivalry.'), ARRAY_FILTER_USE_KEY);

        expect(array_keys($rivalry))->toEqualCanonicalizing(array_map(static fn (string $key): string => "rooms.rivalry.{$key}", $keys), $locale)
            ->and(array_filter(array_map(promisesInRoomText(...), $rivalry)))->toBe([], $locale);
    }
});
