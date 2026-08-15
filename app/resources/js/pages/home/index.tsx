import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CircleDashed,
    Inbox,
    Lock,
    Send,
    Sparkles,
    WandSparkles,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { postJson } from '@/lib/json';
import { index as approvalsIndex } from '@/routes/approvals';
import { index as socialIndex } from '@/routes/social';
import { plan as socialPlan } from '@/routes/social';
import { show as showPost } from '@/routes/social/posts';
import { store as storeIdea } from '@/routes/studio/ideas';

type Step = {
    key: string;
    label: string;
    detail: string;
    done: boolean;
    locked: boolean;
    blocked_by: string | null;
    action: string | null;
    action_label: string | null;
};

type Kind = { value: string; label: string; channels: string[] };

type Waiting = {
    social_drafts: Array<{
        id: string;
        title: string;
        channel: string;
        scheduled_for: string | null;
    }>;
    social_draft_count: number;
    article_draft_count: number;
    failed_deliveries: number;
};

type Props = {
    project: { name: string; site_name: string } | null;
    checklist: Step[];
    waiting?: Waiting;
    kinds: Kind[];
};

/**
 * The landing screen: something to type into, what is not set up, what is
 * waiting.
 *
 * Deliberately not a second dashboard. The dashboard reports on the engine —
 * runs, impressions, citations, stack health — and this answers the only
 * question somebody has when they open the product in the morning.
 */
export default function Home({ project, checklist, waiting, kinds }: Props) {
    if (project === null) {
        return (
            <>
                <Head title="Home" />
                <WorkspacePage width="reading">
                    <WorkspaceHeader
                        eyebrow="Home"
                        title="No project yet"
                        description="Create a project and the engine has something to read."
                    />
                </WorkspacePage>
            </>
        );
    }

    return (
        <>
            <Head title="Home" />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="Home"
                    context={project.site_name}
                    title="What would you like to make?"
                    description="Write one post now, or pick up what the month already planned."
                />

                <Composer kinds={kinds} />
                <Checklist steps={checklist} />

                {/*
                    `Deferred` waits for the prop and renders the fallback while
                    it is in flight; it does not hand the prop to its children.
                    So the value has to be passed in — without it `waiting` was
                    permanently undefined and this band showed its skeleton
                    forever, which looks exactly like a slow request that never
                    lands.
                */}
                <Deferred data="waiting" fallback={<WaitingSkeleton />}>
                    <Waiting waiting={waiting} />
                </Deferred>
            </WorkspacePage>
        </>
    );
}

/**
 * One post, from a sentence.
 *
 * The chip the reference product puts at the top of its home screen, with the
 * one difference that matters: this one has an engine behind it. Every idea in
 * this system used to come from a model reading the website once a month, so
 * the thing that happened this morning had nowhere to go.
 *
 * The kind is a real choice rather than a decoration, because the kind decides
 * the channels — the same rule a proposal is held to, so a hand-written idea
 * cannot do the one thing every planned idea is forbidden.
 */
