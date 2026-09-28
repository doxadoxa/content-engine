import { ArrowRight } from 'lucide-react';
import { AbsoluteFill, useCurrentFrame } from 'remotion';
import { Line, Orbs, Pop, progress, Rise, useSpring } from '../anim';
import { Logo } from '../Logo';
import { C, SANS, SERIF } from '../theme';
import T from '../timeline.json';

export function Close() {
    const frame = useCurrentFrame();
    const K = T.close;
    const tile = useSpring(K.lockup, 14);
    const quarter = useSpring(K.lockup + 6, 16);
    const bite = progress(frame, K.lockup + 10, 10);

    return (
        <AbsoluteFill style={{ fontFamily: SANS, color: C.ink, alignItems: 'center', justifyContent: 'center', textAlign: 'center' }}>
            <Orbs disc={[1480, -140, 440]} ring={[-160, 700, 460]} />
            <div style={{ fontFamily: SERIF, fontSize: 128, lineHeight: 1.08, letterSpacing: '-0.035em' }}>
                <Line at={K.line1}>Help your next customer</Line>
                <Line at={K.line2}>
                    <span style={{ fontStyle: 'italic', color: C.terracotta }}>find you.</span>
                </Line>
            </div>
            <Rise at={K.sub} distance={20}>
                <p style={{ marginTop: 40, fontSize: 36, color: C.muted, letterSpacing: '-0.01em' }}>
                    Research, writing and publishing, handled.
                </p>
            </Rise>
            <div style={{ marginTop: 70, display: 'flex', alignItems: 'center', gap: 60 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 22, opacity: Math.min(1, tile * 3) }}>
                    <Logo size={92} tile={tile} quarter={quarter} bite={bite} />
                    <Rise at={K.lockup + 6} distance={0}>
                        <span style={{ fontSize: 76, fontWeight: 600, letterSpacing: '-0.05em' }}>Avyo</span>
                    </Rise>
                </div>
                <Pop at={K.cta} from={0.7}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 14, padding: '26px 46px', borderRadius: 999, background: C.ink, color: '#fff', fontSize: 32, fontWeight: 600 }}>
                        Get started <ArrowRight size={32} />
                    </span>
                </Pop>
            </div>
        </AbsoluteFill>
    );
}
