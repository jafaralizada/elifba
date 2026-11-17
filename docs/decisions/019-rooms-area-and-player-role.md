# 019 — The rooms area: a third role, `player`, behind a switch

- **Status:** Proposed, 2026-11-17 (roadmap step B1; accepted when the owner approves the design in B1-O; built in B2)
- **Sections:** architecture.md §0, §1 "Accounts and roles", §7 "Agreements", §12 "The area and the switch"
- **Amends:** [010](010-account-roles.md): the third role its last consequence foresaw, a fifth route group, and a role that can be switched off. [013](013-parents-and-their-childrens-privacy.md) decision 2: a third sentence, for the third role.
- **Why it is hard to reverse:** every account that plays gets a role row and a stamped agreement, every room route is placed in an area by that role, and the public privacy page names the role. Taking the role out again means deciding what happens to the rooms and results those accounts hold.

## Context

Elifba has served children only: a teacher's class and a parent's own children. The owner decided (2026-11-17) to add games for adults — memory, logic and sequences, word games in Azerbaijani — played as a live race in a room, and fixed: a **separate area**, not inside the teacher or the parent area, with nothing of it on any class, student, child or board page or in the game catalog; **hidden behind a switch** that is off by default, from teachers and parents too, until launch; **no age**, date of birth or age group anywhere.

Two things were left open and the owner answered them the same day, after the options were compared against the code:

- **Who may open a room: a third role.** The alternatives were any teacher or parent (an adult who only wants to play would have to accept «məktəbimin icazəsi ilə» or «valideyniyəm», and the area would sit in their navigation), and an account with no role (it breaks "an account without a role sees only the role choice" and the rule that every authenticated route names a role, which `RoleAreasTest` enumerates).
- **Whether a joiner needs an account: yes, every player** ([020](020-rooms-and-joining.md)). So the same role serves the one who opens a room and the ones who join it.

## Decision

1. **One new role, `player`.** `App\Enums\Role::Player = 'player'` («Oyunçu»), a third `roles` row (guard `web`) created by B2's migration, `Role::agreementColumn()` → `player_agreed_at`, `Role::homeRoute()` → `rooms.index`. An account may hold it alone or beside `teacher` and `parent`. There is no "host" role: the **host** of a room is the account that opened it (`rooms.user_id`), and any account with the role may open a room or join one.
2. **A fifth route group, the player area.** `auth`, `role.chosen`, `verified`, `Role::middleware(Role::Player)` (`role:player`) and `rooms.enabled` (decision 4). Every room route is in it and nowhere else (`routes/rooms.php`): `/rooms…`, `/otaq…`. The shared group stays `role:teacher|parent`: the board page, the remote, `/pult` and the guest board never name the player role. `users.area` may be `player`; the shell draws a third navigation («Otaqlar») from `components/app/nav-areas.ts`, and the user menu's switch lists the areas the account holds.
3. **Its own sentence, stamped when the role is taken** (`users.player_agreed_at`, by `GrantRole`, on `/role` or Settings → «Rollar»): «Otaqlarda öz adımla oynayıram; adım və nəticələrim olduğum otağın oyunçularına görünür.» / "I play in rooms under my own name; my name and my results are shown to the players of the rooms I am in." It is the adult's own consent to the one thing that is new for an account: other accounts see its name ([024](024-adults-privacy-in-rooms.md)).
4. **The switch.** `config('elifba.rooms.enabled')`, from `ELIFBA_ROOMS`, **false** unless set. It is configuration, not schema: the tables and the `roles` row exist either way. While it is off:
    - `rooms.enabled` (`App\Http\Middleware\EnsureRoomsAreEnabled`) answers **404** on every room route — the routes always exist, so Wayfinder generates them everywhere, as with Google sign-in (ADR 008);
    - `Role::available()` leaves `player` out: `/role` and Settings → «Rollar» show no «Oyunçu» card, and `RoleChoiceData::rules()` refuses the role, so `GrantRole` gives it to nobody;
    - `User::roleList()` and `User::area()` leave the role out, so `auth.roles`, `auth.area`, the navigation, the user menu's switch and `/start` behave as if the account did not hold it (an account that holds nothing else is sent to `/role`);
    - the room channel refuses everyone ([021](021-room-race-protocol.md));
    - the welcome page and `/privacy` say nothing of rooms: their section for rooms (B7) is drawn only from a prop their controllers send while the switch is on; the READMEs describe the area and say that it is off unless `ELIFBA_ROOMS=true`.
