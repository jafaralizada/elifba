#!/usr/bin/env python3
"""
The sounds of a room game (docs/architecture.md §12 "Room content", ADR
025 "Sound"): the screen's — the room's speaker — and a phone's quiet cues
of its own, synthesised here and downloaded from nowhere. Plain Python 3
(standard library only) renders each sound to WAV; ffmpeg levels the set
to one loudness and writes the mono MP3 files a room page plays.

    python3 scripts/room-sounds/make.py            # writes content/assets/rooms/sounds/*.mp3
    python3 scripts/room-sounds/make.py --out DIR  # writes them elsewhere, to listen first

The numbers to retune are the constants below; scripts/room-sounds/README.md
says which does what. No voice, no speech, no music bed and no loop: each
file is one short event.
"""

import argparse
import math
import random
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import wave
from pathlib import Path

RATE = 44100
ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "content" / "assets" / "rooms" / "sounds"

# One loudness for the whole set: EBU R128 integrated loudness of each cue
# played back to back for LOOP_SECONDS ("while it sounds": a 70 ms tap is
# shorter than the 400 ms blocks R128 measures in, so it is measured as a
# run of itself). The page sets how loud a device plays them (a phone at
# half the screen's level), never the files.
LOUDNESS = -20.0
LOOP_SECONDS = 4.0
# The highest true peak a file may have after levelling.
MAX_TRUE_PEAK = -1.0
# The same noise every run, so the files only change when a number does.
SEED = 2026
# The last few milliseconds of every file fade to silence (no click).
FADE_OUT = 0.006

# Notes (Hz).
C4, E4 = 261.63, 329.63
G4, C5, D5, E5, G5, A5 = 392.00, 523.25, 587.33, 659.26, 783.99, 880.00
C6, D6, E6 = 1046.50, 1174.66, 1318.51

# ── the phone's quiet cues ──────────────────────────────────────────────
# A tap: a soft «tock» whose pitch drops a little, the same for every item.
TAP = dict(length=0.07, start=1500.0, end=1100.0, glide=0.02, decay=0.012, click=0.25)
# Taking a tap back («Geri», a cell tapped again): lower and gliding down.
UNDO = dict(length=0.09, start=900.0, end=600.0, glide=0.06, decay=0.022)
# The answer accepted — one sound for every answer: a short airy swoosh
# (noise rising through a band) ending in a soft click, no pitch to read.
SENT = dict(length=0.24, low=700.0, high=3600.0, q=1.6, peak=0.09, click_at=0.17)
# The viewer's verdict, from the reveal only: right rises a major third on
# two soft bells; wrong falls a major third on two low, round notes.
RIGHT = dict(notes=((C6, 0.0), (E6, 0.09)), decay=0.16, length=0.48)
WRONG = dict(notes=((E4, 0.0), (C4, 0.12)), decay=0.11, length=0.40)

# ── the screen: the room's moments ───────────────────────────────────────
# Each second of the countdown: a clear pip.
COUNT = dict(freq=A5, decay=0.06, length=0.18)
# Each of the last three seconds of an answer: a soft wood-block tick.
TICK = dict(freq=1800.0, band=(2200.0, 8.0), decay=0.011, length=0.08)
# The study opens: a soft swell of a chord with a glockenspiel accent.
STUDY = dict(chord=(E5, A5, C6), swell=0.12, decay=0.22, accent=(E6, 0.11), length=0.65)
# The question opens: a quick upward zip into a bell.
QUESTION = dict(zip=(600.0, 1200.0, 0.06), bell=D6, decay=0.15, length=0.42)
# The round closes (and the reveal opens), whatever anyone answered: the
# two-note announcement chime, a major third down.
CLOSE = dict(notes=((E5, 0.0), (C5, 0.22)), decay=0.30, length=0.85)
# The ranking moves: a quick run of soft plucks up a pentatonic scale over
# a breath of noise.
RANKING = dict(notes=(C5, D5, E5, G5, A5, C6), step=0.035, decay=0.07, length=0.45)
# The final's roll: snare strokes ever closer and louder, ending where the
# first place opens (the page lines its end up with that moment).
ROLL = dict(length=2.4, first_gap=0.060, last_gap=0.030, quiet=0.12, body=190.0, timpani=98.0)
# The fanfare when the first place opens: a rising arpeggio into a held
# major chord, with a cymbal and a timpani stroke on the chord.
FANFARE = dict(
    arpeggio=((G4, 0.0), (C5, 0.11), (E5, 0.22)),
    chord_at=0.33,
    chord=(C5, E5, G5, C6),
    length=1.55,
    release=0.35,
    timpani=98.0,
)


