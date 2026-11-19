<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\CreateUserFromGoogle;
use App\Exceptions\GoogleSignInRefused;
use App\Http\Controllers\Controller;
use App\Support\GoogleSignIn;
use App\Support\RoomInvitation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The completion page of a teacher who signs up with Google (ADR 008): the
 * name and the privacy-note agreement registration asks for (§7), before an
 * account exists. While a room's invitation is under way (§12 "The
 * invitation link (I2)", ADR 033 decision 13) it also asks for the player's
 * sentence, and the account takes the player role with it.
 */
final class GoogleRegistrationController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $profile = GoogleSignIn::pending($request->session());

        if ($profile === null) {
            return to_route('login')->withErrors(['google' => __('auth.google.expired')]);
        }

        return Inertia::render('auth/google-register', [
            'email' => $profile->email,
            'name' => $profile->name,
            'player' => self::invited($request),
        ]);
    }

    public function store(Request $request, CreateUserFromGoogle $create): RedirectResponse
    {
        $profile = GoogleSignIn::pending($request->session());

        if ($profile === null) {
            return to_route('login')->withErrors(['google' => __('auth.google.expired')]);
        }

        try {
            $user = $create->create($profile, $request->only(['name', 'terms', 'agreement']), self::invited($request));
        } catch (GoogleSignInRefused $refused) {
            $request->session()->forget(GoogleSignIn::PENDING);

            return to_route('login')->withErrors(['google' => __($refused->reason)]);
        }

        // The «Məni xatırla» box of the page the teacher came from (ADR 017).
        $remember = GoogleSignIn::remembersPending($request->session());

        $request->session()->forget(GoogleSignIn::PENDING);

        event(new Registered($user));

        return GoogleSignIn::logIn($request, $user, $remember);
    }

    /** Whether a room's link opened by hand waits at the end of this sign-up (the switch on). */
    private static function invited(Request $request): bool
    {
        return RoomInvitation::pending($request) !== null;
    }

    /** The teacher changed their mind: Google's name and address leave the session. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(GoogleSignIn::PENDING);

        return to_route('login');
    }
}
