<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * The privacy notice (docs/architecture.md §7): public, az and en, with the
 * retention period and the operator from `.env`; the section «Otaqlar» only
 * while the rooms switch is on (§12 "Adults' privacy", ADR 024).
 */

it('is public and shows the retention period and the operator', function (): void {
    config([
        'elifba.retention_days' => 400,
        'elifba.operator.name' => ' Məktəb №5 ',
        'elifba.operator.email' => 'elifba@example.az',
    ]);

    $this->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('retentionDays', 400)
            ->where('operator', ['name' => 'Məktəb №5', 'email' => 'elifba@example.az']));
});

it('sends no operator when none is configured', function (?string $name, ?string $email): void {
    config(['elifba.operator.name' => $name, 'elifba.operator.email' => $email]);

    $this->get(route('privacy'))
        ->assertInertia(fn (Assert $page) => $page->where('operator', ['name' => null, 'email' => null]));
})->with([
    'unset' => [null, null],
    'blank' => ['', '  '],
]);

it('is in Azerbaijani by default and in English when chosen', function (?string $locale, string $title, string $parents): void {
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', $locale ?? 'az')
            ->where('translations', fn ($translations): bool => $translations['privacy.title'] === $title
                && str_contains($translations['privacy.parents.note'], $parents)
                && str_contains($translations['privacy.how_long.body'], ':days')));
})->with([
    'az' => [null, 'Məxfilik qeydi', 'Hörmətli valideynlər'],
    'en' => ['en', 'Privacy note', 'Dear parents'],
]);

it('follows a teacher\'s own language', function (): void {
    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('privacy'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('has the section for parents in both languages', function (?string $locale, string $title, string $stored, string $classNote, string $intro): void {
    config(['elifba.children.max' => 10, 'elifba.retention_days' => 400]);
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);
    $parts = ['title', 'stored', 'why', 'who', 'not_shared', 'delete', 'how_long'];

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('childrenMax', 10)
            ->where('retentionDays', 400)
            ->where('translations', function ($translations) use ($locale, $parts, $title, $stored, $classNote, $intro): bool {
                foreach ($parts as $part) {
                    if (trim((string) ($translations["privacy.home.{$part}"] ?? '')) === '') {
                        return false;
                    }
                }

                return $translations['privacy.home.title'] === $title
                    && str_contains($translations['privacy.home.stored'], $stored)
                    && str_contains($translations['privacy.home.stored'], ':max')
                    && str_contains($translations['privacy.home.how_long'], ':days')
                    // The note a teacher copies stays, under a title that says it is for a class.
                    && $translations['privacy.parents.title'] === $classNote
                    // The introduction names both uses; the Google paragraph speaks of the account.
                    && str_contains($translations['privacy.intro'], $intro)
                    && ! str_contains($translations['privacy.google.body'], $locale === 'en' ? 'teacher' : 'müəllim');
            }));
})->with([
    'az' => [null, 'Evdə: valideynlər və uşaqları', 'yalnız ad (soyadsız)', 'Sinif valideynləri üçün qeyd', 'valideynlərin'],
    'en' => ['en', 'At home: parents and their children', 'a first name only (no surname)', 'A note for a class\'s parents', 'parents'],
]);

it('says what the board says aloud, a name only from the built-in list and sent nowhere, in both languages', function (?string $locale, string $title, string $list, string $note): void {
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('translations', fn ($translations): bool => $translations['privacy.voice.title'] === $title
                && str_contains($translations['privacy.voice.body'], $list)
                // The note a teacher copies for a class's parents says it too.
                && str_contains($translations['privacy.parents.note'], $note)));
})->with([
    'az' => [null, 'Lövhənin səsi', 'hazır ad lüğətindədirsə', 'ad heç yerə göndərilmir'],
    'en' => ['en', "The board's voice", 'built-in name list', 'the name is sent nowhere'],
]);

