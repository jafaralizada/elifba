<?php

namespace App\Enums;

use App\Support\RoomsArea;

/**
 * The roles an account may hold (docs/architecture.md §1 "Accounts and
 * roles", ADR 010): the one list of their names. Code passes the enum,
 * never the string. A role opens an area; ownership still decides rows.
 * The player opens the adults' rooms (§12, ADR 019), an area that can be
 * switched off: `available()` is what an instance offers and lists.
 */
enum Role: string
{
    case Teacher = 'teacher';
    case Parent = 'parent';
    case Player = 'player';

    /**
     * The `users` column that keeps when the account accepted this role's
     * sentence (§7 "Agreements", ADR 013).
     */
    public function agreementColumn(): string
    {
        return match ($this) {
            self::Teacher => 'teacher_agreed_at',
            self::Parent => 'parent_agreed_at',
            self::Player => 'player_agreed_at',
        };
    }

    /** The name of the route the area opens on (`/start` lands there). */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Teacher => 'classes.index',
            self::Parent => 'children.index',
            self::Player => 'rooms.index',
        };
    }

    /**
     * The roles this instance offers, grants and lists, in the enum's order:
     * every role but the player while the rooms area is switched off (§12
     * "The area and the switch", ADR 019 decision 4). An account keeps a
     * role it holds; it only goes unseen until the area returns.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        // The player is the last case, so what is left stays a list.
        return array_filter(
            self::cases(),
            static fn (self $role): bool => $role !== self::Player || RoomsArea::enabled(),
        );
    }

    /**
     * The package's `role` middleware for an area: `role:teacher`, or
     * `role:teacher|parent` for the shared one.
     */
    public static function middleware(self ...$roles): string
    {
        return 'role:'.implode('|', array_map(static fn (self $role): string => $role->value, $roles));
    }
}
