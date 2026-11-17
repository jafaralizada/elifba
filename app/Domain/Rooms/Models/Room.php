<?php

namespace App\Domain\Rooms\Models;

use App\Domain\Rooms\Enums\RoomStatus;
use App\Domain\Rooms\Policies\RoomPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * A room an account opened (docs/architecture.md §1 `rooms`, §12 "Rooms and
 * joining", ADR 020): the host, a code while it is open, a lock, the
 * revision its signal carries and when it was last active. A room has no
 * name, title or picture; nothing a person typed is stored for it.
 *
 * `{room}` binds only a room the current account hosts or has a row in
 * that the host did not remove (`resolveRouteBinding`), so any other id is
 * a 404 before controller code; `RoomPolicy` is the second layer. A room is
 * open until `closed_at` is set, and an idle one already counts as closed
 * (`isOpen()`) before `CloseIdleRooms` writes it.
 *
 * @property string $id
 * @property int $user_id the host
 * @property string|null $code six digits from `RoomCode` while open; null once closed
 * @property CarbonImmutable|null $locked_at set: no new player comes in
 * @property int $revision raised by every change the room sees; `room.changed` carries it
 * @property CarbonImmutable $last_activity_at a join, a game started, an answer
 * @property CarbonImmutable|null $closed_at final
 * @property CarbonImmutable $created_at what the retention period counts from
 * @property CarbonImmutable $updated_at
 * @property-read User $host
 * @property-read Collection<int, RoomPlayer> $players
 * @property-read Collection<int, RoomGame> $games by position
 */
#[UseFactory(RoomFactory::class)]
#[UsePolicy(RoomPolicy::class)]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 0,
    ];

    /**
     * The moment before which a room's last activity makes it idle.
     */
    public static function idleSince(): CarbonImmutable
    {
        return CarbonImmutable::now()->subHours(Config::integer('elifba.rooms.idle_hours'));
    }

    /**
     * Open: not closed, and active within `elifba.rooms.idle_hours`.
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->last_activity_at->gt(self::idleSince());
    }

    public function status(): RoomStatus
    {
        return $this->isOpen() ? RoomStatus::Open : RoomStatus::Closed;
    }

    public function isHostedBy(User $account): bool
    {
        return $this->user_id === $account->id;
    }

    /**
     * The account's row in this room, present or past, unless the host
     * removed it; null for an account that has none (or took its name out).
     */
    public function rowOf(User $account): ?RoomPlayer
    {
        return $this->players()->where('user_id', $account->id)->whereNull('removed_at')->first();
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $account = Auth::user();

        if (! $account instanceof User) {
            return null;
        }

        return static::query()
            ->reachableBy($account)
            ->where($this->qualifyColumn($field ?? $this->getRouteKeyName()), $value)
            ->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<RoomPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(RoomPlayer::class);
    }

    /**
     * The games played in the room, one at a time, in order (B3).
     *
     * @return HasMany<RoomGame, $this>
     */
    public function games(): HasMany
    {
        return $this->hasMany(RoomGame::class)->orderBy('position');
    }

    /**
     * `{member}` is scoped under `{room}` through `players()`.
     *
     * @param  string  $childType
     */
    protected function childRouteBindingRelationshipName($childType): string
    {
        return $childType === 'member' ? 'players' : parent::childRouteBindingRelationshipName($childType);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'revision' => 'integer',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Rooms that are open: not closed and not idle.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('closed_at'))
            ->where($query->qualifyColumn('last_activity_at'), '>', self::idleSince());
    }

    /**
     * Rooms the account hosts or has a row in that the host did not remove.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function reachableBy(Builder $query, User $account): void
    {
        $query->where(static fn (Builder $query) => $query
            ->where($query->qualifyColumn('user_id'), $account->id)
            ->orWhereHas('players', static fn (Builder $players) => $players
                ->where('user_id', $account->id)
                ->whereNull('removed_at')));
    }
}
