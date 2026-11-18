import { Camera } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { canScan, RoomScanner } from '@/components/rooms/room-scanner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

const noSubscription = () => () => {};

/**
 * The code field (`RoomCodeField`, docs/frontend.md rule 15 "Games
 * first"): six digits typed in place — `inputMode="numeric"`, tabular
 * figures with wide tracking; the server reads it, spaces and dashes
 * dropped — with a camera button inside its right end where the browser
 * can scan (`RoomScanner`). 52px in «Oyunlar»'s bar, 56px at `/oyun`. It
 * is a field of the caller's form, named `code`.
 */
export function RoomCodeField({
    id,
    invalid,
    entrance,
    large = false,
    autoFocus = false,
}: {
    id: string;
    invalid: boolean;
    /** The entrance's address (`APP_URL/oyun`), for the scanner. */
    entrance: string | null;
    /** 56px, at `/oyun`. */
    large?: boolean;
    autoFocus?: boolean;
}) {
    const { t } = useTranslation();
    const [scanning, setScanning] = useState(false);
    const camera = useSyncExternalStore(noSubscription, canScan, () => false);

    return (
        <div className="relative min-w-0 flex-1">
            <Input
                id={id}
                name="code"
                inputMode="numeric"
                autoComplete="off"
                maxLength={9}
                placeholder={t('rooms.join.code')}
                autoFocus={autoFocus}
                aria-invalid={invalid}
                className={cn(
                    'font-semibold tracking-[0.2em] tabular-nums placeholder:font-normal placeholder:tracking-normal',
                    large
                        ? 'h-14 text-2xl placeholder:text-lg'
                        : 'h-13 text-xl placeholder:text-base',
                    camera && 'pr-13',
                )}
            />
            {camera && (
                <>
                    <Button
                        type="button"
                        variant="ghost"
                        className={cn(
                            'absolute top-1/2 right-0.5 size-12 -translate-y-1/2 rounded-md text-muted-foreground',
                        )}
                        aria-label={t('rooms.join.scan')}
                        onClick={() => setScanning(true)}
                    >
                        <Camera className="size-5" />
                    </Button>
                    <RoomScanner
                        open={scanning}
                        onOpenChange={setScanning}
                        entrance={entrance}
                    />
                </>
            )}
        </div>
    );
}