5. **Nothing of rooms on the other side — with the switch on, too.** No class, student, child, ranking, podium, words, setup, board, remote, `/pult` or guest board page receives a prop, a link, a navigation item or a request about rooms; the game catalog lists no room game; `content/levels.json`, `content/prompts.json`, `Content\Enums\Mechanic` and `Playable` do not change ([023](023-room-games-and-content.md)). The only things an account sees outside the player area are the «Oyunçu» card where roles are taken, and — once it holds the role — «Otaqlar» in its own user menu's area switch.
6. **An invited account's way in.** `/otaq/{code}` is in the player area, so a visitor is sent to the login (or registers) and comes back to the same address, as `/pult/{code}` does. An account without the role is sent to where roles are taken — `/role` with no role, Settings → «Rollar» otherwise — with «Oyunçu» preselected, and once `GrantRole` has given the role, the page that took it (`RoleController`, `Settings\RolesController`) goes on to the intended address — only a page of the player area of this app (architecture §12, B2's "As built"). The 403 → `/start` rule of ADR 010 keeps applying to every other area.
7. **Guard tests grow in B2**, so the separation is proven, not promised:
    - `tests/Feature/Auth/RoleAreasTest.php`: the fifth group in "puts every authenticated route in one area"; "refuses an account without the player role on every room route"; "keeps the board, the remote and the pult out of the player role".
    - `tests/Feature/Rooms/RoomsSwitchTest.php`: "answers 404 on every room route while the switch is off", "offers and grants no player role while the switch is off", "shares no player role or area with any page while the switch is off".
    - `tests/Feature/Rooms/RoomsApartTest.php`: "sends nothing about rooms to a class, child, ranking, words, setup, board, remote, pult or guest board page", "takes no class, student, child, game or word in any room route", "keeps the domains apart" (no file outside the Rooms code names `App\Domain\Rooms`, and none inside it names `Classroom`, `Family` or `Play`).
    - `tests/Feature/Play/BearerReachTest.php`: every room route answers a guest board's bearer as it answers a visitor. `tests/Feature/Classroom/TeacherIsolationTest.php`: `room` and `member` join `ISOLATED_PARAMETERS`, with a case per route. `tests/Feature/Family/ChildPagesTest.php`: "sends nothing about rooms to a page of the parent area". `tests/Feature/Play/GameCatalogTest.php`: "lists no room game". `tests/Feature/Auth/RolesTableTest.php`: three rows.
8. **Board settings belong to the roles that have a board.** Settings → «Oyun» (`play-settings.*`: the phone remote, the board's sounds and voice) moves from the account group to the shared group, so an account that holds only the player role is not offered settings of boards it cannot open.
9. **No age.** Nothing asks for or stores an age, a date of birth or an age group, for the role, a room or a game; a room's game is chosen by difficulty ([023](023-room-games-and-content.md)).

## Consequences

- An adult who is neither a teacher nor a parent can have an account that says nothing untrue.
- A teacher or a parent who never takes the role never sees the area, even after launch.
- An instance can run Elifba for schools and families only, for ever: the switch stays off and nothing shows.
- One more tick on the way into a first room (registration's privacy note, then the role's sentence).
- An account can hold a role whose area is switched off; it loses nothing, and the area returns with the switch.

## Alternatives rejected

- **Any teacher or parent may open a room:** each would agree to a sentence that has nothing to do with rooms, the area would live inside theirs against the owner's decision, and an adult who is neither would have to claim to be one.
- **An account with no role:** two tested rules would get an exception (the role choice, the route groups), and nothing would record what the account agreed to.
- **Two roles, host and player:** a matrix for one area; who opened a room is a fact about the room.
- **A permission (`rooms.open`):** ADR 010 keeps the package to roles only.
- **Separate accounts or a separate app for adults:** a teacher who also plays would hold two logins, and two codebases would share Azerbaijani language code.
- **The switch as a database flag or a per-account beta list:** one more table to guard; `.env` is how an instance already turns Google sign-in on.
- **Removing routes when the switch is off:** Wayfinder's generated files would differ between machines.

## What would make us revisit

Rooms that a school or a company runs for its own people (an organiser who manages players would be a permission, not a role), or a legal duty to verify age in a country where an instance runs.
