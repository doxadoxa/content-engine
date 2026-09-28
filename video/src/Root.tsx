import { Composition } from 'remotion';
import { AvyoIntro } from './AvyoIntro';
import './fonts';
import T from './timeline.json';

export const Root = () => (
    <Composition
        id="AvyoIntro"
        component={AvyoIntro}
        durationInFrames={T.duration}
        fps={T.fps}
        width={1920}
        height={1080}
    />
);
