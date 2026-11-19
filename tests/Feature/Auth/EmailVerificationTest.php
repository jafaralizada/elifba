<?php

namespace Tests\Feature\Auth;

use App\Domain\Rooms\Support\RoomJoinLink;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\RoomInvitation;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\Support\Rooms;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    public function test_email_verification_screen_can_be_rendered()
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        $response->assertOk();
    }

    public function test_unverified_users_are_redirected_to_the_email_verification_prompt(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('appearance.edit'));

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_email_can_be_verified()
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('start', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash()
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')],
        );

        $this->actingAs($user)->get($verificationUrl);

        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_is_not_verified_with_invalid_user_id(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => 123, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($verificationUrl);

        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verified_user_is_redirected_to_the_classes_from_verification_prompt(): void
    {
        $user = User::factory()->create();

        Event::fake();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        Event::assertNotDispatched(Verified::class);
        $response->assertRedirect(route('start', absolute: false));
    }

    public function test_already_verified_user_visiting_verification_link_is_redirected_without_firing_event_again(): void
    {
        $user = User::factory()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($verificationUrl)
            ->assertRedirect(route('start', absolute: false).'?verified=1');

        Event::assertNotDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * A game's link opened by hand waits behind the confirmation (docs/architecture.md
     * §12 "The invitation link (I2)", ADR 033 decision 14): the notice says so.
     */
    public function test_says_a_game_waits_while_an_invitation_is_under_way(): void
    {
        $user = User::factory()->player()->unverified()->create();
        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/verify-email')->where('game', false));

        Rooms::on();
        $room = Rooms::open();
        $link = route('rooms.join', ['code' => (string) $room->code, 't' => RoomJoinLink::tag($room)]);

        $this->get($link, ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])
            ->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('game', true));

        // Its minutes passed: nothing waits any more.
        $this->travel((int) config('elifba.rooms.invitation_minutes') + 1)->minutes();
        $this->get(route('verification.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('game', false));
    }

    /**
     * The notice looks again when it is shown again (ADR 033 decision 14):
     * an address confirmed in another browser goes on by itself, into the game.
     */
    public function test_sends_a_confirmed_account_on_when_the_notice_looks_again(): void
    {
        Rooms::on();
        $room = Rooms::open();
        $link = route('rooms.join', ['code' => (string) $room->code, 't' => RoomJoinLink::tag($room)]);
        $user = User::factory()->player()->unverified()->create();

        $this->actingAs($user)
            ->get($link, ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])
            ->assertRedirect(route('verification.notice'));
        $this->assertNotNull(session(RoomInvitation::KEY));

        // Confirmed elsewhere — a mail app's own browser — and this tab shown again: its reload.
        $user->markEmailAsVerified();
        $version = (string) app(HandleInertiaRequests::class)->version(request());
        $this->get(route('verification.notice'), ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => $version])
            ->assertRedirect($link);
        $this->get($link)->assertRedirect(route('rooms.show', $room));

        $this->assertTrue($room->rowOf($user)?->isPresent() ?? false);
        $this->assertNull(session(RoomInvitation::KEY));
    }
}
