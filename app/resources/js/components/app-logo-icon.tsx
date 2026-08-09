import type { SVGAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * A compact content-workflow mark: two document layers and a completed line.
 * It inherits the surrounding colour and stays legible at favicon size.
 */
export default function AppLogoIcon({
    className,
    ...props
}: SVGAttributes<SVGSVGElement>) {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            fill="none"
            className={cn(className)}
            {...props}
        >
            <path
                d="M7 3.75h7.5L18 7.25v10.5A2.25 2.25 0 0 1 15.75 20h-7.5A2.25 2.25 0 0 1 6 17.75V6a2.25 2.25 0 0 1 1-1.87"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M14.5 3.75v3.5H18M9.25 11h5.5M9.25 14.5h4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M4 7.5v10.25A3.25 3.25 0 0 0 7.25 21H14"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
            />
        </svg>
    );
}
