import { Head, Link, router } from '@inertiajs/react';
import { ArrowUp, Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ChatSummary, Turn } from '@/components/chat-thread';
import { ChatThread } from '@/components/chat-thread';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { WorkspacePage } from '@/components/workspace-page';
import {
    destroy as destroyChat,
    index as chatIndex,
    rename as renameChat,
    reply as replyToChat,
} from '@/routes/assistant';
import { index as homeIndex } from '@/routes/home';

type Props = {
    thread: { id: string; title: string | null };
    turns: Turn[];
    threads: ChatSummary[];
};

/**
 * One conversation, at its own address.
 *
 * The first version of this feature put a single endless thread on the landing
 * screen, which made a conversation something you had once and then could not
 * find. A subject deserves a name and a URL: this month's plan, the Portuguese
 * visibility gap and the dead deliveries are three conversations, and holding
 * them in one scroll makes all three harder to return to than none would be.
 */
export default function ChatShow({ thread, turns, threads }: Props) {
    const [pending, setPending] = useState(false);

    return (
        <>
            <Head title={thread.title ?? 'Chat'} />

            <WorkspacePage width="reading">
                <div className="mx-auto flex w-full max-w-[52rem] flex-col gap-5">
                    <Header thread={thread} threads={threads} />

                    <ChatThread turns={turns} pending={pending} />

                    <Replier
                        threadId={thread.id}
                        pending={pending}
                        setPending={setPending}
                    />
                </div>
            </WorkspacePage>
        </>
    );
}

function Header({
    thread,
    threads,
}: {
    thread: Props['thread'];
    threads: ChatSummary[];
}) {
    const [renaming, setRenaming] = useState(false);
    const [title, setTitle] = useState(thread.title ?? '');

    // A thread's title comes from its first message, so it is a summary rather
    // than a decision — and the person who had the conversation is the one who
    // knows what it turned out to be about.
    function save() {
        setRenaming(false);

        if (title.trim() !== '' && title !== thread.title) {
            router.patch(renameChat(thread.id).url, { title: title.trim() });
        }
    }

    return (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b pb-3">
            {renaming ? (
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    <Input
                        value={title}
                        autoFocus
                        aria-label="Chat name"
                        onChange={(event) => setTitle(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                save();
                            }

                            if (event.key === 'Escape') {
                                setTitle(thread.title ?? '');
                                setRenaming(false);
                            }
                        }}
                        className="h-9 max-w-sm"
                    />
                    <Button
                        size="icon"
                        variant="ghost"
                        onClick={save}
                        aria-label="Save chat name"
                    >
                        <Check className="size-4" aria-hidden="true" />
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label="Keep current chat name"
                        onClick={() => {
                            setTitle(thread.title ?? '');
                            setRenaming(false);
                        }}
                    >
                        <X className="size-4" aria-hidden="true" />
                    </Button>
                </div>
            ) : (
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    <h1 className="min-w-0 truncate text-base font-semibold tracking-tight">
                        {thread.title ?? 'Chat'}
                    </h1>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="size-8 shrink-0"
                        aria-label="Rename this chat"
                        onClick={() => setRenaming(true)}
                    >
                        <Pencil className="size-3.5" aria-hidden="true" />
                    </Button>
                </div>
            )}

            <div className="flex shrink-0 items-center gap-1.5">
                {threads.length > 1 && (
                    <Button
                        asChild
                        variant="ghost"
                        className="h-8 px-3 text-xs"
                    >
                        <Link href={chatIndex()}>All chats</Link>
                    </Button>
                )}
                {/* Home, because that is where a conversation starts — this
                    sat beside "All chats" pointing at the same list. */}
                <Button asChild variant="outline" className="h-8 px-3 text-xs">
                    <Link href={homeIndex()}>
                        <Plus className="size-3.5" aria-hidden="true" />
                        New
                    </Link>
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8 text-muted-foreground"
                    aria-label="Delete this chat"
                    onClick={() => router.delete(destroyChat(thread.id).url)}
                >
                    <Trash2 className="size-3.5" aria-hidden="true" />
                </Button>
            </div>
        </header>
    );
}

