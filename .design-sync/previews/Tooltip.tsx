import { Info } from 'lucide-react';
import {
    Button,
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from 'avyo';

/**
 * Unlike the stock shadcn component, this `Tooltip` does not wrap its own
 * provider — it throws "`Tooltip` must be used within `TooltipProvider`"
 * outside one. Mount `TooltipProvider` once near the app root; every tooltip
 * below it then needs nothing else.
 *
 * Shown open, since hover cannot happen in a preview card.
 */
export function Open() {
    return (
        <TooltipProvider>
            <Tooltip defaultOpen>
                <TooltipTrigger asChild>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="What is this"
                    >
                        <Info />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="right">
                    Runs every six hours
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

/**
 * All four open at once. Generous spacing because each tooltip is positioned
 * against its own trigger and they will collide otherwise.
 */
export function Sides() {
    return (
        <TooltipProvider delayDuration={0}>
            <div className="grid grid-cols-2 gap-x-24 gap-y-16 px-12 py-12">
                {(['top', 'right', 'bottom', 'left'] as const).map((side) => (
                    <Tooltip key={side} defaultOpen>
                        <TooltipTrigger asChild>
                            <Button variant="outline">{side}</Button>
                        </TooltipTrigger>
                        <TooltipContent side={side}>{side}</TooltipContent>
                    </Tooltip>
                ))}
            </div>
        </TooltipProvider>
    );
}
