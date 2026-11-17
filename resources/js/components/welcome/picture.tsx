import { cn } from '@/lib/utils';

type PictureProps = {
    src: string;
    width: number;
    height: number;
    alt: string;
    /** Which part stays in the 16:9 frame. */
    position: 'object-top' | 'object-center' | 'object-bottom';
};

/**
 * A screenshot of the app in a 16:9 frame on the welcome page, loaded when
 * it comes near (docs/frontend.md rule 10).
 */
export function Picture({ src, width, height, alt, position }: PictureProps) {
    return (
        <img
            src={src}
            width={width}
            height={height}
            alt={alt}
            loading="lazy"
            decoding="async"
            className={cn(
                'aspect-video w-full rounded-xl bg-muted object-cover ring-1 ring-black/10 dark:ring-white/15',
                position,
            )}
        />
    );
}
