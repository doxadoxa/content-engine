import { AbsoluteFill, Audio, staticFile } from 'remotion';
import { Scene } from './anim';
import { Close } from './scenes/Close';
import { Meet } from './scenes/Meet';
import { Gap, Question } from './scenes/Opening';
import { Measure, Publish, Research, Write } from './scenes/Steps';
import { C } from './theme';

/** Scenes are layered in timeline order: each one is dealt on top of the last. */
export const AvyoIntro = () => (
    <AbsoluteFill style={{ background: C.cream }}>
        <Scene name="question" background={C.cream} sheet={false} sinks={false}>
            <Question />
        </Scene>
        <Scene name="gap" background={C.cream} sheet={false} sinks={false} hold={30}>
            <Gap />
        </Scene>
        <Meet />
        <Scene name="research" background={C.cream}>
            <Research />
        </Scene>
        <Scene name="write" background={C.warm}>
            <Write />
        </Scene>
        <Scene name="publish" background={C.cream}>
            <Publish />
        </Scene>
        <Scene name="measure" background={C.blueBg}>
            <Measure />
        </Scene>
        <Scene name="close" background={C.sand}>
            <Close />
        </Scene>
        <Audio src={staticFile('music.wav')} />
    </AbsoluteFill>
);