function Replier({
    threadId,
    pending,
    setPending,
}: {
    threadId: string;
    pending: boolean;
    setPending: (value: boolean) => void;
}) {
    const formRef = useRef<HTMLFormElement>(null);
    const promptRef = useRef<HTMLTextAreaElement>(null);
    const coarsePointer = useRef(false);
    const [prompt, setPrompt] = useState('');

    useEffect(() => {
        coarsePointer.current = window.matchMedia('(pointer: coarse)').matches;
    }, []);

    const onKeyDown = useCallback(
        (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
            if (event.key !== 'Enter') {
                return;
            }

            // Enter mid-composition commits the IME candidate, not the message.
            if (event.nativeEvent.isComposing) {
                return;
            }

            if (event.shiftKey || event.altKey || coarsePointer.current) {
                return;
            }

            event.preventDefault();
            formRef.current?.requestSubmit();
        },
        [],
    );

    function submit() {
        const text = prompt.trim();

        if (text.length < 2 || pending) {
            return;
        }

        setPending(true);
        setPrompt('');

        router.post(
            replyToChat(threadId).url,
            { message: text },
            {
                preserveScroll: true,
                onFinish: () => setPending(false),
                onError: () => setPrompt(text),
            },
        );
    }

    return (
        <form
            ref={formRef}
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
            className="sticky bottom-4 rounded-[1.5rem] bg-[linear-gradient(140deg,rgba(214,83,60,0.40),rgba(243,207,106,0.30)_38%,rgba(49,85,165,0.28))] p-px opacity-80 transition-opacity focus-within:opacity-100 focus-within:ring-2 focus-within:ring-terracotta/45 focus-within:ring-offset-2 focus-within:ring-offset-background motion-reduce:transition-none"
        >
            <div className="flex items-end gap-2 rounded-[calc(1.5rem-1px)] bg-card px-4 py-3 shadow-[0_1px_2px_rgba(23,53,47,0.04),0_18px_44px_-12px_rgba(23,53,47,0.16)] forced-colors:border forced-colors:border-[ButtonBorder]">
                <label htmlFor="chat-reply" className="sr-only">
                    Say something
                </label>
                <textarea
                    id="chat-reply"
                    ref={promptRef}
                    rows={1}
                    value={prompt}
                    maxLength={4000}
                    placeholder="Say something…"
                    onChange={(event) => setPrompt(event.target.value)}
                    onKeyDown={onKeyDown}
                    className="[field-sizing:content] max-h-40 min-h-[2.5rem] w-full resize-none border-0 bg-transparent py-1.5 text-base leading-relaxed text-foreground shadow-none outline-none placeholder:text-muted-foreground/80 focus-visible:ring-0"
                />
                <button
                    type="submit"
                    disabled={pending}
                    aria-busy={pending}
                    className="mb-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-[linear-gradient(135deg,var(--color-terracotta),var(--color-terracotta-deep))] text-white shadow-[0_1px_2px_rgba(23,53,47,0.2),0_6px_16px_-4px_rgba(214,83,60,0.5)] transition-transform duration-150 focus-visible:ring-2 focus-visible:ring-terracotta focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-none active:scale-[0.96] disabled:opacity-60 motion-reduce:transition-none motion-reduce:active:scale-100 dark:bg-[linear-gradient(135deg,var(--color-honey),#e0b44f)] dark:text-forest"
                >
                    {pending ? (
                        <Spinner className="size-4" />
                    ) : (
                        <ArrowUp
                            className="size-4"
                            strokeWidth={2.25}
                            aria-hidden="true"
                        />
                    )}
                    <span className="sr-only">
                        {pending ? 'Working' : 'Send'}
                    </span>
                </button>
            </div>
        </form>
    );
}

ChatShow.layout = {
    breadcrumbs: [{ title: 'Chats', href: chatIndex() }],
};
