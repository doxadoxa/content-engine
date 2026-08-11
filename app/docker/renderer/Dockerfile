# The renderer: a browser, a React bundle and nothing else.
#
# Its own image rather than another mode of the app image, because the two share
# no runtime: this one is Node and Chromium, that one is PHP and FrankenPHP, and
# folding them together would put a few hundred megabytes of browser into every
# queue worker that will never draw anything.
#
# **glibc, and not Alpine.** This was tried on node:22-alpine and cannot work.
# Remotion's compositor is a Rust binary, and the package npm resolves for musl
# still asks for /lib/ld-linux-aarch64.so.1 — the glibc loader. Alpine has
# /lib/ld-musl-aarch64.so.1, and a missing interpreter surfaces as ENOENT on a
# file that plainly exists and is executable, which is the least helpful error
# in Unix. Adding gcompat gets past the loader and then dies inside the binary
# with "libunwind: unsupported .eh_frame_hdr version". Do not spend the hour
# again: the base has to be glibc.
#
# **This file has not been built on the machine it was written on.** Docker Hub
# is unreachable from that daemon — see product/social-media-studio-debt.md,
# item 1 — so the image running there was built from a different base and tagged
# by hand. Everything below the FROM line has been exercised live; the FROM line
# and the library list under it have not. Expect the first real build to need a
# small correction here, and treat a missing shared object at first render as
# this list being one package short rather than as a Remotion problem.
FROM node:22-bookworm-slim

# What Chromium needs to start at all. Remotion downloads the browser itself;
# these are the system libraries it links against, and without them the failure
# is a shared-object error at first render rather than at build.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates fonts-liberation libasound2 libatk-bridge2.0-0 libatk1.0-0 \
        libc6 libcairo2 libcups2 libdbus-1-3 libdrm2 libexpat1 libgbm1 libglib2.0-0 \
        libnspr4 libnss3 libpango-1.0-0 libx11-6 libxcb1 libxcomposite1 libxdamage1 \
        libxext6 libxfixes3 libxkbcommon0 libxrandr2 xdg-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /renderer

COPY docker/renderer/package.json ./
RUN npm install --omit=dev --no-audit --no-fund

# Fetched at build so the first render is fast and so a machine with no network
# can still start the service.
RUN npx remotion browser ensure

# The brand's own typeface, taken from the app rather than duplicated into this
# directory: a browser loads WOFF2 natively, so the files the site already ships
# are the files the panels are drawn with. Two copies would drift the first time
# somebody changed one. `public/` is what the bundler serves at the site root.
COPY resources/fonts ./public/fonts

COPY docker/renderer/src ./src

EXPOSE 3020

CMD ["node", "src/server.mjs"]
