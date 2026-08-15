import { createServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { bundle } from '@remotion/bundler';
import { openBrowser, renderStill, selectComposition } from '@remotion/renderer';

/**
 * The renderer, as a service the engine calls rather than a binary it shells to.
 *
 * **Bundled once at boot, not once per render.** Bundling is the expensive half
 * of Remotion and it does not depend on the props — doing it per request would
 * put ten seconds in front of a picture that takes one to draw, and a carousel
 * is up to ten pictures. The cost is that a template change needs a restart,
 * which is the right trade for a container that is rebuilt when its code
 * changes anyway.
 *
 * **It answers with the bytes.** The alternative was a shared volume with the
 * app, which would work locally and stop working the moment either side moves
 * to its own host. An image is a few hundred kilobytes; HTTP is the boring
 * option and the one that survives the move.
 *
 * Nothing here is reachable from outside the compose network. It has no
 * authentication for that reason, and that reason has to stay true: publishing
 * this port is publishing an unauthenticated browser that renders whatever HTML
 * it is handed.
 */
const here = path.dirname(fileURLToPath(import.meta.url));
const PORT = Number(process.env.RENDERER_PORT ?? 3020);

/**
 * The distribution's Chromium, not the one Remotion would fetch.
 *
 * This image is Alpine, so its libc is musl; the Chrome Headless Shell Remotion
 * downloads is linked against glibc and fails at the first render rather than
 * at install. Undefined falls back to Remotion's own, which is what a
 * glibc-based image would want.
 */
const BROWSER = process.env.REMOTION_BROWSER || undefined;

console.log('[renderer] bundling…');
const serveUrl = await bundle({
    entryPoint: path.join(here, 'index.jsx'),
    // Where the brand's typeface lives. The bundler serves this directory at
    // the site root, which is what lets the panel @font-face a WOFF2 the app
    // already ships instead of carrying a converted copy.
    publicDir: path.join(here, '..', 'public'),
    onProgress: () => undefined,
});
console.log('[renderer] ready');

const read = (request) =>
    new Promise((resolve, reject) => {
        const chunks = [];
        request.on('data', (chunk) => chunks.push(chunk));
        request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        request.on('error', reject);
    });

/**
 * A photograph of somebody else's website.
 *
 * The engine's picture of a brand has always been read rather than seen: site
 * analysis parses text out of the HTML, so "what does this brand look like" was
 * answered by a model guessing from the copy. This is the eye it never had, and
 * it exists here because this container is the only one with a browser in it.
 *
 * **The address is pinned, not resolved.** This process sits inside the compose
 * network where postgres, redis and the app are all reachable by name, so a
 * request that let Chromium do its own DNS would be a hole straight into them —
 * a host that resolves publicly when the app validates it and to 127.0.0.1 a
 * second later is the whole of a rebinding attack. The caller has already
 * resolved and vetted every address; `--host-resolver-rules` makes Chromium use
 * those and nothing else, which is the browser's equivalent of the
 * `CURLOPT_RESOLVE` pin `ValidatedPublicUrl` applies to every other outbound
 * fetch. A request with no pin is refused rather than resolved.
 *
 * **One browser, opened per shot and closed.** A long-lived instance would be
 * faster and would also accumulate the cookies, storage and service workers of
 * every site this ever visits, which is a lot of somebody else's state to keep
 * in a container that also renders their brand's panels.
 */
const screenshot = async ({ url, host, addresses, width, height, timeout }) => {
    if (!/^https?:\/\//i.test(url)) {
        throw new Error('Only HTTP and HTTPS addresses can be captured.');
    }

    if (!host || !Array.isArray(addresses) || addresses.length === 0) {
        throw new Error('A screenshot needs the caller\'s validated addresses to pin.');
    }

    // Bracketed, because every address this deployment resolves is IPv6 and an
    // unbracketed one silently parses as a host with a port. A malformed rule
    // is not rejected by Chromium — it is ignored, and an ignored pin is a
    // browser doing its own DNS inside the compose network.
    const rules = addresses
        .map((address) => `MAP ${host} ${address.includes(':') ? `[${address}]` : address}`)
        .join(',');

    const browser = await openBrowser('chrome', {
        browserExecutable: BROWSER,
        chromiumOptions: {
            headless: true,
            // EXCLUDE keeps the pin off the loopback the devtools protocol
            // itself speaks over, which the rule would otherwise capture.
            chromiumFlags: [`--host-resolver-rules=${rules},EXCLUDE localhost`],
        },
    });

    try {
        const page = await browser.newPage({ logLevel: 'error' });
        await page.setViewport({ width, height, deviceScaleFactor: 1 });
        await page.goto({ url, timeout });

        // The pin covers one hostname; a redirect names another, and Chromium
        // would resolve that one normally — straight into the compose network,
        // where an unauthenticated internal dashboard would be photographed and
        // handed back. The caller validated the address it asked for, so the
        // address that answered has to be the same one.
        //
        // With one exception, because refusing it would refuse most of the web:
        // apex-to-www and www-to-apex are the ordinary shape of a redirect, and
        // both names are the same site by any reading. Nothing else is allowed
        // — a genuine cross-domain redirect fails here, and the honest fix is
        // for the operator to enter the address the site actually lives at.
        // Every internal name worth reaching from this container (`postgres`,
        // `horizon`, `app`) is a single label and cannot survive this.
        const bare = (name) => name.toLowerCase().replace(/\.$/, '').replace(/^www\./, '');
        const landed = new URL(await page.url());

        if (bare(landed.hostname) !== bare(host)) {
            throw new Error(
                `The site redirected to ${landed.hostname}, which is not the address that was checked.`,
            );
        }

        // A beat for webfonts and hero imagery. Not network-idle: analytics and
        // chat widgets poll forever on plenty of real sites, so waiting for
        // silence never fires on exactly the busy, well-built sites this is
        // most useful on.
        await new Promise((resolve) => setTimeout(resolve, 1_500));

        // Raw devtools, because Remotion's page has no screenshot of its own —
        // its internal one is not exported. The shape matches what it uses:
        // the answer is wrapped in `value`, and the clip needs its own scale.
        const { value } = await page._client().send('Page.captureScreenshot', {
            format: 'png',
            clip: { x: 0, y: 0, width, height, scale: 1 },
            captureBeyondViewport: true,
            optimizeForSpeed: true,
            fromSurface: true,
        });

        return Buffer.from(value.data, 'base64');
    } finally {
        await browser.close({ silent: true });
    }
};

createServer(async (request, response) => {
    if (request.url === '/up') {
        response.writeHead(200).end('ok');

        return;
    }

    if (request.method === 'POST' && request.url === '/screenshot') {
        try {
            const body = JSON.parse(await read(request));

            const buffer = await screenshot({
                url: String(body.url ?? ''),
                host: String(body.host ?? ''),
                addresses: body.addresses ?? [],
                width: Number(body.width ?? 1280),
                height: Number(body.height ?? 900),
                timeout: Number(body.timeout ?? 20_000),
            });

            response.writeHead(200, { 'Content-Type': 'image/png' }).end(buffer);
        } catch (error) {
            console.error('[renderer:screenshot]', error);

            response
                .writeHead(502, { 'Content-Type': 'application/json' })
                .end(JSON.stringify({ message: String(error?.message ?? error) }));
        }

        return;
    }

    if (request.method !== 'POST' || request.url !== '/render') {
        response.writeHead(404).end('not found');

        return;
    }

    try {
        const { composition = 'panel', props = {}, width, height } = JSON.parse(
            await read(request),
        );

        const selected = await selectComposition({
            serveUrl,
            id: composition,
            inputProps: props,
            browserExecutable: BROWSER,
        });

        const { buffer } = await renderStill({
            composition: {
                ...selected,
                // The channel's crop, not the composition's default. A panel
                // that ignored this would be the one asset in a post that the
                // feed letterboxes.
                width: Number(width ?? selected.width),
                height: Number(height ?? selected.height),
            },
            serveUrl,
            inputProps: props,
            imageFormat: 'png',
            output: null,
            browserExecutable: BROWSER,
        });

        response.writeHead(200, { 'Content-Type': 'image/png' }).end(buffer);
    } catch (error) {
        // The message goes back because the only caller is the engine on a
        // private network, and a render that failed for a reason nobody can
        // read is a render nobody can fix.
        console.error('[renderer]', error);

        response
            .writeHead(500, { 'Content-Type': 'application/json' })
            .end(JSON.stringify({ message: String(error?.message ?? error) }));
    }
}).listen(PORT, '0.0.0.0', () => console.log(`[renderer] listening on ${PORT}`));
