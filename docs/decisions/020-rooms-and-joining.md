# 020 — Rooms: every player is an account, and a room code admits

- **Status:** Proposed, 2026-11-17 (roadmap step B1; accepted when the owner approves the design in B1-O; built in B2). Decision 4 amended on 2026-11-18 at the owner's request: a code that opens no room for the account is refused with one sentence on the code field of `/otaq` instead of a 404 page — still one answer for an unknown code, a closed room and a removed account. Decision 6's `room-join` amended the same day: it counts the requests that try a code, the code field itself stays open, and over the limit a page gets a sentence on that field instead of a 429 page.
- **Sections:** architecture.md §1 "Tables", §12 "Rooms and joining"
- **Amends:** the rule "Codes come only from `PairingCode` and never authorize anything" ([002](002-live-play-client-events-board-writes.md) decision 4) now names its scope: pairing codes. A room code is a second kind of code with its own rule.
- **Why it is hard to reverse:** who is in a room is stored as account ids for the retention period, results hang on those rows, and players learn one way in (a code and their own login). Letting people in without an account later means a second credential, a second kind of player row and a second privacy promise.

## Context

A room is where a race happens: someone opens it, the others come in, everyone plays on their own phone ([021](021-room-race-protocol.md)). Until now the only people Elifba knew were account holders, and the only credential without an account was a guest board's bearer, which reaches one game and belongs to a screen, not a person. A code (`PairingCode`) has only ever said _which board_; authority always came from the login.

The owner was asked (2026-11-17) whether a joiner needs an account. The advisor recommended a code and a name without an account, the name deleted with the room. **The owner chose an account for every player**, and results kept with names ([022](022-room-scoring-and-history.md)): a name in a room is then a person who registered, confirmed an address and accepted a sentence, and nobody can sit in a room under someone else's name.

## Decision

