# Avyo homepage film

A 55-second motion piece presenting Avyo, built with [Remotion](https://remotion.dev)
from the marketing page's own palette, type, logo and product previews. The
script and shot list are in [SCRIPT.md](SCRIPT.md).

```
npm install
npm run music     # re-synthesise public/music.wav from music/compose.py
npm run studio    # scrub and preview in the browser
npm run render    # regenerates the music, then out/avyo-intro.mp4 (H.264 + AAC, 1920×1080, 30 fps)
npm run poster    # out/avyo-intro-poster.jpg (the logo frame)
npm run publish   # copy both into app/resources/media, where the homepage imports them
```

Every cue is in `src/timeline.json`, and the animation and the score both read
it. After changing a timing, run `npm run music` before rendering again.

The music is original and synthesised with numpy (`python3`, no other
dependencies), so no licence is involved.

**Chrome.** Remotion downloads Chrome Headless Shell on the first render. If that
fails (behind a proxy Node doesn't honour, or in a sandbox), fetch the same
pinned build by hand:

```
mkdir -p .browser && cd .browser
curl -LO https://storage.googleapis.com/chrome-for-testing-public/134.0.6998.35/mac-arm64/chrome-headless-shell-mac-arm64.zip
unzip chrome-headless-shell-mac-arm64.zip
printf '#!/bin/sh\nexec "$(dirname "$0")/chrome-headless-shell-mac-arm64/chrome-headless-shell" --single-process "$@"\n' > chrome-single.sh
chmod +x chrome-single.sh
```

`remotion.config.ts` picks up `.browser/chrome-single.sh` whenever it exists.
