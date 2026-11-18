# Room sounds

`content/assets/rooms/sounds/*.mp3` are the sounds of a room game (docs/architecture.md §12 "Room content", ADR 025 "Sound", ADR 028): the big screen's — the room's speaker — and a phone's quiet cues of its own. The owner chose each by ear on 2026-11-18 from three candidates (ADR 028 "As chosen"). `make.py` makes every file: nine from recordings of the **VS Chamber Orchestra: Community Edition** (VSCO-2-CE, CC0-1.0), seven by our own synthesis (MIT). `content/rooms/sounds.json` names one file per cue, `content/credits.json` credits each source, and the page decides when each plays (`resources/js/components/rooms/sound-cues.ts`).

| File            | Device | Plays                                                      | Made of                                                                                                                       | Length | Size    |
| --------------- | ------ | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- | ------ | ------- |
| `countdown.mp3` | screen | the countdown before round 0, placed to end at `opens_ms`  | VSCO marimba G5 and C5 (an octave down) on each second, the glockenspiel pentatonic run through the last 0.95 s, a small room | 3.00 s | 35.8 KB |
| `go.mp3`        | screen | round 0 opens                                              | VSCO crash, timpani (C♯2 down to C2), bass drum, staccato trumpets C4–E4–G4–C5                                                | 1.50 s | 18.4 KB |
| `study.mp3`     | screen | a later round's study opens                                | VSCO harp C5–E5–G5 and B5 (up to C6), 55 ms apart                                                                             | 1.00 s | 12.6 KB |
| `question.mp3`  | screen | a question opens                                           | ours: a 600 → 1200 Hz zip into a marimba-like D6, a small room                                                                | 0.55 s | 7.3 KB  |
| `clock.mp3`     | screen | the last 5 s of an answer, placed to end at `bell_ms`      | VSCO log drums high/low every 0.5 s, every 0.25 s from 2 s; a soft bass drum each 0.5 s from 2 s; a cello tremolo C3 swelling | 5.00 s | 59.4 KB |
| `close.mp3`     | screen | the round closes, whatever anyone answered                 | VSCO tubular bell G4 over a soft timpani                                                                                      | 1.30 s | 15.9 KB |
| `ranking.mp3`   | screen | the reveal's ranking comes in                              | ours, the first set's: six plucks C5–C6 over a breath of noise                                                                | 0.45 s | 6.1 KB  |
| `place.mp3`     | screen | the final's third and second places open                   | ours: a kick 110 → 38 Hz, a burst of low noise, a small room                                                                  | 0.80 s | 10.1 KB |
| `roll.mp3`      | screen | the final, placed to end where the first place opens       | VSCO snare roll and timpani roll, swelling 22 and 27 dB                                                                       | 2.50 s | 30.0 KB |
| `fanfare.mp3`   | screen | the final's first place opens — **2.30 s** from `winnerAt` | VSCO trumpets: G4 ×3 (staccato), then G4, C5, E5 held, with horn and trombone C4, crash and timpani                           | 2.30 s | 27.9 KB |
| `join.mp3`      | screen | between games, a player appears                            | ours: a bubble pop, 380 → 1150 Hz                                                                                             | 0.14 s | 2.4 KB  |
| `tap.mp3`       | phone  | the viewer's tap, the same for every item                  | VSCO claves, low-passed at 6 kHz                                                                                              | 0.08 s | 1.8 KB  |
| `undo.mp3`      | phone  | «Geri», or a cell tapped again                             | ours: a 1450 → 1050 Hz «tock» played backwards                                                                                | 0.07 s | 1.5 KB  |
| `sent.mp3`      | phone  | the viewer's answer is in, the same for every answer       | ours, the first set's: noise rising through a band, 700 → 3600 Hz, and a soft click                                           | 0.24 s | 3.7 KB  |
| `right.mp3`     | phone  | from the reveal: the viewer's answer was right             | VSCO harp C5–E5–G5, 60 ms apart                                                                                               | 0.70 s | 8.9 KB  |
| `wrong.mp3`     | phone  | from the reveal: the viewer's answer was wrong             | ours: E4 then C4, round and low, never a buzzer                                                                               | 0.45 s | 6.1 KB  |

The screen's set holds 225.9 KB (at most 320 KB), a phone's 22.0 KB (at most 64 KB); no file above 64 KB (`ContentValidator`). No voice, no speech, no music bed and no loop: each file is one event, played once for its moment; the clock is the same for everyone, and `sent` says nothing of right or wrong.

## Regenerate

You need Python 3 (standard library only), ffmpeg with `libmp3lame` (`brew install ffmpeg`) and, the first time, the network.

1. Listen first, outside the repository:

    ```sh
    python3 scripts/room-sounds/make.py --out /tmp/room-sounds
    ```

    The first run downloads the recordings it uses (about 45 MB) from VSCO-2-CE at commit `440300901dfe9275fd84e0b7763af1f8443ae62e` into `~/.cache/elifba/room-sounds` (`--cache` moves it) and checks each against its SHA-256 in `VSCO`; a file that does not match stops the run, and nothing of it is written. Recordings are never committed.

2. Write the files the pages play (`--only clock fanfare` makes only those):

    ```sh
    python3 scripts/room-sounds/make.py
    ```

    It prints each file's length, size, loudness and true peak. The synthesis is seeded and the MP3 encoder runs bit-exact, so the same numbers, recordings and ffmpeg give the same bytes.