def silence(length):
    return [0.0] * int(length * RATE)


def place(out, at, signal, gain=1.0):
    """Adds `signal` into `out` from `at` seconds."""
    first = int(at * RATE)

    for i, s in enumerate(signal):
        if 0 <= first + i < len(out):
            out[first + i] += gain * s

    return out


def envelope(t, attack, decay):
    """A quick rise and an exponential fall."""
    return (1 - math.exp(-t / attack)) * math.exp(-t / decay) if t >= 0 else 0.0


def glide(length, start, end, time, decay, attack=0.001, partials=((1.0, 1.0),)):
    """A tone whose pitch moves from `start` to `end` over `time`, then holds."""
    out = []
    phase = 0.0

    for i in range(int(length * RATE)):
        t = i / RATE
        freq = end + (start - end) * math.exp(-t / max(time / 4, 1e-6)) if time > 0 else start
        phase += 2 * math.pi * freq / RATE
        value = sum(gain * math.sin(ratio * phase) for ratio, gain in partials)
        out.append(value * envelope(t, attack, decay))

    return out


def bell(freq, length, decay, partials=((1.0, 1.0, 1.0), (2.0, 0.3, 0.6), (3.0, 0.1, 0.4)), attack=0.002):
    """Additive partials (ratio, gain, decay as a share of `decay`)."""
    out = []

    for i in range(int(length * RATE)):
        t = i / RATE
        out.append(
            sum(gain * math.sin(2 * math.pi * freq * ratio * t) * envelope(t, attack, decay * share) for ratio, gain, share in partials)
        )

    return out


def noise(length, rng):
    return [rng.uniform(-1.0, 1.0) for _ in range(int(length * RATE))]


def biquad(signal, coefficients):
    """A biquad whose coefficients may change per sample (`coefficients(i)`)."""
    out = [0.0] * len(signal)
    x1 = x2 = y1 = y2 = 0.0

    for i, x in enumerate(signal):
        b0, b1, b2, a1, a2 = coefficients(i)
        y = b0 * x + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2
        out[i] = y
        x2, x1 = x1, x
        y2, y1 = y1, y

    return out


def bandpass_coefficients(centre, q):
    """An RBJ band-pass biquad (0 dB peak gain)."""
    w0 = 2 * math.pi * min(centre, RATE * 0.45) / RATE
    alpha = math.sin(w0) / (2 * q)
    a0 = 1 + alpha

    return alpha / a0, 0.0, -alpha / a0, -2 * math.cos(w0) / a0, (1 - alpha) / a0


def bandpass(signal, centre, q):
    fixed = bandpass_coefficients(centre, q)

    return biquad(signal, lambda i: fixed)


def sweep(signal, low, high, q):
    """A band-pass whose centre rises from `low` to `high` over the signal."""
    n = max(1, len(signal) - 1)
    cache = {}

    def at(i):
        step = i // 32  # coefficients every 32 samples are smooth enough

        if step not in cache:
            cache[step] = bandpass_coefficients(low * (high / low) ** (min(i, n) / n), q)

        return cache[step]

    return biquad(signal, at)


def shaped(signal, attack, decay):
    return [s * envelope(i / RATE, attack, decay) for i, s in enumerate(signal)]


