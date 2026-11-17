# 021 — The race: every player writes their own answer, the server judges and keeps the clock

- **Status:** Proposed, 2026-11-17 (roadmap step B1; accepted when the owner approves the design in B1-O; built in B2 and B3)
- **Sections:** architecture.md §1 "Tables", §12 "The race"
- **Amends:** the rule "The board is the only writer of results" ([002](002-live-play-client-events-board-writes.md) decision 2) now names its scope: class and home games. A room has no board and a rule of its own. Nothing of 002 changes for a class or a child.
- **Why it is hard to reverse:** the tables, the state a phone receives, the clock and the anti-cheating rule all follow from who writes and who holds the solution. Moving the judge to a device, or the clock to a client, means rebuilding both sides and re-proving fairness.

## Context

A class game has one screen, one teacher and one child at a time: the board judges a touch in the same frame and is the only writer; a round carries its solution to the board and the phone, because both belong to the teacher (ADR 002, ADR 003). A room is the opposite on every count. Up to twenty people answer the **same round at the same time**, each on their own phone, **against each other**. A big screen is optional. Elifba has no queue and no worker (`QUEUE_CONNECTION=sync`), Reverb takes client events only from a channel's members and relays them unjudged, and production is one server.

Three things must hold: a phone must not be able to read the answer before the round is over; time must mean the same for everyone; and a timed round must end although no process is waiting for it.

## Decision

1. **Each player's device writes that player's own answer, and nothing else.** `POST /rooms/{room}/answers` `{event_id, round_id, picked}` → `Rooms\Actions\RecordRoomAnswer`, the only writer of a room result. The player is the session's account, never a field of the body. One answer per player per round, final. Idempotent by `event_id` (unique) and by `(room_round_id, room_player_id)` (unique).
2. **The server is the only judge and the only clock.** `Rooms\Support\RoomJudge` and `RoomPointsRule` ([022](022-room-scoring-and-history.md)) run in PHP only; there is no TypeScript judge for rooms. Time is the server's receipt of the request, read from `Rooms\Support\RoomClock`; a device's clock is used only to draw the countdown.
3. **The solution never leaves the server before the round closes.** A round is stored as two snapshots: `room_rounds.payload` (what players see) and `room_rounds.solution` (hidden from every serialization). The receipt of an answer says `accepted`, `duplicate`, `answered` or `late` — never right or wrong, so a second account in the room cannot scout for the first. Solution, results and ranking are served only once the round is closed.
4. **Moments that scoring reads are Unix milliseconds in `bigint` columns** (`room_games.started_ms`, `ended_ms`; `room_rounds.closed_ms`; durations as `int` milliseconds), because engines differ in sub-second timestamps.
5. **The timetable is derived; only facts are written.** `Rooms\Support\RoomTimetable` is a pure function of the game's `started_ms`, each round's `study_ms` and `answer_ms`, the game's `reveal_ms`, and the facts `closed_ms` (a round every present player answered before the bell) and `ended_ms` (the host ended the game, or the room closed):

    ```
    opens(0)        = started_ms                       (StartRoomGame sets it countdown_ms ahead)
    answer_from(i)  = opens(i) + study_ms(i)
    bell(i)         = answer_from(i) + answer_ms(i)
    closes(i)       = closed_ms(i) ?? bell(i)
    opens(i + 1)    = closes(i) + reveal_ms
    over            = ended_ms ?? closes(last) + reveal_ms
    ```

    The current round and its phase are read from it at the moment of each request and never stored. **A time-driven change needs no trigger**: every device holds the same moments and asks for the state when one arrives; a request made after the bell is simply answered from the next phase. No job, no worker loop, no write in a `GET`, and a game nobody is watching still ends when its timetable says.

6. **What each phase serves** (`GET /rooms/{room}/state`, `no-store`):

    | Phase       | When                         | A device gets                                                                              |
    | ----------- | ---------------------------- | ------------------------------------------------------------------------------------------ |
    | `countdown` | before `opens(i)`            | the round's number and its moments; nothing of its content                                 |
    | `study`     | until `answer_from(i)`       | the material to remember (memory games only; other games have `study_ms` 0)                |
    | `answer`    | until `closes(i)`            | the question and the tiles; whether this player has answered; how many have                |
    | `reveal`    | until `opens(i + 1)`         | the solution, this player's result, every player's result of the round, the ranking so far |
    | `over`      | after the last round's close | the game's final ranking and the room's standing; the room is a lobby again                |

    In `answer` the study material is no longer served, so reloading the page does not show it again. Every state carries `server_ms`, from which the page estimates the server's clock.

