import { Link } from '@inertiajs/react';
import { Check, TriangleAlert } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { show as contentShow } from '@/routes/content';

export type Turn = {
    id: string;
    role: 'user' | 'assistant' | 'tool';
    body: string | null;
    tool_name: string | null;
    tool_result: Record<string, unknown> | null;
};

export type ChatSummary = {
    id: string;
    title: string | null;
    last_message_at: string | null;
};

/**
 * A conversation, rendered.
 *
 * Shared by the chat screen and nothing else today — but it lives here rather
 * than inside that page because the transcript is the product's record of what
 * the engine did, and the moment a second surface shows it, two renderings of
 * one truth is the bug that follows.
 *
 * **Tool turns are shown, not summarised.** The assistant's own sentence about
 * what it did is prose and can be wrong; the row underneath is the engine's
 * receipt, and where it made something it carries the link. That pairing is the
 * whole answer to the question this feature was failing: "I see it working, but
 * where and what?"
 */
export function ChatThread({
    turns,
    pending,
}: {
    turns: Turn[];
    pending: boolean;
}) {
    const bottom = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (turns.length > 0 || pending) {
            bottom.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [turns.length, pending]);

    return (
        <div className="flex flex-col gap-4">
            {turns.map((turn) => (
                <TurnRow key={turn.id} turn={turn} />
            ))}

            {pending && (
                <div
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    <Spinner className="size-3.5" />
                    Thinking…
                </div>
            )}

            <div ref={bottom} />
        </div>
    );
}

function TurnRow({ turn }: { turn: Turn }) {
    if (turn.role === 'user') {
        return (
            <div className="flex justify-end">
                <p className="max-w-[85%] rounded-2xl rounded-br-md bg-muted px-4 py-2.5 text-sm whitespace-pre-wrap">
                    {turn.body}
                </p>
            </div>
        );
    }

    if (turn.role === 'tool') {
        return <ToolRow turn={turn} />;
    }

    return (
        <div className="max-w-[92%] text-sm leading-relaxed">
            <Prose text={turn.body ?? ''} />
        </div>
    );
}

/** What the engine reached for, in the words of the thing it did. */
const TOOL_LABELS: Record<string, string> = {
    read_visibility: 'Checked how you appear in AI answers',
    read_content_state: 'Looked at what you have planned and published',
    read_brand_brief: 'Read the brand brief',
    write_post: 'Started a post',
    write_article: 'Started an article',
    plan_month: 'Started planning the month',
};

function ToolRow({ turn }: { turn: Turn }) {
    const result = turn.tool_result ?? {};
    const failed = result.ok === false;
    const itemId =
        typeof result.content_item_id === 'string'
            ? result.content_item_id
            : null;
    const title = typeof result.title === 'string' ? result.title : null;

    return (
        <div className="flex items-start gap-2 text-xs text-muted-foreground">
            {failed ? (
                <TriangleAlert
                    className="mt-0.5 size-3.5 shrink-0 text-terracotta-deep"
                    aria-hidden="true"
                />
            ) : (
                <Check
                    className="mt-0.5 size-3.5 shrink-0 text-sage"
                    aria-hidden="true"
                />
            )}
            <span className="min-w-0">
                {TOOL_LABELS[turn.tool_name ?? ''] ?? turn.tool_name}
                {title !== null && (
                    <>
                        {' · '}
                        {itemId === null ? (
                            <span className="text-foreground">{title}</span>
                        ) : (
                            <Link
                                href={contentShow(itemId).url}
                                className="text-foreground underline underline-offset-4"
                            >
                                {title}
                            </Link>
                        )}
                    </>
                )}
                {failed && typeof result.error === 'string' && (
                    <span className="text-terracotta-deep">
                        {' — '}
                        {result.error}
                    </span>
                )}
            </span>
        </div>
    );
}

/**
 * The markdown an assistant actually writes, and nothing else.
 *
 * Headings, bold and bullets — the whole of what comes back from a chat model
 * told to keep it short. A markdown library would render the other ninety
 * percent of the spec too, and this surface has no use for tables, footnotes or
 * embedded html; the instructions ask for prose, and this is the safety net for
 * when the model reaches for a heading anyway rather than a reason to accept a
 * dependency and an html sanitiser with it.
 *
 * Grouped line by line rather than block by block. A model writes a heading and
 * its bullets with single newlines between them, so splitting on blank lines
 * puts the two in one block and neither renders — which is how "### By
 * language" reached the screen with its hashes on.
 *
 * Everything is a text node, so there is no path from a model's output into
 * markup.
 */
function Prose({ text }: { text: string }) {
    const nodes: React.ReactNode[] = [];
    let bullets: string[] = [];
    let paragraph: string[] = [];

    const flushBullets = () => {
        if (bullets.length === 0) {
            return;
        }

        nodes.push(
            <ul key={`ul-${nodes.length}`} className="ml-4 list-disc space-y-1">
                {bullets.map((line, index) => (
                    <li key={index}>
                        <Inline text={line} />
                    </li>
                ))}
            </ul>,
        );
        bullets = [];
    };

    const flushParagraph = () => {
        if (paragraph.length === 0) {
            return;
        }

        nodes.push(
            <p key={`p-${nodes.length}`} className="whitespace-pre-wrap">
                <Inline text={paragraph.join('\n')} />
            </p>,
        );
        paragraph = [];
    };

    for (const line of text.trim().split('\n')) {
        const heading = line.match(/^#{1,6}\s+(.*)$/);

        if (heading !== null) {
            flushBullets();
            flushParagraph();
            nodes.push(
                <p key={`h-${nodes.length}`} className="font-semibold">
                    <Inline text={heading[1]} />
                </p>,
            );

            continue;
        }

        const bullet = line.match(/^\s*[-*]\s+(.*)$/);

        if (bullet !== null) {
            flushParagraph();
            bullets.push(bullet[1]);

            continue;
        }

        if (line.trim() === '') {
            flushBullets();
            flushParagraph();

            continue;
        }

        flushBullets();
        paragraph.push(line);
    }

    flushBullets();
    flushParagraph();

    return <div className="flex flex-col gap-2.5">{nodes}</div>;
}

/** `**bold**`, which is the only inline mark worth honouring here. */
function Inline({ text }: { text: string }) {
    return (
        <>
            {text.split(/(\*\*[^*]+\*\*)/g).map((part, index) =>
                part.startsWith('**') && part.endsWith('**') ? (
                    <strong key={index} className="font-semibold">
                        {part.slice(2, -2)}
                    </strong>
                ) : (
                    <span key={index}>{part}</span>
                ),
            )}
        </>
    );
}
