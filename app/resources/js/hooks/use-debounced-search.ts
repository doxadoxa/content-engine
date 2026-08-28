import { useEffect, useRef, useState } from 'react';

/**
 * A search box that waits until somebody stops typing.
 *
 * Without it, `onChange` issues a request per keystroke — and each
 * administrative request rebuilds the shared props and runs the page's spend
 * aggregates, so typing a ten-character name was ten full page loads.
 *
 * Returns the controlled value and its setter; the callback fires once the
 * typing settles.
 */
export function useDebouncedSearch(
    initial: string,
    onSearch: (value: string) => void,
    delay = 300,
): readonly [string, (value: string) => void] {
    const [value, setValue] = useState(initial);
    const latest = useRef(onSearch);
    const first = useRef(true);

    // In an effect rather than during render. Writing a ref while rendering is
    // a React rule violation and a real hazard under concurrent rendering,
    // where a render can be thrown away after the write.
    useEffect(() => {
        latest.current = onSearch;
    });

    useEffect(() => {
        // Not on mount: the first render already reflects the query string it
        // was rendered from, and searching for it again is a wasted round trip.
        if (first.current) {
            first.current = false;

            return;
        }

        const timer = window.setTimeout(() => latest.current(value), delay);

        return () => window.clearTimeout(timer);
    }, [value, delay]);

    return [value, setValue] as const;
}
