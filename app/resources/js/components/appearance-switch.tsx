import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

/**
 * Light, dark, or whatever the machine is set to.
 *
 * **Three, not two.** A plain light/dark flip is the smaller control and it
 * quietly takes something away: `system` is the default every account starts on,
 * and a two-state toggle cannot express it, so the first tap silently opts a
 * person out of following their own machine — including out of it switching at
 * dusk. The settings screen has always offered all three
 * ({@link ./appearance-tabs.tsx}); this is the same choice sized for a corner.
 *
 * Icons alone, because this sits on a sign-in screen where a labelled segmented
 * control would be the second most prominent thing on the page after the form.
 * Each carries its name for anyone not reading pixels.
 */
const OPTIONS: { value: Appearance; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'Match my system' },
];

export default function AppearanceSwitch({
    className,
}: {
    className?: string;
}) {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="group"
            aria-label="Colour scheme"
            className={cn(
                'inline-flex items-center gap-0.5 rounded-full border border-border/80 bg-card/80 p-1 backdrop-blur-sm',
                className,
            )}
        >
            {OPTIONS.map(({ value, icon: Icon, label }) => {
                const active = appearance === value;

                return (
                    <button
                        key={value}
                        type="button"
                        title={label}
                        aria-pressed={active}
                        onClick={() => updateAppearance(value)}
                        className={cn(
                            // Named properties rather than `all`: this element
                            // also carries a backdrop filter from its parent and
                            // a blanket transition would animate that too.
                            'grid size-7 place-items-center rounded-full transition-[color,background-color,scale] duration-150 ease-out',
                            'active:scale-[0.96]',
                            active
                                ? 'bg-secondary text-secondary-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon
                            className="size-3.5"
                            strokeWidth={1.5}
                            aria-hidden
                        />
                        <span className="sr-only">{label}</span>
                    </button>
                );
            })}
        </div>
    );
}