def brass(freq, length, attack=0.03, release=0.3, vibrato_from=0.45, harmonics=9):
    """A soft brass-like note: harmonics that brighten through the attack, a late vibrato."""
    out = []
    phase = 0.0

    for i in range(int(length * RATE)):
        t = i / RATE
        wobble = 1 + (0.004 * math.sin(2 * math.pi * 5.5 * t) if t > vibrato_from else 0.0)
        phase += 2 * math.pi * freq * wobble / RATE
        bright = min(1.0, t / attack)
        value = sum(
            (1 / n**0.9) * (bright ** (n / 3)) * math.sin(n * phase)
            for n in range(1, harmonics + 1)
        )
        level = min(1.0, t / 0.012) * (1.0 if t < length - release else max(0.0, (length - t) / release))
        out.append(value * level)

    return out


# ── the cues ─────────────────────────────────────────────────────────────


def tap(rng):
    p = TAP
    body = glide(p["length"], p["start"], p["end"], p["glide"], p["decay"], partials=((1.0, 1.0), (2.0, 0.12)))
    click = shaped(bandpass(noise(p["length"], rng), 3200.0, 1.2), 0.0005, 0.003)

    return place(body, 0.0, click, p["click"])


def undo(rng):
    p = UNDO

    return glide(p["length"], p["start"], p["end"], p["glide"], p["decay"], partials=((1.0, 1.0), (2.0, 0.08)))


def sent(rng):
    p = SENT
    air = sweep(noise(p["length"], rng), p["low"], p["high"], p["q"])
    rise = p["peak"]
    out = []

    for i, s in enumerate(air):
        t = i / RATE
        level = (t / rise) ** 2 if t < rise else math.exp(-(t - rise) / 0.035)
        out.append(s * level)

    click = shaped(bandpass(noise(0.03, rng), 2600.0, 2.0), 0.0005, 0.004)

    return place(out, p["click_at"], click, 0.35)


def right(rng):
    p = RIGHT
    out = silence(p["length"])

    for freq, at in p["notes"]:
        place(out, at, bell(freq, p["length"] - at, p["decay"]), 0.8)

    return out


def wrong(rng):
    p = WRONG
    out = silence(p["length"])
    round_note = ((1.0, 1.0, 1.0), (2.0, 0.18, 0.5), (3.0, 0.04, 0.3))

    for freq, at in p["notes"]:
        place(out, at, bell(freq, p["length"] - at, p["decay"], partials=round_note, attack=0.006), 0.9)

    return out


def count(rng):
    p = COUNT

    return bell(p["freq"], p["length"], p["decay"], partials=((1.0, 1.0, 1.0), (2.0, 0.15, 0.5)), attack=0.002)


def tick(rng):
    p = TICK
    knock = shaped(bandpass(noise(p["length"], rng), *p["band"]), 0.0004, p["decay"])
    tone = bell(p["freq"], p["length"], p["decay"] * 0.9, partials=((1.0, 1.0, 1.0),), attack=0.0005)

    return place(knock, 0.0, tone, 0.35)


def study(rng):
    p = STUDY
    out = silence(p["length"])

    for n, freq in enumerate(p["chord"]):
        detune = 1 + 0.002 * (n - 1)
        for i in range(len(out)):
            t = i / RATE
            level = (t / p["swell"]) ** 1.5 if t < p["swell"] else math.exp(-(t - p["swell"]) / p["decay"])
            out[i] += 0.3 * level * math.sin(2 * math.pi * freq * detune * t)

    freq, at = p["accent"]
    glock = ((1.0, 1.0, 1.0), (2.76, 0.25, 0.35), (5.4, 0.08, 0.2))

    return place(out, at, bell(freq, p["length"] - at, p["decay"] * 0.9, partials=glock), 0.55)


