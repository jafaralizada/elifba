import type { Avatar, Letter } from './classroom';

/*
 * Page props of the welcome page (`WelcomeController`, frontend.md rule 10).
 * Every fact comes from the content and the code on the server.
 */

/** One round the wall plays (`Play\Support\SampleRounds`). */
export type SampleRound = {
    /** The prompt as the board shows it, cased on the server. */
    prompt: string;
    /** The target as text, shown in the prompt's frame. */
    show: string | null;
    items: { id: string; text: string }[];
    /** The item ids that solve the round. */
    solution: string[];
};

export type Sample = {
    /** `item` is the pack's item style (`balloon`, `card`…), drawn simply. */
    pack: { key: string; title: string; item: string; palette: string[] };
    rounds: SampleRound[];
};

/**
 * The adults' rooms (architecture §12), sent only while the rooms switch is
 * on (frontend.md rule 10, ADR 019 decision 4).
 */
export type WelcomeRooms = {
    /**
     * `RoomFamily` keys in order, each with its `RoomMechanic` keys that have
     * levels; named by `rooms.setup.families.*` and `rooms.mechanics.*`.
     */
    families: { key: string; mechanics: string[] }[];
    /** The difficulties of `content/rooms/levels.json`, named by `rooms.difficulty.*`. */
    difficulties: number[];
    /** `elifba.rooms.max_players`. */
    maxPlayers: number;
    /** `elifba.rooms.rounds`: how many rounds a game may have. */
    rounds: { min: number; max: number };
};

export type WelcomeProps = {
    /** `elifba.source_url`. */
    sourceUrl: string;
    /** Mechanic keys, named by `games.mechanics.*`. */
    mechanics: string[];
    /** Every pack, title in the interface language. */
    packs: { key: string; title: string; palette: string[] }[];
    /** Keys of the packs a child who cannot read yet plays (picture or number levels). */
    picturePacks: string[];
    /** The alphabet in its order. */
    letters: Letter[];
    /** The first avatars of `content/avatars.json`. */
    avatars: Avatar[];
    /** Null when the content has no letter level that `find` plays. */
    sample: Sample | null;
    /** Absent while the rooms switch is off. */
    rooms?: WelcomeRooms;
};
