import { useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    value: string[];
    onChange: (value: string[]) => void;
    error?: string;
};

/**
 * The project&rsquo;s RSS whitelist (&sect;4.1).
 *
 * A textarea, one address per line, and that is the whole design. The duty
 * hours next to it needed seven rows of paired time inputs because a time is
 * awkward to type and easy to get subtly wrong; a URL is neither. Chips would
 * be worse here, not better — feed URLs are long, they wrap badly in a badge,
 * and they arrive by paste rather than by typing.
 *
 * The text is local state and the parent holds the list, so a half-typed line
 * stays where it was put. Blank lines are dropped on the way out, and the
 * server drops them again before validating.
 */
export function FeedUrlsField({ value, onChange, error }: Props) {
    const [text, setText] = useState(value.join('\n'));

    return (
        <div className="grid gap-2">
            <Label htmlFor="feed_urls">News sources to watch</Label>

            <Textarea
                id="feed_urls"
                name="feed_urls_text"
                rows={4}
                spellCheck={false}
                placeholder={
                    'https://example.com/feed.xml\nhttps://example.org/atom'
                }
                aria-describedby="feed_urls-help"
                value={text}
                onChange={(event) => {
                    setText(event.target.value);

                    onChange(
                        event.target.value
                            .split('\n')
                            .map((line) => line.trim())
                            .filter(Boolean),
                    );
                }}
            />

            <p id="feed_urls-help" className="text-xs text-muted-foreground">
                One RSS or Atom address per line, up to twenty. These feed the
                listening run alongside Threads search, and reactive posts are
                capped at one a week with an expiry — so a short list of sources
                worth reacting to beats a long list of everything published.
                Addresses that are not reachable on the public internet are
                refused here rather than failing quietly every hour.
            </p>

            <InputError message={error} />
        </div>
    );
}
