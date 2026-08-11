import { createServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { bundle } from '@remotion/bundler';
import { renderStill, selectComposition } from '@remotion/renderer';

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

createServer(async (request, response) => {
    if (request.url === '/up') {
        response.writeHead(200).end('ok');

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
