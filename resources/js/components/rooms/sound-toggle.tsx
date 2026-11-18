import { Volume2, VolumeX } from 'lucide-react';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

/**
 * A device's sound (docs/frontend.md rule 15 "Sound", ADR 025): a toggle
 * (`aria-pressed`: the sound is on) remembered on this device only. On
 * the screen's top bar (`big`) it reads «Səs»; while the browser still
 * waits for a gesture it stands out, filled, as «Səsi aç», and a press
 * turns the room's sound on. On a phone it is a 44px icon in the round's
 * head beside the code chip: the first tap anywhere unlocks a phone, so it
 * only mutes and unmutes. The page's first-gesture unlock leaves this
 * button to itself (`data-room-sound`), so its press means what it shows.
 */
export function SoundToggle({
    sound,
    big = false,
}: {
    sound: RoomSound;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const waiting = big && sound.locked && !sound.muted;
    const on = !sound.muted && !(big && sound.locked);
    const Icon = on ? Volume2 : VolumeX;

    if (!big) {
        return (
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-11 shrink-0 rounded-full"
                aria-pressed={!sound.muted}
                data-room-sound
                aria-label={t('rooms.sound.label')}
                onClick={sound.toggle}
            >
                <Icon className={sound.muted ? 'text-muted-foreground' : ''} />
            </Button>
        );
    }

    return (
        <Button
            type="button"
            variant={waiting ? 'default' : 'outline'}
            className="h-11"
            aria-pressed={on}
            data-room-sound
            onClick={sound.toggle}
        >
            <Icon />
            {t(waiting ? 'rooms.sound.unlock' : 'rooms.sound.label')}
        </Button>
    );
}