def question(rng):
    p = QUESTION
    low, high, time = p["zip"]
    out = silence(p["length"])
    place(out, 0.0, glide(time, low, high, time, 0.05, attack=0.004, partials=((1.0, 1.0),)), 0.35)
    marimba = ((1.0, 1.0, 1.0), (3.9, 0.22, 0.25), (2.0, 0.15, 0.6))

    return place(out, time * 0.8, bell(p["bell"], p["length"] - time * 0.8, p["decay"], partials=marimba), 0.9)


def close(rng):
    p = CLOSE
    out = silence(p["length"])
    chime = ((1.0, 1.0, 1.0), (2.0, 0.25, 0.55), (3.0, 0.08, 0.35), (4.07, 0.04, 0.25))

    for freq, at in p["notes"]:
        place(out, at, bell(freq, p["length"] - at, p["decay"], partials=chime, attack=0.003), 0.8)

    return out


def ranking(rng):
    p = RANKING
    out = silence(p["length"])
    pluck = ((1.0, 1.0, 1.0), (2.0, 0.35, 0.5), (3.0, 0.12, 0.3))

    for n, freq in enumerate(p["notes"]):
        at = n * p["step"]
        place(out, at, bell(freq, p["length"] - at, p["decay"], partials=pluck, attack=0.001), 0.45 + 0.08 * n)

    breath = sweep(noise(p["length"], rng), 900.0, 4000.0, 1.0)
    breath = [s * envelope(i / RATE, 0.08, 0.12) for i, s in enumerate(breath)]

    return place(out, 0.0, breath, 0.25)


def roll(rng):
    p = ROLL
    out = silence(p["length"])
    strokes = []
    at = 0.0
    n = 0

    while at < p["length"] - 0.02:
        share = at / p["length"]
        strokes.append((at, share, n))
        gap = p["first_gap"] + (p["last_gap"] - p["first_gap"]) * share
        at += gap * (1 + rng.uniform(-0.08, 0.08))
        n += 1

    snare = bandpass(noise(0.12, rng), 4200.0, 0.7)

    for at, share, n in strokes:
        level = p["quiet"] + (1 - p["quiet"]) * share**1.6
        level *= 0.9 if n % 2 else 1.0  # two hands, one a little lighter
        stroke = [s * envelope(i / RATE, 0.0008, 0.022) for i, s in enumerate(snare)]
        body = bell(p["body"], 0.08, 0.018, partials=((1.0, 1.0, 1.0),), attack=0.0008)
        place(out, at, stroke, level)
        place(out, at, body, 0.35 * level)

    # A low timpani swell under the roll.
    for i in range(len(out)):
        t = i / RATE
        share = t / p["length"]
        out[i] += 0.22 * share**2 * math.sin(2 * math.pi * p["timpani"] * t)

    return out


def fanfare(rng):
    p = FANFARE
    out = silence(p["length"])

    for freq, at in p["arpeggio"]:
        place(out, at, brass(freq, 0.16, attack=0.02, release=0.05, vibrato_from=1.0), 0.32)

    at = p["chord_at"]
    held = p["length"] - at

    for freq in p["chord"]:
        place(out, at, brass(freq, held, attack=0.04, release=p["release"]), 0.22)

    cymbal = bandpass(noise(held, rng), 7000.0, 0.6)
    cymbal = [s * envelope(i / RATE, 0.002, 0.45) for i, s in enumerate(cymbal)]
    place(out, at, cymbal, 0.35)
    timpani = bell(p["timpani"], held, 0.25, partials=((1.0, 1.0, 1.0), (1.5, 0.3, 0.6)), attack=0.003)

    return place(out, at, timpani, 0.5)


# file name → how it is made; the screen's first, then the phone's.
CUES = {
    "count": count,
    "study": study,
    "question": question,
    "tick": tick,
    "close": close,
    "ranking": ranking,
    "roll": roll,
    "fanfare": fanfare,
    "tap": tap,
    "undo": undo,
    "sent": sent,
    "right": right,
    "wrong": wrong,
}


