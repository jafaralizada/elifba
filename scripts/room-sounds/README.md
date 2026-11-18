# Room sounds

`content/assets/rooms/sounds/*.mp3` are the sounds of a room game (docs/architecture.md §12 "Room content", ADR 025 "Sound" and its "As built"): the big screen's — the room's speaker — and a phone's quiet cues of its own. They are synthesised by `make.py` and downloaded from nowhere; Elifba's own, MIT, credited in `content/credits.json` ("Sounds of the adults' room games"). `content/rooms/sounds.json` names one file per cue, and the page decides when each plays (`resources/js/components/rooms/sound-cues.ts`).

| File           | Device | Plays                                                           |
| -------------- | ------ | --------------------------------------------------------------- |
| `count.mp3`    | screen | each second of the countdown                                    |
| `study.mp3`    | screen | the study opens                                                 |
| `question.mp3` | screen | the question opens                                              |
| `tick.mp3`     | screen | each of the last three seconds of an answer                     |
| `close.mp3`    | screen | the round closes and the reveal opens, whatever anyone answered |
| `ranking.mp3`  | screen | the reveal's ranking comes in                                   |
| `roll.mp3`     | screen | the final, ending where the first place opens                   |
| `fanfare.mp3`  | screen | the final's first place opens                                   |
| `tap.mp3`      | phone  | the viewer's tap, the same for every item                       |
| `undo.mp3`     | phone  | «Geri», or a cell tapped again                                  |
| `sent.mp3`     | phone  | the viewer's answer is in, the same for every answer            |
| `right.mp3`    | phone  | from the reveal: the viewer's answer was right                  |
| `wrong.mp3`    | phone  | from the reveal: the viewer's answer was wrong                  |

No voice, no speech, no music bed and no loop: each file is one short event, and `sent` says nothing of right or wrong.

## Regenerate

You need Python 3 (standard library only) and ffmpeg with `libmp3lame` (`brew install ffmpeg`).

1. Listen first, outside the repository:

    ```sh
    python3 scripts/room-sounds/make.py --out /tmp/room-sounds
    ```

2. Write the files the pages play:

    ```sh
    python3 scripts/room-sounds/make.py
    ```

    It prints each file's length, size, loudness and true peak, and stops when a peak is above `MAX_TRUE_PEAK`. The noise is seeded, so the same numbers give the same files.

3. Bump `version` in `content/rooms/sounds.json` (the URLs carry it, so open pages and caches fetch the new files), then run `php artisan content:validate` (each file at most 48 KB).

## What each number does

Everything to retune is a constant at the top of `make.py`.

- **Loudness.** `LOUDNESS` (−20 LUFS) sets every file. A 70 ms tap is shorter than the 400 ms blocks EBU R128 measures in, so each cue is measured "while it sounds": played back to back for `LOOP_SECONDS` (4 s) and measured by ffmpeg's `ebur128`; three passes correct what the MP3 encoder moves. `MAX_TRUE_PEAK` (−1 dBFS) is the ceiling. How loud a device plays them is the page's (`SPEAKER_GAIN` in `resources/js/rooms/sound.ts`: the screen at full level, a phone at half), never the files'.
- **The phone's cues.** `TAP` (a 70 ms «tock», 1500 → 1100 Hz with a soft click), `UNDO` (90 ms, gliding 900 → 600 Hz), `SENT` (an airy swoosh, noise rising through a band from 700 to 3600 Hz, with a soft click; no pitch, so it reads as neither right nor wrong), `RIGHT` (C6 then E6 on soft bells) and `WRONG` (E4 then C4, round and low, never a buzzer).
- **The screen's cues.** `COUNT` (an A5 pip), `TICK` (a wood-block knock at 2.2 kHz with a 1.8 kHz ping), `STUDY` (an E5–A5–C6 swell with a glockenspiel E6), `QUESTION` (a 600 → 1200 Hz zip into a D6 marimba bell), `CLOSE` (the announcement chime E5 → C5), `RANKING` (six plucks up C5–D5–E5–G5–A5–C6 over a breath of noise), `ROLL` (2.4 s of snare strokes from 60 ms to 30 ms apart, from `quiet` to full, over a 98 Hz timpani swell; the page starts it so that it ends where the first place opens, or from its middle when the final is quicker) and `FANFARE` (G4–C5–E5 rising into a held C major chord on soft brass, a cymbal and a timpani stroke, 1.55 s).
- **Shape.** `FADE_OUT` is the last few milliseconds of each file.

## Method

Additive and subtractive synthesis at 44.1 kHz, mono: bells and marimbas are sine partials with decays of their own (the glockenspiel's 2.76 and 5.4, the marimba's 3.9), pitch glides are sines with an exponential approach, the brass is nine harmonics brightening through a 30–40 ms attack with a late 5.5 Hz vibrato, the noise is seeded white noise through RBJ band-pass biquads (swept for the swooshes). Each sound is peak-normalised, written as 16-bit WAV, levelled by ffmpeg's `ebur128` measurement and encoded as 96 kb/s mono MP3 without tags, as the board's sounds are.

## The set as made (2026-11-18)

| File           | Length  | Size    | Loudness while it sounds | True peak |
| -------------- | ------- | ------- | ------------------------ | --------- |
| `count.mp3`    | 180 ms  | 2.8 KB  | -20.0 LUFS               | -9.7 dBFS |
| `study.mp3`    | 650 ms  | 8.3 KB  | -20.0 LUFS               | -5.2 dBFS |
| `question.mp3` | 420 ms  | 5.8 KB  | -20.0 LUFS               | -8.2 dBFS |
| `tick.mp3`     | 80 ms   | 1.8 KB  | -20.3 LUFS               | -5.2 dBFS |
| `close.mp3`    | 850 ms  | 10.7 KB | -20.0 LUFS               | -8.9 dBFS |
| `ranking.mp3`  | 450 ms  | 6.1 KB  | -20.0 LUFS               | -6.2 dBFS |
| `roll.mp3`     | 2400 ms | 28.8 KB | -20.0 LUFS               | -2.4 dBFS |
| `fanfare.mp3`  | 1550 ms | 19.0 KB | -20.0 LUFS               | -8.1 dBFS |
| `tap.mp3`      | 70 ms   | 1.5 KB  | -20.0 LUFS               | -7.9 dBFS |
| `undo.mp3`     | 90 ms   | 1.8 KB  | -20.0 LUFS               | -8.5 dBFS |
| `sent.mp3`     | 240 ms  | 3.7 KB  | -20.0 LUFS               | -4.1 dBFS |
| `right.mp3`    | 480 ms  | 6.4 KB  | -20.0 LUFS               | -8.8 dBFS |
| `wrong.mp3`    | 400 ms  | 5.5 KB  | -20.0 LUFS               | -9.5 dBFS |