3. Bump `version` in `content/rooms/sounds.json` (the URLs carry it, so open pages and caches fetch the new files), run `php artisan content:validate`, and `php artisan content:credits` when a source changed.

A cue changed by ear is auditioned again before it is committed (ADR 028 decision 1).

## Sources

**VS Chamber Orchestra: Community Edition** — recorded by Sam Gossner and Simon Dalzell (Versilian Studios), sample cutting by Elan Hickler (Soundemote); <https://github.com/sgossner/VSCO-2-CE>, CC0-1.0 (its `LICENSE`). `VSCO` in `make.py` names each file used, its path in the repository and its SHA-256: timpani hits (Timpani3 v1 and v3) and roll (Timpani2), snare roll (Snare2 rollSN v5), crash cymbal (crash1 ff), bass drum (BDrumNewhit v3, v5), tubular bell (G4), log drums (high and low, v2), claves, glockenspiel run (pentatonic, medium), marimba (the C4 and G4 samples, sounding C5 and G5), harp (C5, E5, G5, B5), cello section tremolo (C3), trumpet staccato (C3, D♯3, G3, A♯3) and sustained (G3, A♯3, D4), French horn (C3) and tenor trombone (C3). The library names its trumpets, horn, trombone and marimba an octave lower than they sound; the table above names what sounds.

What `make.py` does to a recording: mixes it to mono at 44.1 kHz; cuts it from its onset (the first sample above −36 dB of its peak, less 2 ms) or from a fixed point in a roll; normalises its peak; pitches some by resampling (`semitones`, which also shortens them a little); crops and fades it; shapes its level over time (`ramp`, in dB); filters some (low- and high-pass); layers them, some in a small synthetic room (`reverb`, a Freeverb); then levels and encodes as below.

## Levelling

- **Loudness.** `LOUDNESS` (−20 LUFS) for every file, by ffmpeg's `ebur128`. A cue shorter than `LOOP_SECONDS` (4 s) is measured played back to back, "while it sounds". A cue that builds (`BUILDS`: countdown, clock, roll) is measured by its last second, looped — its climax, which leads into the next cue — so it never ends louder than what follows it.
- **Peaks.** A look-ahead limiter (`alimiter`, its delay compensated so no cue moves in time) holds sample peaks at `LIMIT_DB` (−3 dBFS) while a cue reaches its loudness; a true peak above `MAX_TRUE_PEAK` (−1 dBFS) after encoding lowers the limiter by 1 dB and tries again.
- **Encoding.** 16-bit mono WAV peak-normalised to −6 dBFS with a 6 ms fade at its end, then 96 kb/s mono MP3 without tags, up to four passes until the loudness is within 0.05 LU.
- **Kept from the first set.** `ranking` and `sent` are the first set's files (ADR 025): their recipes, their seeded noise and their levelling (three passes, no limiter) are kept as `a48e56c` made them, so the files do not change.
- **On the device.** How loud a device plays them is the page's (`SPEAKER_GAIN` in `resources/js/rooms/sound.ts`: the screen at full level, a phone at half), never the files'.

## The set as made (2026-11-18)

| File            | Length  | Size    | Loudness                     | True peak |
| --------------- | ------- | ------- | ---------------------------- | --------- |
| `countdown.mp3` | 3000 ms | 35.8 KB | −20.0 LUFS (its last second) | −3.3 dBFS |
| `go.mp3`        | 1500 ms | 18.4 KB | −20.0 LUFS                   | −3.1 dBFS |
| `study.mp3`     | 1000 ms | 12.6 KB | −20.0 LUFS                   | −6.8 dBFS |
| `question.mp3`  | 550 ms  | 7.3 KB  | −20.0 LUFS                   | −7.3 dBFS |
| `clock.mp3`     | 5000 ms | 59.4 KB | −20.0 LUFS (its last second) | −3.4 dBFS |
| `close.mp3`     | 1300 ms | 15.9 KB | −20.0 LUFS                   | −3.2 dBFS |
| `ranking.mp3`   | 450 ms  | 6.1 KB  | −20.0 LUFS                   | −6.2 dBFS |
| `place.mp3`     | 800 ms  | 10.1 KB | −20.0 LUFS                   | −3.4 dBFS |
| `roll.mp3`      | 2500 ms | 30.0 KB | −20.0 LUFS (its last second) | −5.8 dBFS |
| `fanfare.mp3`   | 2300 ms | 27.9 KB | −20.0 LUFS                   | −6.8 dBFS |
| `join.mp3`      | 140 ms  | 2.4 KB  | −20.0 LUFS                   | −9.4 dBFS |
| `tap.mp3`       | 80 ms   | 1.8 KB  | −20.3 LUFS                   | −6.6 dBFS |
| `undo.mp3`      | 70 ms   | 1.5 KB  | −20.0 LUFS                   | −8.2 dBFS |
| `sent.mp3`      | 240 ms  | 3.7 KB  | −20.0 LUFS                   | −4.1 dBFS |
| `right.mp3`     | 700 ms  | 8.9 KB  | −20.0 LUFS                   | −6.7 dBFS |
| `wrong.mp3`     | 450 ms  | 6.1 KB  | −20.0 LUFS                   | −9.0 dBFS |
