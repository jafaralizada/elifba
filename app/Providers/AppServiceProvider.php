<?php

namespace App\Providers;

use App\Domain\Play\Events\GameSessionTransitioned;
use App\Domain\Play\Listeners\BroadcastSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\GuestBoardGuard;
use App\Models\User;
use App\Support\CodeEntrance;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRoles();
        $this->configurePlay();
        $this->configureRooms();

        // `composer dev`: no queue worker, because QUEUE_CONNECTION is `sync`
        // everywhere (docs/architecture.md §10). Reverb registers its own
        // `reverb:start` entry.
        DevCommands::except('queue');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Account roles (docs/architecture.md §1 "Accounts and roles", ADR 010):
     * `model_has_roles.model_type` holds the alias `user`, not a class name.
     */
    protected function configureRoles(): void
    {
        Relation::morphMap(['user' => User::class]);
    }

    /**
     * The guest board's guard, the pairing and round-count limits and the
     * `session.status` broadcast (docs/architecture.md §4).
     */
    protected function configurePlay(): void
    {
        Auth::viaRequest('board', new GuestBoardGuard);

        $who = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        // A request that tries a pairing code — `GET /pult?code=`, `GET` and
        // `POST /pult/{code}`: 10 a minute per teacher, so a code cannot be
        // guessed by trying. Over it the phone is sent to the code field
        // with a sentence, never an error page (§4 "Codes").
        RateLimiter::for('pult', static fn (Request $request): Limit => CodeEntrance::limit(
            $request,
            10,
            $who($request),
            'pult.errors.too_many',
            static fn (): string => route('pult', ['list' => 1]),
        ));

        // Opening a guest board (`POST /guest-boards`): 6 a minute per IP.
        RateLimiter::for('guest-boards', static fn (Request $request): Limit => Limit::perMinute(6)->by((string) $request->ip()));

        // A guest board's 2 s poll, per board, apart from the opening limit.
        RateLimiter::for('guest-board', static fn (Request $request): Limit => Limit::perMinute(120)->by($who($request)));

        // The round count under «Başla» builds every round again: 30 a minute per game.
        RateLimiter::for('board-rounds', static function (Request $request): Limit {
            $game = $request->route('game');

            return Limit::perMinute(30)->by('board-rounds:'.($game instanceof GameSession ? $game->id : (is_string($game) ? $game : '')));
        });

        Event::listen(GameSessionTransitioned::class, BroadcastSessionStatus::class);
    }

    /**
     * The rooms' limiters (docs/architecture.md §12 "Rooms and joining",
     * ADR 020 decision 6), each per account — a room route is never reached
     * without one.
     */
    protected function configureRooms(): void
    {
        $account = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        // Opening a room: 6 a minute.
        RateLimiter::for('rooms-open', static fn (Request $request): Limit => Limit::perMinute(6)->by($account($request)));

        // A request that tries a room code — `GET /otaq?code=` and both
        // `/otaq/{code}`: 10 a minute, so a code cannot be guessed by trying.
        // Over it the answer is a sentence on the code field: where the code
        // was typed, or at the entrance for a link or a QR.
        RateLimiter::for('room-join', static fn (Request $request): Limit => CodeEntrance::limit(
            $request,
            10,
            $account($request),
            'rooms.errors.too_many',
            static fn (Request $request): ?string => $request->route('code') === null ? null : route('rooms.entrance'),
        ));

        // A room page's state, asked at each moment of the timetable and every 3 s without a socket.
        RateLimiter::for('room-state', static fn (Request $request): Limit => Limit::perMinute(120)->by($account($request)));

        // A player's answers (B3).
        RateLimiter::for('room-answers', static fn (Request $request): Limit => Limit::perMinute(30)->by($account($request)));
    }
}
