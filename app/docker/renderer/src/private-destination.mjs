/**
 * Destinations a page is not allowed to reach through this browser.
 *
 * **The pin is not enough, and that is the hole this closes.**
 * `--host-resolver-rules` maps the *one* hostname the caller validated; every
 * other name on the page resolves normally. So an attacker-controlled site
 * could put `<img src="http://app/dashboard">` or an iframe of `postgres` in
 * its markup, this container would fetch it on the compose network, and
 * whatever came back would be rendered into the screenshot we hand to the
 * operator. The permitted apex-to-`www` redirect had the same gap: the second
 * hostname was never pinned.
 *
 * **A denylist of private destinations rather than an allowlist of one host,**
 * and the choice is deliberate. Refusing everything except the pinned name
 * would also refuse the CDN a site keeps its stylesheet on — and the stylesheet
 * is precisely what the inspection reads to learn a brand's colours, so the
 * strict rule would quietly break the feature it is guarding. Public addresses
 * are not the threat here: this browser carries no credentials and the page is
 * one anybody can already load. What it must not reach is *inside*.
 *
 * Unparseable is refused. A URL this cannot read is one it cannot vouch for.
 */
const PRIVATE_SUFFIX = /^(localhost|.+\.(localhost|local|internal|home\.arpa))$/i;

export const isPrivateDestination = (raw) => {
    let parsed;

    try {
        parsed = new URL(raw);
    } catch {
        return true;
    }

    // `data:`, `blob:` and `about:` never leave the process, so there is
    // nothing to reach and nothing to refuse.
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return false;
    }

    const host = parsed.hostname.replace(/^\[|\]$/g, '').toLowerCase();

    // One label and no colons is a container name — `app`, `postgres`,
    // `horizon`, `renderer`. Nothing on the public internet resolves without a
    // dot, which is what makes this cheap and safe.
    if (!host.includes('.') && !host.includes(':')) {
        return true;
    }

    if (PRIVATE_SUFFIX.test(host)) {
        return true;
    }

    const v4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(host);

    if (v4) {
        const a = Number(v4[1]);
        const b = Number(v4[2]);

        return a === 0
            || a === 127
            || a === 10
            || (a === 169 && b === 254)
            || (a === 172 && b >= 16 && b <= 31)
            || (a === 192 && b === 168);
    }

    return host === '::1'
        || host.startsWith('fc')
        || host.startsWith('fd')
        || host.startsWith('fe80');
};