def write_wav(path, samples):
    peak = max(abs(s) for s in samples) or 1.0
    fade = int(FADE_OUT * RATE)
    frames = bytearray()

    for i, s in enumerate(samples):
        s /= peak
        left = len(samples) - i

        if left <= fade:
            s *= left / fade

        # -6 dBFS; ffmpeg sets the loudness after.
        frames += struct.pack("<h", int(max(-1.0, min(1.0, 0.5 * s)) * 32767))

    with wave.open(str(path), "wb") as file:
        file.setnchannels(1)
        file.setsampwidth(2)
        file.setframerate(RATE)
        file.writeframes(bytes(frames))


def loudness(ffmpeg, path):
    """(LUFS while it sounds, true peak dBFS) of a file, by ffmpeg's ebur128."""
    looped = subprocess.run(
        [
            ffmpeg, "-hide_banner", "-nostats", "-stream_loop", "-1", "-t", str(LOOP_SECONDS),
            "-i", str(path), "-af", "ebur128", "-f", "null", "-",
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    once = subprocess.run(
        [ffmpeg, "-hide_banner", "-nostats", "-i", str(path), "-af", "ebur128=peak=true", "-f", "null", "-"],
        capture_output=True,
        text=True,
        check=True,
    )
    integrated = re.search(r"I:\s+(-?[\d.]+) LUFS", looped.stderr.split("Summary:")[-1])
    peak = re.search(r"Peak:\s+(-?[\d.]+) dBFS", once.stderr.split("Summary:")[-1])

    if integrated is None or peak is None:
        sys.exit(f"Cannot read the loudness of {path}.")

    return float(integrated.group(1)), float(peak.group(1))


def duration(ffprobe, path):
    result = subprocess.run(
        [ffprobe, "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)],
        capture_output=True,
        text=True,
        check=True,
    )

    return float(result.stdout.strip())


def encode(ffmpeg, wav, mp3):
    measured, _ = loudness(ffmpeg, wav)
    gain = LOUDNESS - measured

    # Three times: each pass corrects what the MP3 encoder moved.
    for _ in range(3):
        subprocess.run(
            [
                ffmpeg, "-hide_banner", "-loglevel", "error", "-y", "-i", str(wav),
                "-af", f"volume={gain:.2f}dB",
                "-ac", "1", "-ar", str(RATE), "-c:a", "libmp3lame", "-b:a", "96k",
                "-map_metadata", "-1", "-id3v2_version", "0", "-write_id3v1", "0",
                "-fflags", "+bitexact", "-flags:a", "+bitexact",
                str(mp3),
            ],
            check=True,
        )
        integrated, peak = loudness(ffmpeg, mp3)
        gain += LOUDNESS - integrated

    if peak > MAX_TRUE_PEAK:
        sys.exit(f"{mp3.name}: true peak {peak} dBFS is above {MAX_TRUE_PEAK}; lower LOUDNESS.")

    return integrated, peak


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--out", type=Path, default=OUT, help="folder to write the MP3 files into")
    args = parser.parse_args()
    ffmpeg = shutil.which("ffmpeg") or "/opt/homebrew/bin/ffmpeg"
    ffprobe = shutil.which("ffprobe") or "/opt/homebrew/bin/ffprobe"

    if not Path(ffmpeg).exists() or not Path(ffprobe).exists():
        sys.exit("ffmpeg is needed (brew install ffmpeg).")

    args.out.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory() as temp:
        for name, render in CUES.items():
            samples = render(random.Random(f"{SEED}-{name}"))
            wav = Path(temp) / f"{name}.wav"
            mp3 = args.out / f"{name}.mp3"
            write_wav(wav, samples)
            integrated, peak = encode(ffmpeg, wav, mp3)
            print(
                f"{mp3.name:<13} {len(samples) / RATE * 1000:5.0f} ms rendered, "
                f"{duration(ffprobe, mp3) * 1000:5.0f} ms as MP3, {mp3.stat().st_size / 1024:4.1f} KB, "
                f"{integrated} LUFS while it sounds, true peak {peak} dBFS"
            )


if __name__ == "__main__":
    main()
