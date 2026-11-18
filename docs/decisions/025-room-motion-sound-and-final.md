# 025 — A room game's motion, its sound and its final

- **Status:** Accepted, 2026-11-18 (the owner's three decisions of that day; "Motion" built on the branch `feat/rooms-polish`, "Sound" by the next step)
- **Sections:** architecture.md §12 "Room games", "Guard tests"; docs/frontend.md rule 15
- **Amends:** [023](023-room-games-and-content.md) decision 11 ("No Phaser, no sound and no voice in a room in the first stage"): Phaser and voice stay out; motion is designed and sound comes, as below. docs/frontend.md rule 15: the ranking's "No medal colours, podium or confetti" and the last bullet's "No sound, voice, Phaser, confetti or vibration" give way to the final and to the two parts below. [021](021-room-race-protocol.md) and [022](022-room-scoring-and-history.md) are unchanged: the device still never judges, scores or keeps the time.
- **Why it is hard to reverse:** a room is played by several people in one room at once. What moves, what sounds and on which device are habits the players learn, and a sound or a movement that told one phone's owner something before the others would break the race's fairness, which no later fix can take back from a game already played.

## Context

B3–B6 built the room games to be correct: a phone draws the phase the state says, and a phase change replaces one block with the next. Every moment of a game — the countdown, the study, the question, a tap, the answer sent, the reveal, the ranking, the game's end — appears the same way, at once, at one size. The owner asked on 2026-11-18 that the game itself feel finished, on the phone and on the big screen, and decided:

1. **The scope is the game itself**, phone (`rooms/show` → `LiveRound`) and screen (`rooms/screen`). The list of rooms, the entrance, the lobby, the setup and a closed room stay as they are.
2. **Sound** — the screen is the room's speaker; a phone plays only quiet cues of its own; each device has a mute. No vibration and no background music.
3. **A festive final** — the places open from the last to the first, the first three are emphasised, the winner gets a short confetti and a fanfare, mainly on the screen.

Two things constrain all three. The state is all a page knows (§12 "The state"): it is fetched again at every moment of the timetable, on every `room.changed` and every 3 s by a page without a socket, so a page re-renders the same moment many times; and a page may be opened or reloaded in the middle of any moment. And before the reveal nothing on a device may differ by whether an answer is right — the device does not know, and must not look as if it did.

## Decision

### Motion (built on `feat/rooms-polish`)

1. **Motion follows the state, never the reverse.** Every movement is started by a moment of the state `useLiveRoom()` gives — a game, a round's index and phase, a receipt, a count — or by the viewer's own tap, and it changes nothing: no phase is shortened or lengthened by an animation, no tap waits for one, and the time shown is still `timeLeft()` / `serverClock()`'s.
2. **A moment animates only when the page saw it begin.** Its key is the game, the round's index and its phase (`roomMoment()`, `components/rooms/motion.ts`); a page draws the moment it opened on — a reload, a late join, the screen opened in the middle — still and complete, and animates every later one. Entrances are keyed on that key, never on a render, so a state fetched again (`room.changed`, the poll) replays nothing.
3. **Nothing differs by right or wrong before the reveal.** The answer sent has one movement for every answer, from the receipt's status only (`accepted`, `duplicate`, or the state's `answered`); a tap's movement is the same for every item; the count of answers moves when `answered_count` changes. From the reveal, the verdict comes in the same way for a right and a wrong answer and differs only by its word, its icon and its colour (rule 15: never a colour or a movement alone).
4. **The study stops on time.** The material comes in within a quarter of a second and goes at once when the estimated server clock reaches `answer_from_ms`, with no fade that would show it longer: a row turns face down and a grid goes dark until the question arrives. The question's items come in within 0.2 s, the same on every device.
5. **Numbers that move are the server's.** A verdict's points count up from 0 to `mine.points`; a ranking's totals count up from `points − result.points` to `points` (both from the state); the rows of a ranking move from the order of the previous reveal the page showed to the new one, and only when it showed one. Places and their labels are the server's (`place_labels`, `standingGroups()`): players who share a place move, open and are emphasised together. Nothing ranks, adds up or judges on the device.
6. **A still form for every movement.** Under `prefers-reduced-motion` nothing moves: the classes are `motion-safe:` (or the `room-motion:` variant, which holds the same media query), a count shows its final number, the rows stand in their places and there is no confetti. A moment then looks exactly as when the page is opened on it.
7. **The look stays rule 15's.** Tokens and the room's own colours in both themes, right and wrong as a word with an icon, a round that fits 375 × 667 with its 44 px and 52 px controls, text as the server cased it. The keyframes live once in `resources/css/app.css` (`--animate-room-*`, next to the welcome page's), and the pure helpers in `components/rooms/motion.ts` and `components/rooms/final.ts`, covered by Vitest.

### The final (built on `feat/rooms-polish`)

8. **The final is the end of one game.** When the latest game is over (`game.phase = over`) while the room is open, the screen and the room page show the state's ranking — the room's standing after this game, which after a room's first game is that game's (§12 "The state") — as the final (`FinalStanding`), a variant of `Standing`. The places open from the last to the first — the players without points first, then each place, the first three slower, the first last — and a place that players share opens at once with all of them. The first three places are emphasised by size, weight and an icon (`Trophy` for the first, `Medal` for the second and the third) with the room's amber for the first; no medal palette and no podium of steps.
9. **Confetti is short and has a place.** `canvas-confetti` (the package the podium uses, never a children's component) bursts once when the first place opens and is gone within two seconds: on the screen always, on a phone only for a player who holds the first place. Never on a reload, never with reduced motion, never in a reveal.
10. **Only there.** The reveal's «Sıralama», the lobby's list of players and a closed room's results keep the plain `Standing`. Still no total, rating or profile across rooms: the final shows one room's standing, as the lobby did.

### Sound (the next step builds it)

11. **The screen is the room's speaker.** It plays the moments everybody shares: the countdown's seconds, the start of the study and of the question, a soft tick in the last 3 s of an answer, one neutral sound when the round closes, the reveal and the ranking, and in the final a roll while the places open and a short fanfare when the first place opens. Its reveal sound is the same whatever anyone answered: the screen has no viewer.
12. **A phone plays only quiet cues of its own:** its own tap, its own answer sent — one sound for every answer — and, from the reveal, its own verdict. A phone never plays the room's moments, so twenty phones never ring together.
13. **Each device has a mute** — a toggle with `aria-pressed` in the screen's top bar and in the round's head on the phone, remembered on that device only (browser storage, read inside `try/catch`). The screen's sound starts with a tap (browsers allow no sound before one).
14. **Nothing audible about right or wrong before the reveal**, by the same rule as decision 3. No voice, no speech synthesis, no vibration, no background music. Room sounds are files of their own with a credits entry and an allowed license, separate from the children's `content/sounds.json`, and reach a page as URLs in its props (rule 9).

## Consequences

- The motion of a moment is the same on every device that saw it begin, so a room watching the screen and its phones sees one game.
- A page opened in the middle of a game shows less motion than one that was there from the start. That is the price of never replaying a moment; a moment is never shown half-built.
- The final celebrates the room's standing after the game. For a room's first game it is that game's result; after several games the first place is the room's leader. If the owner wants each game's own winner, the state needs that game's ranking once it is over — a change of `RoomStateData` and its fixtures, decided separately.
- Sound has a place for every moment before it is built: the next step adds files and a player, not new triggers.

## Alternatives rejected

- **Motion keyed on a render or on the state's revision:** every poll would replay it.
- **Animating a moment the page opened on** ("the final always plays"): a reload during the final, or the screen opened after it, would show it twice to the same room.
- **A fade-out of the study:** it would show the material after `answer_from_ms` on some devices and not others.
- **A shake for a refused answer or a burst for a sent one:** a movement read as "wrong" or "right" before the reveal.
- **A ranking re-sorted on the device to animate it:** places are the server's; the rows move only between two orders the server gave.
- **Confetti on every phone, or medal colours and a podium of steps:** the owner asked for a final mainly on the screen; a podium is the children's projector page and stays theirs.
- **Phones as speakers of the room:** twenty phones out of step; the screen is one speaker everyone hears.

## What would make us revisit

A wish to celebrate each game's own winner after several games (the state's ranking at `over`), a room played without a screen often enough that phones should carry the room's sounds, or a player who asks for less motion than the system setting gives.
