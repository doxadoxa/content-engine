import type { CSSProperties, ReactNode } from 'react';
import {
    AbsoluteFill,
    Easing,
    interpolate,
    spring,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import { C } from './theme';
import T from './timeline.json';

export const easeOut = Easing.bezier(0.16, 1, 0.3, 1);
export const easeInOut = Easing.bezier(0.65, 0, 0.35, 1);

const clamp = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } as const;

/** 0 → 1 over `duration` frames from `at`, eased out. */
export function progress(frame: number, at: number, duration = 18, easing = easeOut) {
    return interpolate(frame, [at, at + duration], [0, 1], { ...clamp, easing });
}

export function useSpring(at: number, damping = 200, durationInFrames?: number) {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    return spring({ frame: frame - at, fps, config: { damping }, durationInFrames });
}

/** Fade and lift into place. */
export function Rise({
    at,
    children,
    distance = 36,
    duration = 20,
    style,
}: {
    at: number;
    children: ReactNode;
    distance?: number;
    duration?: number;
    style?: CSSProperties;
}) {
    const p = progress(useCurrentFrame(), at, duration);
    return (
        <div style={{ opacity: p, transform: `translateY(${(1 - p) * distance}px)`, ...style }}>
            {children}
        </div>
    );
}

/** A line of type sliding up out of its own mask: the kinetic-type workhorse. */
export function Line({
    at,
    children,
    duration = 22,
    style,
}: {
    at: number;
    children: ReactNode;
    duration?: number;
    style?: CSSProperties;
}) {
    const p = progress(useCurrentFrame(), at, duration);
    return (
        <div style={{ overflow: 'hidden', paddingBottom: '0.12em', marginBottom: '-0.12em', ...style }}>
            <div style={{ transform: `translateY(${(1 - p) * 110}%)`, opacity: Math.min(1, p * 3) }}>
                {children}
            </div>
        </div>
    );
}

/** Springy scale-in for UI elements that land on a beat. */
export function Pop({
    at,
    children,
    style,
    from = 0.6,
}: {
    at: number;
    children: ReactNode;
    style?: CSSProperties;
    from?: number;
}) {
    const s = useSpring(at, 13);
    const o = progress(useCurrentFrame(), at, 6);
    return (
        <div style={{ opacity: o, transform: `scale(${from + (1 - from) * s})`, ...style }}>
            {children}
        </div>
    );
}

export function typed(text: string, frame: number, start: number, framesPerChar: number) {
    const n = Math.max(0, Math.min(text.length, Math.floor((frame - start) / framesPerChar) + 1));
    return frame < start ? '' : text.slice(0, n);
}

export function Caret({ height, color = C.ink, visible = true }: { height: number; color?: string; visible?: boolean }) {
    const frame = useCurrentFrame();
    const on = visible && Math.floor(frame / 15) % 2 === 0;
    return (
        <span
            style={{
                display: 'inline-block',
                width: 3,
                height,
                marginLeft: 4,
                verticalAlign: 'middle',
                background: color,
                opacity: on ? 1 : 0,
            }}
        />
    );
}

type SceneName = keyof typeof T.scenes;

/** How far ahead of its downbeat a sheet starts moving, so it lands on the beat. */
export const LEAD = 14;

/**
 * Mounts a scene for its slice of the timeline, in absolute frames.
 *
 * `sheet` scenes slide up over the previous one like a card being dealt and land
 * on their downbeat; whatever sits under them sinks back as they arrive.
 */
export function Scene({
    name,
    background,
    sheet = true,
    sinks = true,
    hold = 6,
    children,
}: {
    name: SceneName;
    background: string;
    sheet?: boolean;
    sinks?: boolean;
    /** Frames to stay mounted past the scene's end, under whatever covers it. */
    hold?: number;
    children: ReactNode;
}) {
    const frame = useCurrentFrame();
    const [start, end] = T.scenes[name];
    const from = sheet ? start - LEAD : start;
    const until = end === T.duration ? end : end + hold;
    if (frame < from || frame >= until) return null;

    const enter = sheet ? progress(frame, start - LEAD, LEAD + 6) : 1;
    const exit = sinks && end !== T.duration ? progress(frame, end - LEAD, LEAD + 6, easeInOut) : 0;

    return (
        <AbsoluteFill
            style={{
                background,
                overflow: 'hidden',
                borderRadius: (1 - enter) * 72,
                transform: `translateY(${(1 - enter) * 1100 - exit * 160}px)`,
                boxShadow: sheet && enter < 1 ? '0 -40px 80px -30px rgba(23,53,47,.35)' : undefined,
            }}
        >
            {children}
            {exit > 0 && <AbsoluteFill style={{ background: C.ink, opacity: 0.35 * exit }} />}
        </AbsoluteFill>
    );
}

/** The marketing page's decorative gold disc and terracotta ring, drifting. */
export function Orbs({
    disc,
    ring,
    discColor = 'rgba(232,186,100,.35)',
    ringColor = 'rgba(200,91,67,.12)',
}: {
    disc?: [number, number, number];
    ring?: [number, number, number];
    discColor?: string;
    ringColor?: string;
}) {
    const frame = useCurrentFrame();
    const dx = Math.sin(frame / 60) * 14;
    const dy = Math.cos(frame / 75) * 12;
    return (
        <>
            {disc && (
                <div
                    style={{
                        position: 'absolute',
                        left: disc[0] + dx,
                        top: disc[1] + dy,
                        width: disc[2],
                        height: disc[2],
                        borderRadius: '50%',
                        background: discColor,
                    }}
                />
            )}
            {ring && (
                <div
                    style={{
                        position: 'absolute',
                        left: ring[0] - dx,
                        top: ring[1] - dy,
                        width: ring[2],
                        height: ring[2],
                        borderRadius: '50%',
                        border: `${ring[2] * 0.14}px solid ${ringColor}`,
                    }}
                />
            )}
        </>
    );
}
