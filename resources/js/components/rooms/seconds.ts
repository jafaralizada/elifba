/**
 * Milliseconds as seconds to one decimal, with the interface language's
 * decimal mark (`rooms.decimal_mark`: «,» in Azerbaijani, "." in English):
 * «2,4» for an answer's time, «2,5» or «10» for a level's. Chrome's own
 * number data has no Azerbaijani symbols and would write «2.4», so the
 * mark comes from the translations, not from `Intl`. `always` keeps the
 * tenth of a whole number («3,0»).
 */
export function seconds(ms: number, mark: string, always = false): string {
    const tenths = Math.round(ms / 100);
    const whole = Math.floor(tenths / 10);
    const tenth = tenths % 10;

    return tenth === 0 && !always ? String(whole) : `${whole}${mark}${tenth}`;
}
