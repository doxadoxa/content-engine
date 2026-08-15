import { Composition } from 'remotion';
import { Panel } from './Panel.jsx';
import {
    Checklist,
    Contrast,
    Cover,
    Cta,
    Statement,
    Stat,
    Step,
} from './Layouts.jsx';

/**
 * What this service can draw.
 *
 * Its dimensions are overridden per request — a panel is rendered at whatever
 * crop the channel shows, and the numbers below are only the defaults a Remotion
 * composition is required to declare.
 *
 * `durationInFrames` is 1 because these are stills. It is the seam video will
 * arrive through: the same components, the same props, more frames.
 *
 * **`panel` stays**, and not for compatibility with drawn assets — those are PNGs
 * on disk and outlive any template. It stays because it is the fallback
 * `App\Enums\SlideLayout` degrades to when a slide names a layout this service
 * does not have, which is exactly what happens for the minutes between deploying
 * a new layout to the engine and restarting this container. Without it that
 * window drops every slide.
 */
const still = { durationInFrames: 1, fps: 30, width: 1080, height: 1350 };

/**
 * Defaults are empty rather than plausible.
 *
 * Remotion requires them and never uses them here: every render arrives with
 * `inputProps`. A default that looked like real copy would be indistinguishable
 * from a slide whose field the engine forgot to send.
 */
const style = {
    colour: '#1a1a2e',
    ink: '#ffffff',
    accent: '#ffffff',
    // Resolved by VisualStyle, not by the templates: what may be written on the
    // accent, and what the accent may be written with. See its MIN_CONTRAST.
    onAccent: '#000000',
    accentType: '#ffffff',
    index: 1,
    total: 1,
};

export const RemotionRoot = () => (
    <>
        <Composition
            id="panel"
            component={Panel}
            {...still}
            defaultProps={{ ...style, heading: '', body: '', position: 'bottom' }}
        />
        <Composition
            id="cover"
            component={Cover}
            {...still}
            defaultProps={{ ...style, heading: '', kicker: '' }}
        />
        <Composition
            id="statement"
            component={Statement}
            {...still}
            defaultProps={{ ...style, heading: '' }}
        />
        <Composition
            id="step"
            component={Step}
            {...still}
            defaultProps={{ ...style, step: '1', heading: '', body: '' }}
        />
        <Composition
            id="stat"
            component={Stat}
            {...still}
            defaultProps={{ ...style, figure: '', caption: '' }}
        />
        <Composition
            id="contrast"
            component={Contrast}
            {...still}
            defaultProps={{
                ...style,
                beforeLabel: '',
                before: '',
                afterLabel: '',
                after: '',
            }}
        />
        <Composition
            id="checklist"
            component={Checklist}
            {...still}
            defaultProps={{ ...style, heading: '', items: [] }}
        />
        <Composition
            id="cta"
            component={Cta}
            {...still}
            defaultProps={{ ...style, heading: '', body: '', action: '' }}
        />
    </>
);