it('has the section for rooms in both languages when the switch is on', function (?string $locale, string $title, string $intro, string $stored, string $who, string $age, string $contact, string $profile): void {
    Rooms::on();
    config(['elifba.retention_days' => 400, 'elifba.rooms.idle_hours' => 3]);
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    // The parts the page draws, in its order (ADR 024 decision 10).
    preg_match('/const ROOMS = \[(.*?)\];/s', (string) file_get_contents(resource_path('js/pages/privacy.tsx')), $list);
    preg_match_all("/'([a-z_]+)'/", $list[1] ?? '', $parts);

    expect($parts[1])->toBe(['what', 'camera', 'stored', 'not_stored', 'why', 'who', 'contact', 'how_long', 'delete', 'where']);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('rooms', ['idleHours' => 3])
            ->where('retentionDays', 400)
            ->where('translations', function ($translations) use ($parts, $title, $intro, $stored, $who, $age, $contact, $profile): bool {
                foreach (['intro', 'title', ...$parts[1]] as $part) {
                    if (trim((string) ($translations["privacy.rooms.{$part}"] ?? '')) === '') {
                        return false;
                    }
                }

                return $translations['privacy.rooms.title'] === $title
                    // The introduction names the third use.
                    && str_contains($translations['privacy.rooms.intro'], $intro)
                    // What is stored for an account that plays and for a room.
                    && str_contains($translations['privacy.rooms.stored'], $stored)
                    && str_contains($translations['privacy.rooms.stored'], $translations['roles.player.title'])
                    // Who sees a name, and for how long.
                    && str_contains($translations['privacy.rooms.who'], $who)
                    && str_contains($translations['privacy.rooms.how_long'], ':hours')
                    && str_contains($translations['privacy.rooms.how_long'], ':days')
                    // No age, and no way to write to another player.
                    && str_contains($translations['privacy.rooms.contact'], $age)
                    && str_contains($translations['privacy.rooms.contact'], $contact)
                    // The four ways out, by the names the room pages give them (ADR 022 decision 7).
                    && str_contains($translations['privacy.rooms.delete'], $translations['rooms.show.forget'])
                    && str_contains($translations['privacy.rooms.delete'], $translations['rooms.show.delete'])
                    && str_contains($translations['privacy.rooms.delete'], $profile)
                    && str_contains($translations['privacy.rooms.delete'], ':days');
            }));

    // The operator closes the section, as it closes the notice.
    expect((string) file_get_contents(resource_path('js/pages/privacy.tsx')))
        ->toMatch('/data-test="rooms".*?<Operator \{\.\.\.operator\} \/>.*?<\/section>/s');
})->with([
    'az' => [null, 'Böyüklərin oyunları', '«Böyüklərin oyunları» bölməsindədir', 'razılığının verildiyi vaxt', 'otaqların aparıcıları və oyunçuları görür', 'yaşını soruşmur', 'bir-birinə yazması', 'Ayarlar → Profil'],
    'en' => ['en', 'Games for adults', 'the section “Games for adults” below', 'when its agreement was given', 'seen only by the hosts and the players', "asks no one's age", 'write to each other', 'Settings → Profile'],
]);

it('says what a taunt is, what it costs, who sees it and how it is hidden', function (?string $locale, array $contact, string $stored): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', function ($translations) use ($contact, $stored): bool {
                foreach ($contact as $words) {
                    if (! str_contains($translations['privacy.rooms.contact'], $words)) {
                        return false;
                    }
                }

                // Kept: who threw what at whom and when; the target forgotten after a day, the cost kept.
                return str_contains($translations['privacy.rooms.stored'], $stored)
                    && substr_count($translations['privacy.rooms.stored'], "\n") === 3
                    // The player role's sentence does not change for it (ADR 029 decision 30).
                    && ! str_contains(mb_strtolower($translations['roles.agreement.player']), 'atmaca')
                    && ! str_contains(mb_strtolower($translations['roles.agreement.player']), 'taunt');
            }));
})->with([
    'az' => [null, ['mesaj, çat və ya dəvət göndərilmir', 'yalnız hazır siyahıdan atmaca', 'hər atmaca atana 1 xal başa gəlir', '4 saniyə yalnız hədəf olan oyunçu və aparıcının böyük ekranı görür', 'öz cihazında gizlədə', 'otaqda bağlaya bilər'], 'kimə atıldığı bir gündən sonra silinir'],
    'en' => ['en', ['no message, chat or invitation is sent', 'only a taunt from a ready-made list', 'each costs the thrower 1 point', "only the player it is aimed at and the host's big screen see it, for 4 seconds", 'hide taunts on their own device', 'switch them off for the room'], 'whom it was aimed at is erased after a day'],
]);

it('says what the camera does with the picture', function (?string $locale, string $optional, string $asks, string $nowhere): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', fn ($translations): bool => str_contains($translations['privacy.rooms.camera'], $optional)
                && str_contains($translations['privacy.rooms.camera'], $asks)
                && str_contains($translations['privacy.rooms.camera'], $nowhere)
                // The scanner says the same where the camera opens.
                && str_contains($translations['rooms.scan.privacy'], $locale === null ? 'saxlanılmır' : 'not kept')));
})->with([
    'az' => [null, 'istəyə bağlıdır', 'brauzer əvvəlcə icazə soruşur', 'heç yerə göndərilmir və saxlanılmır'],
    'en' => ['en', 'is optional', 'the browser asks first', 'it is sent nowhere and is not kept'],
]);

it('says nothing of rooms when it is off', function (): void {
    // `Tests\TestCase` leaves the switch off, as an instance has it.
    foreach ([null, User::factory()->teacherAndPlayer()->create()] as $account) {
        $request = $account === null ? $this : $this->actingAs($account);

        $request->get(route('privacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('privacy')
                ->missing('rooms')
                // The sections for teachers and parents and the class note stay.
                ->has('retentionDays')
                ->has('childrenMax')
                ->has('operator'));
    }

    // Every part of the section and the introduction's sentence are drawn
    // only under `rooms`, which the page gets only while the switch is on.
    $page = (string) file_get_contents(resource_path('js/pages/privacy.tsx'));
    preg_match_all('/privacy\.rooms\./', $page, $uses);

    expect($uses[0])->toHaveCount(3)
        ->and($page)->toContain("{rooms && ` \${t('privacy.rooms.intro')}`}")
        ->toMatch('/\{rooms && \(\s*<section[^>]*data-test="rooms"/s');
});
