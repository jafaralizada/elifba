/**
 * Picks a random student for «Püşk» (docs/architecture.md §4): students who
 * have not yet had a turn in this game come first, and the student already
 * at the board is never picked again while another student exists. Once
 * everyone has had a turn, the whole class is eligible again.
 */
export function pickRandomStudent(
    studentIds: readonly string[],
    currentId: string | null,
    playedIds: ReadonlySet<string> = new Set(),
    random: () => number = Math.random,
): string | null {
    if (studentIds.length === 0) {
        return null;
    }

    if (studentIds.length === 1) {
        return studentIds[0];
    }

    const others = studentIds.filter((id) => id !== currentId);
    const unplayed = others.filter((id) => !playedIds.has(id));
    const pool = unplayed.length > 0 ? unplayed : others;
    const index = Math.min(
        pool.length - 1,
        Math.max(0, Math.floor(random() * pool.length)),
    );

    return pool[index];
}
