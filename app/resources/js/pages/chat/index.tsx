import { Head, Link } from '@inertiajs/react';
import { MessagesSquare, Plus } from 'lucide-react';
import type { ChatSummary } from '@/components/chat-thread';
import { Button } from '@/components/ui/button';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index as chatIndex, show as showChat } from '@/routes/assistant';
import { index as homeIndex } from '@/routes/home';

type Props = { threads: ChatSummary[] };

/**
 * Every conversation, newest first.
 *
 * The list exists because the alternative — the shape this feature shipped with
 * first — was a chat you had once and could not find again. Starting one still
 * happens on Home, where the box is; this is where they live afterwards.
 */
export default function ChatIndex({ threads }: Props) {
    return (
        <>
            <Head title="Chats" />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="AI assistant"
                    title="Chats"
                    description="Every chat with Avyo, newest first."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={homeIndex()}>
                                <Plus className="size-4" aria-hidden="true" />
                                New chat
                            </Link>
                        </Button>
                    }
                />

                {threads.length === 0 ? (
                    <section
                        className={`${workspacePanelClass} px-5 py-10 text-center`}
                    >
                        <p className="text-sm text-muted-foreground">
                            No chats yet. Ask something from the box on Home to
                            start one.
                        </p>
                        <Button asChild className="mt-5">
                            <Link href={homeIndex()}>Go to Home</Link>
                        </Button>
                    </section>
                ) : (
                    <section
                        className={`${workspacePanelClass} overflow-hidden`}
                    >
                        <ul className="divide-y">
                            {threads.map((thread) => (
                                <li key={thread.id}>
                                    <Link
                                        href={showChat(thread.id)}
                                        prefetch
                                        className="flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                                    >
                                        <MessagesSquare
                                            className="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                            {thread.title ?? 'Untitled'}
                                        </span>
                                        <time
                                            className="shrink-0 text-xs text-muted-foreground"
                                            dateTime={
                                                thread.last_message_at ??
                                                undefined
                                            }
                                        >
                                            {said(thread.last_message_at)}
                                        </time>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </WorkspacePage>
        </>
    );
}

/**
 * When something was last said, in the words somebody would use.
 *
 * A date on today's conversation reads as older than it is, and a clock time on
 * last month's reads as nonsense — so each gets the half that carries meaning.
 */
function said(at: string | null): string {
    if (at === null) {
        return '';
    }

    const when = new Date(at);
    const today = new Date();
    const sameDay = when.toDateString() === today.toDateString();

    return sameDay
        ? when.toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
          })
        : when.toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
          });
}

ChatIndex.layout = {
    breadcrumbs: [{ title: 'Chats', href: chatIndex() }],
};
