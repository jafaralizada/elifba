<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

/**
 * A room's link opened by hand (docs/architecture.md §12 "The invitation
 * link (I2)", ADR 033 decisions 6 and 8): whether the browser says that a
 * person opened `/oyun/{code}` themselves — the only open that joins at
 * once — and the session's `rooms.invitation`, which carries that open to
 * the end of a login, a registration or an e-mail confirmation. It holds a
 * code and a tag, nothing about a person, and reads no room.
 */
final class RoomInvitation
{
    public const string KEY = 'rooms.invitation';

    /**
     * Whether a person opened this request by hand: exactly `GET`, nothing
     * speculative (a prefetch, a prerender, a preload), not Inertia or a
     * JSON client, a top-level document navigation, and begun by the
     * browser itself (`Sec-Fetch-Site: none`: another app, the camera, the
     * address bar) or by a person's tap (`Sec-Fetch-User: ?1`). A browser
     * that sends no fetch metadata is not by hand. Only headers a page
     * cannot set are read.
     */
    public static function openedByHand(Request $request): bool
    {
        if ($request->getMethod() !== 'GET' || self::speculative($request)) {
            return false;
        }

        if ($request->headers->has('X-Inertia') || $request->expectsJson()) {
            return false;
        }

        if ($request->headers->get('Sec-Fetch-Mode') !== 'navigate' || $request->headers->get('Sec-Fetch-Dest') !== 'document') {
            return false;
        }

        return $request->headers->get('Sec-Fetch-Site') === 'none' || $request->headers->get('Sec-Fetch-User') === '?1';
    }

    /**
     * Keeps the open of `$code` (with its link's `$tag`) in the session for
     * `elifba.rooms.invitation_minutes`; a newer open replaces it.
     */
    public static function remember(Request $request, string $code, ?string $tag): void
    {
        $request->session()->put(self::KEY, [
            'code' => $code,
            'tag' => $tag,
            'until' => Date::now()->addMinutes(Config::integer('elifba.rooms.invitation_minutes'))->unix(),
        ]);
    }

    /**
     * The invitation under way, `{code, tag}`; null when there is none, the
     * switch is off or its minutes have passed (then it is forgotten).
     *
     * @return array{code: string, tag: ?string}|null
     */
    public static function pending(Request $request): ?array
    {
        if (! RoomsArea::enabled() || ! $request->hasSession()) {
            return null;
        }

        $invitation = $request->session()->get(self::KEY);

        if (! is_array($invitation)) {
            return null;
        }

        $code = $invitation['code'] ?? null;
        $tag = $invitation['tag'] ?? null;
        $until = $invitation['until'] ?? null;

        if (! is_string($code) || ($tag !== null && ! is_string($tag)) || ! is_int($until) || $until < Date::now()->unix()) {
            $request->session()->forget(self::KEY);

            return null;
        }

        return ['code' => $code, 'tag' => $tag];
    }

    /**
     * Whether the invitation under way names `$code` — and `$tag`, when the
     * request carries one — taking it out of the session if so: it is used
     * once. A speculative, `HEAD` or JSON request never takes it.
     */
    public static function pull(Request $request, string $code, ?string $tag): bool
    {
        if ($request->getMethod() !== 'GET' || self::speculative($request) || $request->expectsJson()) {
            return false;
        }

        $invitation = self::pending($request);

        if ($invitation === null || $invitation['code'] !== $code || ($tag !== null && $invitation['tag'] !== $tag)) {
            return false;
        }

        $request->session()->forget(self::KEY);

        return true;
    }

    /**
     * A link's tag as the request carries it (`?t=`): null without one, and
     * an empty string — a tag no room has — for a value that is not text.
     */
    public static function tagOf(Request $request): ?string
    {
        $tag = $request->query('t');

        return match (true) {
            $tag === null => null,
            is_string($tag) => $tag,
            default => '',
        };
    }

    /** Whether `$tag` has the shape of a link's tag: six lowercase hex characters. */
    public static function wellFormed(string $tag): bool
    {
        return preg_match('/^[0-9a-f]{6}$/', $tag) === 1;
    }

    /**
     * A prefetch or prerender (Chrome's `Sec-Purpose`, the older `Purpose`
     * that Inertia's own prefetch sends too), Safari's preload (`X-Purpose`)
     * or Firefox's prefetch (`X-Moz`), whatever the header's value.
     */
    private static function speculative(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }
}
