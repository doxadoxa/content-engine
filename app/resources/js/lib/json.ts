/**
 * A POST that expects JSON back, for the two onboarding calls that are not
 * page visits.
 *
 * Inertia's router is for navigation: it replaces the page with whatever comes
 * back. Reading a site and saving a wizard step both leave the operator exactly
 * where they were, so they go over fetch instead — and then have to carry the
 * CSRF token themselves, which is what the cookie read below is for.
 */
export async function postJson<T>(
    url: string,
    body: unknown,
): Promise<{ ok: true; data: T } | { ok: false; message: string }> {
    return sendJson<T>('POST', url, body);
}

/**
 * The same, for an edit to something that already exists.
 *
 * Separate from {@see postJson} only in the verb, and worth having rather than
 * a `method` argument on the caller's side: the two read differently at the
 * call site, and a screen that PATCHes where it meant to POST creates a second
 * row instead of changing one.
 */
export async function patchJson<T>(
    url: string,
    body: unknown,
): Promise<{ ok: true; data: T } | { ok: false; message: string }> {
    return sendJson<T>('PATCH', url, body);
}

async function sendJson<T>(
    method: 'POST' | 'PATCH',
    url: string,
    body: unknown,
): Promise<{ ok: true; data: T } | { ok: false; message: string }> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
    } catch {
        // A dropped connection, not a rejected request. The distinction matters
        // to whoever is reading the message: one is retried, the other fixed.
        return { ok: false, message: 'The request did not reach the server.' };
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        return { ok: false, message: messageIn(payload) };
    }

    return { ok: true, data: payload as T };
}

/**
 * Laravel signs the cookie value and URL-encodes it; decodeURIComponent is what
 * turns it back into the token the middleware compares against.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function messageIn(payload: unknown): string {
    if (payload && typeof payload === 'object') {
        const body = payload as { message?: unknown; errors?: unknown };

        if (body.errors && typeof body.errors === 'object') {
            const first = Object.values(
                body.errors as Record<string, unknown>,
            )[0];

            if (Array.isArray(first) && typeof first[0] === 'string') {
                return first[0];
            }
        }

        if (typeof body.message === 'string' && body.message !== '') {
            return body.message;
        }
    }

    return 'Something went wrong.';
}