function Composer({ kinds }: { kinds: Kind[] }) {
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [thesis, setThesis] = useState('');
    const [kind, setKind] = useState(kinds[0]?.value ?? 'take');
    const [date, setDate] = useState(() =>
        new Date().toISOString().slice(0, 10),
    );
    const [pending, setPending] = useState(false);

    const chosen = kinds.find((entry) => entry.value === kind);

    async function create() {
        if (title.trim() === '' || thesis.trim() === '') {
            return;
        }

        setPending(true);

        const result = await postJson<{ idea: { id: string; title: string } }>(
            storeIdea().url,
            {
                title: title.trim(),
                thesis: thesis.trim(),
                kind,
                date,
            },
        );

        setPending(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        toast.success('Writing it now — it will appear on the board.');
        router.get(socialIndex());
    }

    return (
        <section className={`${workspacePanelClass} p-4 sm:p-5`}>
            <div className="flex min-w-0 items-center gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-violet-500/12 text-violet-600 dark:text-violet-300">
                    <Sparkles className="size-4" aria-hidden="true" />
                </span>
                <Input
                    aria-label="What is the post about?"
                    value={title}
                    placeholder="What happened today that is worth a post?"
                    onChange={(event) => {
                        setTitle(event.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    className="h-11 border-0 bg-transparent text-base shadow-none focus-visible:ring-0"
                />
                {!open && (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Write a post"
                        onClick={() => setOpen(true)}
                    >
                        <Send className="size-4" aria-hidden="true" />
                    </Button>
                )}
            </div>

            {open && (
                <div className="mt-4 grid gap-4 border-t pt-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="idea-thesis">
                            What is the point of it?
                        </Label>
                        <Textarea
                            id="idea-thesis"
                            value={thesis}
                            rows={2}
                            placeholder="The one thing a reader should take away."
                            onChange={(event) => setThesis(event.target.value)}
                        />
                    </div>

                    <div
                        className="grid gap-2 sm:grid-cols-5"
                        role="radiogroup"
                        aria-label="What kind of post is it?"
                    >
                        {kinds.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={option.value === kind}
                                onClick={() => setKind(option.value)}
                                className={`rounded-xl border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none ${
                                    option.value === kind
                                        ? 'border-violet-500/60 bg-violet-500/10'
                                        : 'bg-background hover:bg-muted/30'
                                }`}
                            >
                                <span className="block text-sm font-medium">
                                    {option.label}
                                </span>
                                <span className="mt-1 block text-[11px] text-muted-foreground">
                                    {option.channels.join(' · ')}
                                </span>
                            </button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="idea-date">Publish on</Label>
                            <Input
                                id="idea-date"
                                type="date"
                                value={date}
                                onChange={(event) =>
                                    setDate(event.target.value)
                                }
                                className="w-44"
                            />
                        </div>
                        <Button
                            disabled={
                                pending ||
                                title.trim() === '' ||
                                thesis.trim() === ''
                            }
                            onClick={() => void create()}
                        >
                            <WandSparkles
                                className="size-4"
                                aria-hidden="true"
                            />
                            {pending ? 'Writing…' : 'Write it'}
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            {chosen === undefined
                                ? null
                                : `Writes ${chosen.channels.length} native ${chosen.channels.length === 1 ? 'draft' : 'drafts'} and their pictures. Nothing publishes.`}
                        </p>
                    </div>
                </div>
            )}
        </section>
    );
}

/**
 * What is set up, and what is genuinely not reachable yet.
 *
 * A padlock here means a real prerequisite, not a position in the list — see
 * `App\Social\ActivationChecklist` for why that divergence from the reference
 * is the whole point of the component.
 */
function Checklist({ steps }: { steps: Step[] }) {
    const done = steps.filter((step) => step.done).length;

    if (done === steps.length) {
        return null;
    }

    return (
        <section className={`${workspacePanelClass} overflow-hidden`}>
            <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h2 className="font-semibold tracking-tight">Set up</h2>
                <div className="flex items-center gap-3">
                    <div className="h-1.5 w-32 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-emerald-500 transition-[width]"
                            style={{
                                width: `${Math.round((done / steps.length) * 100)}%`,
                            }}
                        />
                    </div>
                    <span className="text-sm text-muted-foreground tabular-nums">
                        {done}/{steps.length}
                    </span>
                </div>
            </header>

            <ol>
                {steps.map((step) => (
                    <li
                        key={step.key}
                        className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5 last:border-b-0"
                    >
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="mt-0.5 shrink-0">
                                {step.done ? (
                                    <Check
                                        className="size-4 text-emerald-600 dark:text-emerald-400"
                                        aria-label="Done"
                                    />
                                ) : step.locked ? (
                                    <Lock
                                        className="size-4 text-muted-foreground"
                                        aria-label="Not available yet"
                                    />
                                ) : (
                                    <CircleDashed
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                )}
                            </span>
                            <div className="min-w-0">
                                <p
                                    className={`text-sm font-medium ${step.done ? 'text-muted-foreground line-through' : ''}`}
                                >
                                    {step.label}
                                </p>
                                <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                    {step.blocked_by ?? step.detail}
                                </p>
                            </div>
                        </div>
                        {step.action !== null && !step.locked && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={step.action}>
                                    {step.action_label}
                                    <ArrowRight
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </Button>
                        )}
                    </li>
                ))}
            </ol>
        </section>
    );
}

function Waiting({ waiting }: { waiting?: Waiting }) {
    if (waiting === undefined) {
        return <WaitingSkeleton />;
    }

    const nothing =
        waiting.social_draft_count === 0 &&
        waiting.article_draft_count === 0 &&
        waiting.failed_deliveries === 0;

    return (
        <section className={`${workspacePanelClass} overflow-hidden`}>
            <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h2 className="font-semibold tracking-tight">
                    Waiting for you
                </h2>
                <Inbox
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
            </header>

            {nothing ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    Nothing is waiting. The engine will say when something is.
                </p>
            ) : (
                <div className="grid gap-3 p-4">
                    <div className="flex flex-wrap gap-2">
                        {waiting.social_draft_count > 0 && (
                            <Badge variant="secondary" className="rounded-full">
                                {waiting.social_draft_count} social drafts
                            </Badge>
                        )}
                        {waiting.article_draft_count > 0 && (
                            <Badge variant="secondary" className="rounded-full">
                                {waiting.article_draft_count} article drafts
                            </Badge>
                        )}
                        {waiting.failed_deliveries > 0 && (
                            <Badge
                                variant="outline"
                                className="rounded-full border-red-500/40"
                            >
                                {waiting.failed_deliveries} dead deliveries
                            </Badge>
                        )}
                    </div>

                    {waiting.social_drafts.length > 0 && (
                        <ul className="grid gap-1.5">
                            {waiting.social_drafts.map((draft) => (
                                <li key={draft.id}>
                                    <Link
                                        href={showPost(draft.id)}
                                        className="flex items-center justify-between gap-3 rounded-xl border bg-background/60 px-3.5 py-2.5 text-sm transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                                    >
                                        <span className="min-w-0 truncate">
                                            {draft.title}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground capitalize">
                                            {draft.channel}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link href={approvalsIndex()}>Open the queue</Link>
                        </Button>
                        <Button asChild size="sm" variant="ghost">
                            <Link href={socialIndex()}>The board</Link>
                        </Button>
                        <Button asChild size="sm" variant="ghost">
                            <Link href={socialPlan()}>The Studio</Link>
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}

function WaitingSkeleton() {
    return (
        <section className={`${workspacePanelClass} p-5`}>
            <div className="h-4 w-32 animate-pulse rounded bg-muted" />
            <div className="mt-4 h-10 w-full animate-pulse rounded bg-muted" />
        </section>
    );
}
