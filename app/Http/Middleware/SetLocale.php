<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Translations;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the interface language: the teacher's saved choice, then the choice
 * made in this session (the switcher guests see), then APP_LOCALE (az).
 * Accept-Language is ignored on purpose: Elifba is Azerbaijani first.
 */
final class SetLocale
{
    public const SESSION_KEY = 'locale';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::of($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        return $next($request);
    }

    /**
     * The request's interface language. Public for what answers before this
     * middleware runs — a limit (`App\Support\CodeEntrance`) — and needs
     * the same language for its sentence.
     */
    public static function of(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User && Translations::supports($user->locale)) {
            return (string) $user->locale;
        }

        $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return Translations::supports($chosen) ? (string) $chosen : Translations::default();
    }
}
