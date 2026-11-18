import { useState } from 'react';
import { SwitchRow } from '@/components/rooms/room-tools';
import { useTranslation } from '@/hooks/use-translation';
import { update as setTaunts } from '@/routes/rooms/taunts';
import { roomRequest } from '@/rooms/http';

/** The host's switch as a page shows and changes it (`useTauntsSwitch()`). */
export type TauntsSwitch = {
    /** The state's switch, or the tap not yet in the state. */
    on: boolean;
    busy: boolean;
    failed: boolean;
    toggle: () => void;
};

/**
 * The host's switch «Atmacalar» (docs/frontend.md rule 15 "Taunts", ADR
 * 029 decision 12): a tap sends `rooms.taunts.update` `{on}` at once and
 * shows the new value until the state's switch moves — the state then
 * says it, on this device and every other, as `useRoomChoice()` does for
 * the choice. A refusal or a failure leaves the state's value standing.
 */
export function useTauntsSwitch({
    roomId,
    on,
    onChanged,
}: {
    roomId: string;
    /** The room's switch, from the state. */
    on: boolean;
    /** After a write: the page asks for the state now rather than wait for the signal. */
    onChanged: () => void;
}): TauntsSwitch {
    const [tap, setTap] = useState<{ on: boolean; over: boolean } | null>(null);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const shown = tap !== null && tap.over === on ? tap.on : on;

    const toggle = () => {
        if (busy) {
            return;
        }

        setTap({ on: !shown, over: on });
        setBusy(true);
        setFailed(false);
        void roomRequest('PUT', setTaunts.url(roomId), { on: !shown })
            .then((reply) => {
                if (reply.status !== 204) {
                    setFailed(true);
                    setTap(null);
                }
            })
            .catch(() => {
                setFailed(true);
                setTap(null);
            })
            .finally(() => {
                setBusy(false);
                onChanged();
            });
    };

    return { on: shown, busy, failed, toggle };
}

/** «Atmacalar» with its line, as one switch row of «Otağın idarəsi» or «Oyun ayarları». */
export function TauntsSwitchRow({
    taunts,
    disabled = false,
    big = false,
}: {
    taunts: TauntsSwitch;
    disabled?: boolean;
    big?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <SwitchRow
            label={t('rooms.taunts.switch')}
            line={t('rooms.taunts.switch_line')}
            on={taunts.on}
            busy={taunts.busy}
            disabled={disabled || taunts.busy}
            onToggle={taunts.toggle}
            big={big}
        />
    );
}
