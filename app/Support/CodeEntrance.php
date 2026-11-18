<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * How an entrance with a code says no — `/pult` (docs/architecture.md §4
 * "Codes") and `/otaq` (§12 "Rooms and joining"): one sentence on the code
 * field, never an error page, the same sentence whatever the reason. The
 * two entrances share this way of answering and nothing else: each has its
 * own codes, routes, sentences and limit.
 */
final class CodeEntrance
{
    /**
     * The refusal of a code: the sentence `$key` on `code`. Without
     * `$entrance` it goes back to the field the code was typed in, as a
     * malformed code does; with it, to that page — a link or a QR has no
     * field of its own.
     */
    public static function refusal(string $key, ?string $entrance = null, ?string $locale = null): ValidationException
    {
        $refusal = ValidationException::withMessages(['code' => __($key, [], $locale)]);

        return $entrance === null ? $refusal : $refusal->redirectTo($entrance);
    }

    /**
     * An entrance's limit: `$perMinute` requests that try a code — one in
     * the path or in `?code=` — per `$by`. The page with the code field
     * tries none and is never counted, so it always opens and can say that
     * the limit is reached: a page over it is refused with the sentence
     * `$key` as a code is (`$entrance` as in `refusal()`), and a JSON
     * client gets the 429. The limit runs before `SetLocale`, so the
     * sentence takes the request's language from it.
     *
     * @param  Closure(Request): ?string  $entrance
     */
    public static function limit(Request $request, int $perMinute, string $by, string $key, Closure $entrance): Limit
    {
        if ($request->route('code') === null && ! $request->query->has('code')) {
            return Limit::none();
        }

        return Limit::perMinute($perMinute)->by($by)->response(
            static function (Request $request, array $headers) use ($key, $entrance): Response {
                $locale = SetLocale::of($request);

                if ($request->expectsJson()) {
                    return response()->json(['message' => __($key, [], $locale)], Response::HTTP_TOO_MANY_REQUESTS, $headers);
                }

                throw self::refusal($key, $entrance($request), $locale);
            },
        );
    }
}
