<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Fortify\CreateInvitedPlayer;
use App\Http\Controllers\Controller;
use App\Support\GoogleSignIn;
use App\Support\RoomInvitation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

/**
 * The invitation's door, `/oyun/qeydiyyat` (docs/architecture.md §12 "The
 * invitation link (I2)", ADR 033 decisions 10–13): where `auth` sends a
 * visitor who opened a room's link by hand. Google first, then a short form
 * that makes an account with the player role in the same step
 * (`CreateInvitedPlayer`); then the confirmation mail, the login with the
 * page's «Məni xatırla» box (ADR 017) and the link, kept as the intended
 * address. It reads no room: the page is one and the same for a real code
 * and six made-up digits. A guest route (`rooms.enabled`, `guest`), the
 * only room route outside the player group.
 */
final class RoomInvitedController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (RoomInvitation::pending($request) === null) {
            return to_route('login');
        }

        return Inertia::render('rooms/invited', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'googleSignIn' => GoogleSignIn::enabled(),
        ]);
    }

    public function store(Request $request, CreateInvitedPlayer $create): RedirectResponse
    {
        $input = $request->only(['name', 'email', 'password', 'terms', 'agreement']);

        // As registration takes the address (`fortify.lowercase_usernames`).
        if (Config::boolean('fortify.lowercase_usernames') && is_string($input[Fortify::username()] ?? null)) {
            $input[Fortify::username()] = Str::lower($input[Fortify::username()]);
        }

        $user = $create->create($input);

        event(new Registered($user));

        Auth::guard(Config::string('fortify.guard'))->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(GoogleSignIn::home());
    }
}
