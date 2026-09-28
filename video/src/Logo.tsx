import { useId } from 'react';
import { C } from './theme';

/**
 * The Aperture mark from app/resources/js/components/app-logo-icon.tsx, with its
 * three parts exposed so the film can assemble it: the tile, the terracotta
 * quarter turning out of the corner, and the circular bite. All three at 1 is
 * the shipped mark exactly.
 */
export function Logo({
    size,
    tile = 1,
    quarter = 1,
    bite = 1,
    tone = 'ink',
}: {
    size: number;
    tile?: number;
    quarter?: number;
    bite?: number;
    tone?: 'ink' | 'cream';
}) {
    const mask = useId();
    return (
        <svg viewBox="0 0 32 32" width={size} height={size} style={{ display: 'block', overflow: 'visible' }}>
            <mask id={mask}>
                <rect width="32" height="32" rx="8" fill="#fff" />
                <circle cx="32" cy="32" r={10 * bite} fill="#000" />
            </mask>
            <g mask={`url(#${mask})`}>
                <g transform={`translate(16 16) scale(${tile}) translate(-16 -16)`}>
                    <rect width="32" height="32" rx="8" fill={tone === 'ink' ? C.ink : '#F3ECDD'} />
                </g>
                <g transform={`rotate(${(1 - quarter) * 90} 32 32)`} opacity={Math.min(1, quarter * 4)}>
                    <path d="M11 32A21 21 0 0 1 32 11v13a8 8 0 0 1-8 8Z" fill={C.terracottaMark} />
                </g>
            </g>
        </svg>
    );
}
