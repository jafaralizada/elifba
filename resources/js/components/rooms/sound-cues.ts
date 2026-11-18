import { finalTier, finalTimeline } from '@/components/rooms/final';
import { momentKey, REVEAL_MS, roomMoment } from '@/components/rooms/motion';
import { standingGroups } from '@/components/rooms/standing-groups';
import type { RoomState } from '@/rooms/contract';
import type { RoomSoundName, RoomSpeaker } from '@/rooms/sound';

/*
 * When a room game sounds (ADR 025 "Sound", ADR 028, docs/frontend.md rule
 * 15 "Sound"), the part that is pure: from two consecutive things a page
 * heard — a state and the estimated server clock — the cues to play now.
 * A cue plays when its moment begins and the page saw it begin, once:
 * the state fetched again (`room.changed`, the poll, a timetable fetch)
 * is the same moment and plays nothing, and the moment a page opened on —
 * a reload, a late join, the screen opened mid-game — is silent, as it
 * stands still (`useRoomMotion()`).
 *
 * The screen plays the room's moments: the countdown, one file that ends
 * at `opens_ms`; `go` as round 0 opens; the study and the question of a
 * later round; the clock, one file that ends at `bell_ms`, in an answer's
 * last `CLOCK_MS`; the round closing (the reveal opening) and its ranking;
 * in the final a hit as the third and the second places open, a roll
 * ending where the first opens, then a fanfare; and between games a
 * `join` when a player appears. It has no viewer, so nothing it plays
 * depends on anyone's answer. A phone plays the viewer's own: the answer
 * sent (`answered`, the same for every answer) and, from the reveal only,
 * its verdict (`reveal.mine`). Its taps play where they are made
 * (`LiveRound`), not here.
 */

/** What a page heard at one moment: the latest state and the server's clock as estimated. */
export type RoomEar = { state: RoomState | null; now: number | null };

/** A cue: `at` ms from now it starts — or, with `ends`, it ends there (the countdown, the clock, the roll). */
export type RoomCue = { sound: RoomSoundName; at: number; ends?: boolean };

/**
 * A moment heard later than this after it began is not announced: a page
 * that was asleep, or shown old props, joins the moment silently. The
 * longest a state may lag without a socket is a poll (3 s).
 */
export const STALE_MS = 4000;

/**
 * The clock runs the last 5 s of every answer (ADR 028 decision 3): one
 * file that ends at `bell_ms`. Its last 3 s are the most urgent, as the
 * time bar turns red (`URGENT_MS` stays the bar's).
 */
export const CLOCK_MS = 5000;

/**
 * The clock is heard this much ahead of its start, more than the page's
 * listening step (200 ms), so the player schedules it to the sample.
 */
export const LEAD_MS = 500;

/**
 * What one ear means for a speaker: the moment (the game, the round and
 * its phase) with when it began and its cues; a beat within it — the
 * clock, the viewer's answer sent — with its own cues; and, between games
 * on the screen, the players the state lists.
 */
type Heard = {
    moment: string;
    began: number | null;
    cues: RoomCue[];
    beat: string | null;
    beatCues: RoomCue[];
    players: string[] | null;
};

const now0 = (sound: RoomSoundName): RoomCue => ({ sound, at: 0 });

/** The final's cues: a hit as the third and the second open, the roll ending where the first opens, the fanfare. */
function finalCues(state: RoomState): RoomCue[] {
    const groups = standingGroups(state.ranking, []);
    const timeline = finalTimeline(groups, 'screen');

    if (timeline.winnerAt === null) {
        return [];
    }

    const places = groups
        .map((group, index) => ({
            tier: finalTier(group.place),
            at: timeline.opensAt[index],
        }))
        .filter(({ tier }) => tier === 'second' || tier === 'third')
        .sort((a, b) => a.at - b.at)
        .map(({ at }): RoomCue => ({ sound: 'place', at }));

    return [
        ...places,
        { sound: 'roll', at: timeline.winnerAt, ends: true },
        { sound: 'fanfare', at: timeline.winnerAt },
    ];
}

