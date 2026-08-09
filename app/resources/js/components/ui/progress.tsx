'use client';

import { Progress as ProgressPrimitive } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

function Progress({
    className,
    indicatorClassName,
    value,
    ...props
}: React.ComponentProps<typeof ProgressPrimitive.Root> & {
    /**
     * Colour for the filled portion. Added to the stock shadcn component so a
     * caller can tie the fill to meaning — a completed run reading green, a
     * failed one red — instead of every bar being the same primary colour.
     */
    indicatorClassName?: string;
}) {
    return (
        <ProgressPrimitive.Root
            data-slot="progress"
            className={cn(
                // A visible track matters more than it sounds. With the stock
                // `bg-primary/20`, in dark mode the fill is near-white and the
                // track is near-invisible, so a finished bar reads as a plain
                // white line — indistinguishable from a bar that failed to
                // render at all.
                'relative h-2 w-full overflow-hidden rounded-full bg-muted ring-1 ring-border/60 ring-inset',
                className,
            )}
            {...props}
        >
            <ProgressPrimitive.Indicator
                data-slot="progress-indicator"
                className={cn(
                    // Only the fill moves. `transition-all` also animated the
                    // colour swap when a run changed status, which read as the
                    // bar fading rather than the run finishing.
                    'h-full w-full flex-1 rounded-full transition-transform',
                    indicatorClassName ?? 'bg-primary',
                )}
                style={{ transform: `translateX(-${100 - (value || 0)}%)` }}
            />
        </ProgressPrimitive.Root>
    );
}

export { Progress };
