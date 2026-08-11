import { CalendarDays, FolderKanban, Globe2, Sparkles } from 'lucide-react';
import { Icon } from 'avyo';

/**
 * Icon takes the lucide component itself as `iconNode`, not an element — it
 * exists so a config object can carry its glyph as data (the sidebar's nav
 * items do exactly this) instead of every call site importing lucide.
 * Returns null when `iconNode` is missing, which is the whole reason a bare
 * `<Icon />` renders nothing.
 */
export function Sizes() {
    return (
        <div className="flex items-end gap-6">
            <Icon iconNode={Sparkles} className="size-4" />
            <Icon iconNode={Sparkles} className="size-6" />
            <Icon iconNode={Sparkles} className="size-8" />
            <Icon iconNode={Sparkles} className="size-10" />
        </div>
    );
}

export function AsNavData() {
    const items = [
        { label: 'Today', icon: CalendarDays },
        { label: 'Projects', icon: FolderKanban },
        { label: 'Visibility', icon: Globe2 },
    ];

    return (
        <ul className="flex max-w-xs flex-col gap-3">
            {items.map(({ label, icon }) => (
                <li key={label} className="flex items-center gap-3 text-sm">
                    <Icon
                        iconNode={icon}
                        className="size-4 text-muted-foreground"
                    />
                    {label}
                </li>
            ))}
        </ul>
    );
}

export function Tones() {
    return (
        <div className="flex items-center gap-6">
            <Icon iconNode={Sparkles} className="size-6 text-foreground" />
            <Icon iconNode={Sparkles} className="size-6 text-muted-foreground" />
            <Icon iconNode={Sparkles} className="size-6 text-chart-1" />
            <Icon iconNode={Sparkles} className="size-6 text-chart-2" />
            <Icon iconNode={Sparkles} className="size-6 text-destructive" />
        </div>
    );
}