function hearScreen(state: RoomState | null, now: number | null): Heard {
    const moment = momentKey(roomMoment(state), 'phase');
    const quiet: Heard = {
        moment,
        began: null,
        cues: [],
        beat: null,
        beatCues: [],
        players: null,
    };

    if (state === null) {
        return quiet;
    }

    // Between games: before the first, and while the last one's final stands.
    const players = state.players.map((player) => player.id);

    if (state.game === null) {
        return { ...quiet, players };
    }

    if (state.game.phase === 'over') {
        return { ...quiet, cues: finalCues(state), players };
    }

    const round = state.round;

    if (round === null) {
        return quiet;
    }

    switch (round.phase) {
        case 'countdown':
            return now === null
                ? quiet
                : {
                      ...quiet,
                      cues: [
                          {
                              sound: 'countdown',
                              at: round.opens_ms - now,
                              ends: true,
                          },
                      ],
                  };
        case 'study':
            // Round 0's opening is the game's start.
            return {
                ...quiet,
                began: round.opens_ms,
                cues: [now0(round.index === 0 ? 'go' : 'study')],
            };
        case 'answer': {
            const opening = round.answer_from_ms === round.opens_ms;
            const left = now === null ? null : round.bell_ms - now;
            const clock =
                left !== null && left > 0 && left <= CLOCK_MS + LEAD_MS;

            return {
                ...quiet,
                began: round.answer_from_ms,
                cues: [now0(round.index === 0 && opening ? 'go' : 'question')],
                beat: clock ? 'clock' : null,
                beatCues:
                    clock && left !== null
                        ? [{ sound: 'clock', at: left, ends: true }]
                        : [],
            };
        }
        case 'reveal':
            return {
                ...quiet,
                began: round.closes_ms,
                cues: [
                    now0('close'),
                    { sound: 'ranking', at: REVEAL_MS.ranking },
                ],
            };
    }
}

function hearPhone(state: RoomState | null): Heard {
    const moment = momentKey(roomMoment(state), 'phase');
    const quiet: Heard = {
        moment,
        began: null,
        cues: [],
        beat: null,
        beatCues: [],
        players: null,
    };
    const round = state?.game?.phase === 'live' ? state.round : null;

    if (state === null || round === null) {
        return quiet;
    }

    if (round.phase === 'answer') {
        // The answer is in: one sound for every answer, from `answered` only.
        return {
            ...quiet,
            began: round.answer_from_ms,
            beat: round.answered ? 'sent' : null,
            beatCues: [now0('sent')],
        };
    }

    if (round.phase === 'reveal' && state.me.player_id !== null) {
        // From the reveal only: the viewer's own verdict; no answer, no sound.
        const mine = round.reveal.mine;

        return {
            ...quiet,
            began: round.closes_ms,
            cues: mine === null ? [] : [now0(mine.correct ? 'right' : 'wrong')],
        };
    }

    return quiet;
}

/** What `ear` means for `speaker`. */
function hear(ear: RoomEar, speaker: RoomSpeaker): Heard {
    return speaker === 'screen'
        ? hearScreen(ear.state, ear.now)
        : hearPhone(ear.state);
}

/**
 * The cues to play now, from what the page heard before (`previous`, null
 * for the first thing it heard) and what it hears now: a moment's cues —
 * with the beat it began on, if any — when a new moment began recently; a
 * beat's when a new beat came within the same moment; `join` when a player
 * appeared between games; and nothing when nothing began.
 */
export function roomCues(
    previous: RoomEar | null,
    current: RoomEar,
    speaker: RoomSpeaker,
): RoomCue[] {
    if (previous === null) {
        return [];
    }

    const before = hear(previous, speaker);
    const heard = hear(current, speaker);

    if (heard.moment !== before.moment) {
        const late =
            heard.began !== null &&
            current.now !== null &&
            current.now - heard.began > STALE_MS;

        return late
            ? []
            : [...heard.cues, ...(heard.beat === null ? [] : heard.beatCues)];
    }

    const cues =
        heard.beat !== null && heard.beat !== before.beat
            ? [...heard.beatCues]
            : [];

    if (heard.players !== null && before.players !== null) {
        const known = new Set(before.players);

        if (heard.players.some((id) => !known.has(id))) {
            cues.push(now0('join'));
        }
    }

    return cues;
}

/** Whether two ears are one moment for `speaker`: every cue of a moment stops when it ends. */
export function sameMoment(
    previous: RoomEar,
    current: RoomEar,
    speaker: RoomSpeaker,
): boolean {
    return hear(previous, speaker).moment === hear(current, speaker).moment;
}
