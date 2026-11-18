<?php

use App\Domain\Content\Support\NameVoices;
use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\NameClips;
use Tests\Support\Rooms;

/*
 * The winner's name at a room's final (docs/architecture.md §12 "Room
 * games", ADR 030): the big screen gets a member's first-name clip only
 * from `GET /rooms/{room}/members/{member}/voice`, by ids — never a public
 * file, never a name or a hash in a URL — while it may open the screen:
 * the host of an open room, in the host's own session. A name off the list
 * or without a clip, a removed row and a row without an account are 404; a
 * player's phone and a closed room are refused; the clip is the name
 * list's, matched as `NameVoices` matches it. The clips here are fakes over
 * a fixture list (Tests\Support\NameClips); the rest is the real content.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->voices = NameClips::fake(['Nihat', 'Aysel', 'Lalə'], ['Zəhra']);
    $this->host = User::factory()->player()->create(['name' => 'Aysel Quliyeva']);
    $this->room = Rooms::open($this->host);
    $this->hostRow = $this->room->rowOf($this->host);
    $this->nihat = Rooms::join($this->room, User::factory()->player()->create(['name' => 'Nihat Əliyev']));
    $this->lale = Rooms::join($this->room, User::factory()->player()->create(['name' => 'Lale']));
    $this->zehra = Rooms::join($this->room, User::factory()->player()->create(['name' => 'Zəhra']));
    $this->kral = Rooms::join($this->room, User::factory()->player()->create(['name' => 'Kral_77']));
});

/**
 * The clip a first name is said with, as the screen should receive it.
 */
function memberClipOf(NameVoices $voices, string $name): string
{
    return (string) file_get_contents((string) $voices->file($name));
}

/**
 * @return TestResponse<Response>
 */
function memberVoice(Room $room, RoomPlayer $member): TestResponse
{
    return test()->get(route('rooms.members.voice', ['room' => $room->id, 'member' => $member->id]));
}

/**
 * Everything a request for a clip could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function memberVoiceSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return ['rooms' => $rows('rooms'), 'room_players' => $rows('room_players'), 'room_games' => $rows('room_games')];
}

it('gives the host\'s screen the clip of a member whose first name is listed', function (): void {
    $before = memberVoiceSnapshot();
    $this->actingAs($this->host);

    // The screen's own session reaches it: the host may open the screen.
    $this->get(route('rooms.screen', $this->room))->assertOk();

    $response = memberVoice($this->room, $this->nihat)
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Disposition')
        ->assertHeaderMissing('ETag')
        ->assertHeaderMissing('Last-Modified');

    // «Nihat Əliyev» is said by his first name, from the list's clip.
    expect($response->getContent())->toBe(memberClipOf($this->voices, 'Nihat'))
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public');

    // «Lale» is the list's «Lalə», as on the board; the host's own row too, when the host wins.
    expect(memberVoice($this->room, $this->lale)->assertOk()->getContent())->toBe(memberClipOf($this->voices, 'Lalə'))
        ->and(memberVoice($this->room, $this->hostRow)->assertOk()->getContent())->toBe(memberClipOf($this->voices, 'Aysel'))
        // Nothing is written: not even a read of the room.
        ->and(memberVoiceSnapshot())->toBe($before);
});

it('answers 404 for a name off the list, a listed name without a clip, a removed row and a row without an account', function (): void {
    $this->actingAs($this->host);

    memberVoice($this->room, $this->kral)->assertNotFound();
    memberVoice($this->room, $this->zehra)->assertNotFound();

    app(RemoveRoomPlayer::class)->handle($this->room, $this->lale);
    memberVoice($this->room, $this->lale)->assertNotFound();

    RoomPlayer::query()->whereKey($this->nihat->id)->update(['user_id' => null]);
    memberVoice($this->room, $this->nihat)->assertNotFound();
});

it('answers 404 to an account outside the room and for another room\'s member', function (): void {
    $outsider = User::factory()->player()->create(['name' => 'Nihat']);
    $other = Rooms::open($outsider);
    $otherMember = Rooms::join($other, User::factory()->player()->create(['name' => 'Aysel']));

    $this->actingAs($outsider);
    memberVoice($this->room, $this->nihat)->assertNotFound();

    $this->actingAs($this->host);
    memberVoice($this->room, $otherMember)->assertNotFound();
    memberVoice($other, $otherMember)->assertNotFound();
});

it('refuses a player\'s phone and a closed room: only the screen says a name', function (): void {
    $this->actingAs($this->nihat->user);
    memberVoice($this->room, $this->nihat)->assertForbidden();
    memberVoice($this->room, $this->hostRow)->assertForbidden();

    app(CloseRoom::class)->handle($this->room);

    $this->actingAs($this->host);
    memberVoice($this->room->refresh(), $this->nihat)->assertForbidden();
});

it('answers 404 with the switch off', function (): void {
    config(['elifba.rooms.enabled' => false]);
    $this->actingAs($this->host);

    memberVoice($this->room, $this->nihat)->assertNotFound();
});

it('takes ids only, names no file in a header and logs no name', function (): void {
    $this->actingAs($this->host);
    $hash = $this->voices->line('Nihat')->hash;

    // A name or a hash in place of the member is no route.
    $this->get('/rooms/'.$this->room->id.'/members/Nihat/voice')->assertNotFound();
    $this->get('/rooms/'.$this->room->id.'/members/'.$hash.'/voice')->assertNotFound();

    Log::spy();
    $url = route('rooms.members.voice', ['room' => $this->room->id, 'member' => $this->nihat->id]);
    $response = $this->get($url)->assertOk();

    expect(rawurldecode($url))->not->toContain('Nihat')->not->toContain($hash)->not->toContain(NameVoices::FOLDER);

    foreach ($response->headers->all() as $name => $values) {
        expect(implode(' ', $values))->not->toContain($hash)->not->toContain('Nihat')->not->toContain(NameVoices::FOLDER, $name);
    }

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});
