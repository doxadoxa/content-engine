import { Config } from '@remotion/cli/config';
import { existsSync } from 'node:fs';

// Remotion fetches Chrome Headless Shell itself on first render. Where it can't
// (a sandbox, a proxy Node doesn't honour), download the same pinned build into
// .browser/ by hand — see README.md — and it is picked up here. The wrapper
// adds --single-process, which a macOS sandbox that denies Chrome its mach-port
// rendezvous server needs in order to launch at all.
const local = '.browser/chrome-single.sh';
if (existsSync(local)) {
    Config.setBrowserExecutable(local);
}

Config.setVideoImageFormat('jpeg');
Config.setJpegQuality(95);
