import { Composition } from 'remotion';
import { Panel } from './Panel.jsx';

/**
 * What this service can draw.
 *
 * One composition today. Its dimensions are overridden per request — a panel is
 * rendered at whatever crop the channel shows, and the numbers below are only
 * the defaults a Remotion composition is required to declare.
 *
 * `durationInFrames` is 1 because these are stills. It is the seam video will
 * arrive through: the same components, the same props, more frames.
 */
export const RemotionRoot = () => (
    <>
        <Composition
            id="panel"
            component={Panel}
            durationInFrames={1}
            fps={30}
            width={1080}
            height={1350}
            defaultProps={{
                heading: '',
                body: '',
                index: 1,
                total: 1,
                colour: '#1a1a2e',
                ink: '#ffffff',
                position: 'bottom',
                accent: '#ffffff',
            }}
        />
    </>
);