1. **Two tables, no free text.**
    - `rooms` (`Rooms\Models\Room`, route parameter `{room}`): `id` (ULID), `user_id` (the host, cascade), `code` (char(6), null, unique), `locked_at`, `revision` (int), `last_activity_at`, `closed_at`, timestamps.
    - `room_players` (`Rooms\Models\RoomPlayer`, route parameter `{member}` — `{player}` is the board's voice route): `id` (ULID, the only id of a player that leaves the server), `room_id` (cascade), `user_id` (the account, **null on delete**), `joined_at`, `left_at`, `removed_at`, timestamps; unique `(room_id, user_id)`.
    - A room has no name, title, description or picture, and a player row has no name: nothing a person types is stored for a room.
2. **A player's name is the account's name** (`users.name`), read when a page or the state is built, never copied into a room table. A row whose `user_id` is null (the account was deleted, or the player took their name out of the room, [022](022-room-scoring-and-history.md)) has no name and is shown as «Oyunçu».
3. **The room code** comes only from `Rooms\Support\RoomCode`: 6 digits, unique among open rooms (the column's unique index; a retry on a collision), cleared when the room closes. It is a code space of its own with an entrance of its own — `/otaq` and `/otaq/{code}`, the QR is `{APP_URL}/otaq/{code}` — so a room code typed at `/pult` and a pairing code typed at `/otaq` are both unknown. `PairingCode` does not change and still authorizes nothing.
4. **What a room code grants.** It admits **a signed-in account with a confirmed address that holds the player role** into **that one room**, as a player, while the room is open, not locked and not full, and unless the host removed that account from it. Without such an account it grants nothing; it never reaches another room, a class, a child or a game. `GET /otaq/{code}` shows the join page (the code, the account's own name as the room will see it, «Qoşul») or redirects a member to the room; `POST /otaq/{code}` → `JoinRoom`. An unknown code, a closed room and a removed account get the same refusal — the sentence `rooms.errors.unknown` («Bu kodla açıq otaq tapılmadı. …») on the code field, never a 404 page: where the code was typed (`GET /otaq?code=`), and at `/otaq` for a link or a QR that opens nothing (`GET` and `POST /otaq/{code}`); a JSON client gets the same 422 for all three. A locked or a full room says so on the join page and shows nothing else of the room. Six characters that are not digits are no code at all: `/otaq/{code}` does not match them (404).
5. **The credential is the account's web session.** No new guard, no bearer, nothing in `localStorage`. What it reaches in a room:

    | Who                                                                 | Reaches                                                                                                                                                                             |
    | ------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
    | A **present player** (`left_at` and `removed_at` null) of the room  | the room page, the room's state, their own answers ([021](021-room-race-protocol.md)), the room channel while the room is open, leaving                                             |
    | The **host**                                                        | the same, playing or not, and: starting and ending a game, locking, removing a player, closing, the screen page; deleting the room once it is closed                                |
    | A **past player** (left, or the room is closed)                     | the room page with its results, and taking their own name out of a closed room; a player who left an open room may join again with the code and keeps the same row and their points |
    | A **removed player**, any other account, a visitor, a guest's board | nothing: 404                                                                                                                                                                        |

    `{room}` (`Room::resolveRouteBinding`) finds only a room the current account hosts or has a row in that is not removed; `{member}` is scoped under `{room}`; `RoomPolicy` (`view`, `play`, `host`) is the second layer and never asks for a role.

6. **Limits.** At most `elifba.rooms.max_players` (20) present players in a room; one open room per host at a time (`OpenRoom` checks under a lock on the host's `users` row, the lock `AddCustomWord` takes); a room with no activity for `elifba.rooms.idle_hours` (3) is closed. Limiters: `rooms-open` (6 a minute per account), `room-join` (10 a minute per account, shared by every request that tries a code — `GET /otaq?code=`, `GET` and `POST /otaq/{code}`; `/otaq` without a code is not counted, so the code field always opens, and a page over the limit is sent to it with `rooms.errors.too_many` on `code`, a JSON client gets the 429), `room-state` (120 a minute per account) and `room-answers` (30 a minute per account).
7. **Lifetime.** A room is `open` until `closed_at` is set — by the host (`CloseRoom`), by `CloseIdleRooms` (`rooms:close-idle`, hourly) or with the host's account — and a room past its idle time counts as closed before the scheduler reaches it (`Room::isOpen()`). Closing clears the code, ends a game that is running and is final: a closed room is its results ([022](022-room-scoring-and-history.md)).
8. **The host keeps the room.** «Kilidlə» (`LockRoom`) stops new players and unlocks again; «Çıxar» (`RemoveRoomPlayer`) sets `removed_at`: that account can neither see the room nor come back, and its answers leave the ranking. The lobby lists every player, so a stranger who guessed a code is seen and removed. Every one of these actions raises `rooms.revision` and signals the room ([021](021-room-race-protocol.md)).
9. **Actions** (only they write): `OpenRoom` (`plays`, default true: the host is the room's first player), `JoinRoom`, `LeaveRoom`, `LockRoom`, `RemoveRoomPlayer`, `CloseRoom`, `CloseIdleRooms`, `DeleteRoom`, `ForgetRoomPlayer`, `PruneOldRooms`.

## Consequences

- A first-time player needs a few minutes: register, confirm the address (or sign in with Google), take the role, then the code. The intended address is kept all the way ([019](019-rooms-area-and-player-role.md) decision 6).
- A code in the wrong hands still needs an account that can be removed and is rate-limited: with ten open rooms, one account guessing at the limit meets a live code about once a week, and lands in a lobby that shows it.
- `BearerReachTest` needs no new credential: a guest board's bearer gets from a room route what a visitor gets.
- A room is found only by its code or by being in it: there is no list of rooms, no search, no invitation sent by Elifba.

## Alternatives rejected

- **A code and a typed name, no account** (the advisor's recommendation): the fastest way in, and no address stored for a player; but a name anyone can type is a name anyone can take, typed names need moderation that "no third-party request" forbids to outsource, and the owner wanted results kept with names. Rejected by the owner.
- **Both ways in:** two credentials, two kinds of player row and two privacy promises for one lobby.
- **A bearer per player, like a guest board's:** only needed without accounts.
- **A player name per room, or on the account:** a second name to store, validate and moderate; `users.name` is already what the account calls itself.
- **Reusing `PairingCode` and `/pult`:** `Play` would have to know rooms to keep one code space, and a teacher's remote entrance would start to admit players.
- **A longer or lettered code:** harder to say aloud and type on a phone; the account, the limiter, the lock and the lobby do the guarding.
- **The host approves every joiner:** one more tap per player for a danger the lobby and «Çıxar» already answer.
- **A presence channel for "who is here":** it puts member data on the socket and cannot say more than the rows do.

## What would make us revisit

Hosts asking to let guests in without an account (a party where nobody will register), rooms larger than a table of friends (a hall, a broadcast), or abuse that the lobby and «Çıxar» do not stop.