7. **`RecordRoomAnswer`**, in one transaction with the room row and then the round row locked (always in that order):
    1. the `event_id` is stored → `duplicate`; this player already answered the round → `answered`;
    2. the round is not the game's current round in its `answer` phase by the server's clock (too early, after the bell or the early close, a foreign id, a game that is over, a closed room) → `late`, nothing written. There is no grace after the bell: the reveal is being served from that moment;
    3. `picked` is not made of the round's own item ids in the shape it asks for → 422, nothing written;
    4. otherwise the answer is judged, timed (`elapsed_ms` = receipt − `answer_from`), given its points and stored;
    5. if every present player has now answered, the round's `closed_ms` is set to this moment — the one transition a fact causes. `LeaveRoom` and `RemoveRoomPlayer` make the same check, so a round never waits for someone who is gone.
8. **One private channel per room, signals only.** `private-room.{roomId}`, authorized for the `web` guard alone: the switch is on, the account holds the player role, the room is open, and the account is its host or a present player (`Rooms\Support\RoomChannel`). The server broadcasts one event, `room.changed` `{v: 1, revision}` (`Rooms\Events\RoomChanged`, `ShouldBroadcastNow`, after commit), when a fact changes what the room sees: a join, a leave, a removal, a lock, a game started or ended by the host, an early close, the room closed. A device that sees a higher revision than its own fetches the state. **Content, names, answers and results never travel on the channel**, and **no client event means anything in a room**: the pages send none and listen for none.
9. **Without Reverb the race still runs.** A failed broadcast never fails the write (reported with ids only, as `SessionStatusNotBroadcast` is). A page that has no socket polls the state every 3 seconds in the lobby and after it has answered; the phase changes come from the timetable either way.
10. **Late, lost and gone.**
    - _Late join_ (the room is not locked): the player gets the current state like everyone, may answer the round that is open with the time that is left, and has nothing for the rounds before.
    - _Reconnect or reload_: the state is everything; an answer sent twice is stored once.
    - _A player who vanishes without leaving_ costs the room at most the rest of each round's time; the host can remove them.
    - _The host leaves or loses the connection_: the game goes on by its timetable. Only a new game, the lock, a removal and closing wait for the host; a room nobody tends closes when it has been idle ([020](020-rooms-and-joining.md)). The host is never replaced.
    - There is no pause. The host may end a game (`EndRoomGame`); rounds that never closed give nothing.
11. **The big screen is a page of the host's own session**, `GET /rooms/{room}/screen` (page `rooms/screen`): the code and its QR while the room waits, then what every player sees of a round, the count of answers, the reveal and the ranking. It reads the same state and writes nothing. It is **not** the guest board: `/lovhe`, the `board` guard and its bearer stay what they are, reach only a class or home game, and never show a room.

## Consequences

- Every phone asks the server at each phase change: about three requests per player per round, at most `max_players` at the same instant. That is the price of holding the content back, and it bounds the size of a room on one server.
- Speed includes each phone's network time. The points rule counts speed in coarse steps for that reason ([022](022-room-scoring-and-history.md)).
- A memory round's material is on the phone while it is studied, so a screenshot defeats it. The design removes the cheap cheat — reading a solution out of the page — not that one; a room is people who invited each other.
- The children's protocol is untouched: `RecordBoardEvents`, `board_epoch`, `RoundJudge`, `engine.ts`, `SolutionData` and the `game.*` channel have no new caller and no new case.

## Alternatives rejected

- **A single writer, as in class games** (the host's device, or the big screen, judges the room's client events and syncs them): the host is a contestant and would hold every solution; a host whose phone sleeps would freeze the room; a big screen would become required.
- **Sending the solution hashed, so the phone can judge:** a choice among four is guessed from its hash in four tries.
- **Rounds, reveals and rankings in broadcasts:** content and results on the socket, against "payloads carry ids only", and a phone that misses one message has a hole; the state over HTTP is whole every time.
- **Client events between players** (whispers for answers or progress): Reverb relays them unjudged from any member.
- **A queue with delayed jobs, or a worker that ticks the rooms:** a new service and a new failure mode on a one-server instance, for a change every device can compute.
- **Settling on read** (the first request after the bell writes the close): a `GET` that writes, and a timetable that drifts with whoever looks late.
- **The device's clock for elapsed time:** trivially forged.
- **A grace period after the bell:** the solution is already being served.
- **The guest board as the big screen:** a bearer that today reaches one children's game would reach a room, and the board page would carry adults' games.
- **A presence channel:** see [020](020-rooms-and-joining.md).

## What would make us revisit

Rooms of hundreds (a server push per phase, and a queue or a second server), a game whose rounds cannot be built before it starts, or measured unfairness from network time that coarse steps do not hide.
