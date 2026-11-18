import { finalTimeline } from '@/components/rooms/final';
import { momentKey, REVEAL_MS, roomMoment } from '@/components/rooms/motion';
import { standingGroups } from '@/components/rooms/standing-groups';
import { URGENT_MS } from '@/components/rooms/time-bar';
import { secondsUntil } from '@/components/rooms/use-server-now';
import type { RoomState } from '@/rooms/contract';
import type { RoomSoundName, RoomSpeaker } from '@/rooms/sound';

/*
 * When a room game sounds (ADR 025 "Sound", docs/frontend.md rule 15
 * "Sound"), the part that is pure: from two consecutive things a page
 * heard — a state and the estimated server clock — the cues to play now.
 * A cue plays when its moment begins and the page saw it begin, once:
 * the state fetched again (`room.changed`, the poll, a timetable fetch)
 * is the same moment and plays nothing, and the moment a page opened on —
 * a reload, a late join, the screen opened mid-game — is silent, as it
 * stands still (`useRoomMotion()`).
 *
 * The screen plays the room's moments: the countdown's seconds, the study
 * and the question opening, a tick in each of the last three seconds of an
 * answer, the round closing (the reveal opening) and its ranking, and in
 * the final a roll ending where the first place opens, then a fanfare. It
 * has no viewer, so nothing it plays depends on anyone's answer. A phone
 * plays the viewer's own: the answer sent (`answered`, the same for every
 * answer) and, from the reveal only, its verdict (`reveal.mine`). Its taps
 * play where they are made (`LiveRound`), not here.
 */

/** What a page heard at one moment: the latest state and the server's clock as estimated. */
export type RoomEar = { state: RoomState | null; now: number | null };

/** A cue: `at` ms from now it starts — or, with `ends`, it ends there (the final's roll). */
export type RoomCue = { sound: RoomSoundName; at: number; ends?: boolean };

/**
 * A moment heard later than this after it began is not announced: a page
 * that was asleep, or shown old props, joins the moment silently. The
 * longest a state may lag without a socket is a poll (3 s).
 */
export const STALE_MS = 4000;

/**
 * What one ear means for a speaker: the moment (the game, the round and
 * its phase) with when it began and its cues; and a beat within it — a
 * countdown's second, one of an answer's last seconds, the viewer's answer
 * sent — with its own cues.
 */
type Heard = {
    moment: string;
    began: number | null;
    cues: RoomCue[];
    beat: string | null;
    beatCues: RoomCue[];
};

const now0 = (sound: RoomSoundName): RoomCue => ({ sound, at: 0 });

function hearScreen(state: RoomState | null, now: number | null): Heard {
    const moment = momentKey(roomMoment(state), 'phase');
    const quiet: Heard = {
        moment,
        began: null,
        cues: [],
        beat: null,
        beatCues: [],
    };

    if (state === null || state.game === null) {
        return quiet;
    }

    if (state.game.phase === 'over') {
        // The final: the places open from the last; the roll ends as the first opens.
        const winnerAt = finalTimeline(
            standingGroups(state.ranking, []),
            'screen',
        ).winnerAt;

        return winnerAt === null
            ? quiet
            : {
                  ...quiet,
                  cues: [
                      { sound: 'roll', at: winnerAt, ends: true },
                      { sound: 'fanfare', at: winnerAt },
                  ],
              };
    }

    const round = state.round;

    if (round === null) {
        return quiet;
    }

    switch (round.phase) {
        case 'countdown': {
            const left = secondsUntil(round.opens_ms, now);
            const cues = left >= 1 ? [now0('count')] : [];

            return {
                ...quiet,
                cues,
                beat: left >= 1 ? `second-${left}` : null,
                beatCues: cues,
            };
        }
        case 'study':
            return { ...quiet, began: round.opens_ms, cues: [now0('study')] };
        case 'answer': {
            const left = secondsUntil(round.bell_ms, now);
            const hurry =
                now !== null && left > 0 && round.bell_ms - now <= URGENT_MS;

            return {
                ...quiet,
                began: round.answer_from_ms,
                cues: [now0('question')],
                beat: hurry ? `last-${left}` : null,
                beatCues: hurry ? [now0('tick')] : [],
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
 * for the first thing it heard) and what it hears now: a moment's cues
 * when a new moment began recently, a beat's when a new beat came within
 * the same moment, and nothing when nothing began.
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

        return late ? [] : heard.cues;
    }

    return heard.beat !== null && heard.beat !== before.beat
        ? heard.beatCues
        : [];
}

/** Whether two ears are one moment for `speaker`: a cue scheduled for a moment is dropped when it ends. */
export function sameMoment(
    previous: RoomEar,
    current: RoomEar,
    speaker: RoomSpeaker,
): boolean {
    return hear(previous, speaker).moment === hear(current, speaker).moment;
}
