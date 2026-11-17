<?php

namespace Tests\Feature\Settings;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rooms;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_deleting_the_account_removes_its_roles()
    {
        $user = User::factory()->teacherAndParent()->create();
        $other = User::factory()->create();

        expect(DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(2);

        $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ])
            ->assertRedirect(route('home'));

        $this->assertNull($user->fresh());
        expect(DB::table('model_has_roles')->where('model_id', $user->id)->exists())->toBeFalse()
            ->and($other->fresh()?->roleList())->toBe([Role::Teacher])
            ->and(DB::table('roles')->count())->toBe(count(Role::cases()));
    }

    /**
     * Docs/architecture.md §12 "Scoring and what is kept", ADR 022 decision
     * 7: the rooms it hosted go by cascade; its rows in others' rooms stay,
     * without an account.
     */
    public function test_deleting_the_account_removes_its_rooms()
    {
        Rooms::on();
        $user = User::factory()->teacherAndPlayer()->create();
        $other = User::factory()->player()->create();
        $hosted = Rooms::open($user);
        $guest = Rooms::join($hosted, $other);
        $closed = Rooms::open($other);
        $row = Rooms::join($closed, $user);
        $this->actingAs($other)->post(route('rooms.close', $closed));

        $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ])
            ->assertRedirect(route('home'));

        $this->assertNull($user->fresh());
        expect(Room::query()->whereKey($hosted->id)->exists())->toBeFalse()
            ->and(RoomPlayer::query()->whereKey($guest->id)->exists())->toBeFalse()
            ->and(Room::query()->whereKey($closed->id)->exists())->toBeTrue()
            ->and($row->refresh()->user_id)->toBeNull()
            ->and(DB::table('room_players')->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(DB::table('rooms')->where('user_id', $user->id)->exists())->toBeFalse();
    }

    public function test_an_account_without_a_role_can_still_delete_itself()
    {
        $user = User::factory()->withoutRole()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
