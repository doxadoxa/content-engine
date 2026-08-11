import { Head, router } from '@inertiajs/react';
import {
    BookOpenCheck,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Globe2,
    Image as ImageIcon,
    Instagram,
    Maximize2,
    MessageCircle,
    Send,
    Sparkles,
    WandSparkles,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import AlertError from '@/components/alert-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { postJson } from '@/lib/json';
import { accept, generate, index, propose, refine } from '@/routes/studio';
import { choose, revise, upload } from '@/routes/studio/image';

type Source = {
    website_url: string | null;
    site_name: string;
    site_description: string;
    has_brief: boolean;
    site_articles: number;
};

type Fact = { claim: string; source: string };
type Pillar = { name: string; purpose: string };

type Strategy = {
    site_facts?: Fact[];
    assumptions?: string[];
    objectives?: string[];
    pillars?: Pillar[];
    channel_roles?: Record<string, string>;
    questions?: string[];
    /** What is still wrong with the month's mix after the assistant was asked twice. */
    mix_findings?: string[];
};

type Draft = {
    id: string;
    channel: string;
    body: string | null;
    payload: Record<string, unknown> | null;
    state: string;
    assets: Array<{
        id: string;
        url: string;
        alt: string;
        width: number | null;
        height: number | null;
        chosen: boolean;
        retired: boolean;
        source: string;
    }>;
};

type ProductionShape = {
    format: string;
    visual: string;
};

type Idea = {
    id: string;
    key: string;
    date: string;
    title: string;
    kind: string;
    kind_label: string;
    pillar: string;
    thesis: string;
    evidence: string[];
    goal: string;
    audience: string;
    angle: string | null;
    channels: string[];
    production: Record<string, ProductionShape>;
    drafts: Draft[];
};

type Message = {
    id: string;
    role: 'assistant' | 'user';
    body: string;
    version: number;
    metadata: Record<string, unknown>;
    created_at: string | null;
};

type Plan = {
    id: string;
    month: string;
    summary: string | null;
    strategy: Strategy;
    version: number;
    accepted_version: number | null;
    accepted: boolean;
    proposed_at: string | null;
    accepted_at: string | null;
    messages: Message[];
    ideas: Idea[];
};

type Operation = {
    id: string;
    action:
        | 'proposal'
        | 'refine'
        | 'generate_week'
        | 'generate_idea'
        | 'revise_image'
        | null;
    status: 'pending' | 'running' | 'failed' | 'completed' | 'cancelled';
    message: string | null;
    result: {
        version?: number;
        /** Drafts written — on a per-idea run. */
        created?: number;
        /** Ideas dispatched — on a fan-out run. */
        ideas?: number;
        idea?: string;
        /** Pictures drawn — on an image revision. */
        variants?: number;
        from?: string | null;
        until?: string | null;
    } | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
};

type Props = {
    month: string;
    label: string;
    previous: string;
    next: string;
    source: Source;
    plan: Plan | null;
    operation: Operation | null;
};

type Busy = 'proposing' | 'refining' | 'accepting' | 'generating' | null;

function isActive(operation: Operation | null): boolean {
    return operation?.status === 'pending' || operation?.status === 'running';
}

function busyFor(operation: Operation | null): Busy {
    if (!isActive(operation)) {
        return null;
    }

    if (operation?.action === 'refine') {
        return 'refining';
    }

    // Drafting is a fan-out run followed by one run per idea, and the poll
    // follows the newest — so after the fan-out the screen is watching a
    // `generate_idea`. Without these two arms it fell through to 'proposing'
    // and told the operator it was building a proposal while it drafted their
    // week, or drew a picture.
    if (
        operation?.action === 'generate_week' ||
        operation?.action === 'generate_idea' ||
        operation?.action === 'revise_image'
    ) {
        return 'generating';
    }

    return 'proposing';
}

function notifyCompleted(operation: Operation, plan: Plan | null) {
    if (operation.action === 'refine' && plan !== null) {
        toast.success(`Proposal v${plan.version} is ready`);

        return;
    }

    if (operation.action === 'generate_idea') {
        const created = operation.result?.created;

        toast.success(
            typeof created === 'number' && created > 0
                ? `${created} drafts ready for one idea`
                : 'An idea finished drafting',
        );

        return;
    }

    if (operation.action === 'revise_image') {
        const variants = operation.result?.variants;

        toast.success(
            typeof variants === 'number' && variants > 1
                ? `${variants} pictures to choose from`
                : 'A new picture is ready',
        );

        return;
    }

    if (operation.action === 'generate_week') {
        // What it dispatched, not what it wrote: drafting is a run per idea
        // now, and each finishes on its own. The plan reloads as they land.
        const ideas = operation.result?.ideas;

        toast.success(
            ideas === 0
                ? 'Every batch in this proposal is already drafted'
                : typeof ideas === 'number'
                  ? `Drafting ${ideas} ${ideas === 1 ? 'idea' : 'ideas'}…`
                  : 'The next weekly batch is on its way',
        );

        return;
    }

    const created = operation.result?.created;
    toast.success(
        typeof created === 'number' && created > 0
            ? `The proposal and ${created} starter drafts are ready`
            : 'The monthly proposal is ready',
    );
}

/**
 * A conversation beside the artifact it changes.
 *
 * The assistant makes the first move from saved site context. Chat remains the
 * interface for intent; strategy, ideas, versions, and drafts remain visible
 * state instead of disappearing into a transcript.
 */
export default function Studio({
    month,
    label,
    previous,
    next,
    source,
    plan: initialPlan,
    operation: initialOperation,
}: Props) {
    const [plan, setPlan] = useState(initialPlan);
    const [operation, setOperation] = useState(initialOperation);
    const [busy, setBusy] = useState<Busy>(() => busyFor(initialOperation));
    const [error, setError] = useState<string | null>(null);
    const [message, setMessage] = useState('');

    async function startProposal() {
        if ((plan !== null && plan.version > 0) || isActive(operation)) {
            return;
        }

        setBusy('proposing');
        setError(null);

        const result = await postJson<{
            plan: Plan;
            operation: Operation | null;
        }>(propose().url, { month });

        if (!result.ok) {
            setBusy(null);
            setError(result.message);

            return;
        }

        setOperation(result.data.operation);

        if (result.data.plan.version > 0) {
            setPlan(result.data.plan);
        }

        if (!isActive(result.data.operation)) {
            setBusy(null);
        }
    }

    useEffect(() => {
        if (!isActive(operation)) {
            return;
        }

        let cancelled = false;
        let timer: number | undefined;

        const schedule = () => {
            timer = window.setTimeout(poll, 1_500);
        };

        const poll = () => {
            if (cancelled) {
                return;
            }

            router.reload({
                only: ['plan', 'operation'],
                onSuccess: (page) => {
                    if (cancelled) {
                        return;
                    }

                    const serverPlan = page.props.plan as Plan | null;
                    const serverOperation = page.props
                        .operation as Operation | null;

                    if (serverPlan !== null && serverPlan.version > 0) {
                        setPlan(serverPlan);
                    }

                    setOperation(serverOperation);

                    if (isActive(serverOperation)) {
                        schedule();

                        return;
                    }

                    setBusy(null);

                    if (serverOperation?.status === 'failed') {
                        setError(
                            serverOperation.message ??
                                'The Studio operation failed.',
                        );

                        return;
                    }

                    if (serverOperation?.status === 'completed') {
                        setError(null);
                        notifyCompleted(serverOperation, serverPlan);
                    }
                },
                onError: () => schedule(),
            });
        };

        schedule();

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [operation]);

    const readyPlan = plan !== null && plan.version > 0 ? plan : null;

    const hasMissingDrafts =
        readyPlan?.ideas.some(
            (idea) => idea.drafts.length < idea.channels.length,
        ) ?? false;

    async function sendRefinement() {
        if (plan === null || message.trim() === '') {
            return;
        }

        setBusy('refining');
        setError(null);

        const result = await postJson<{
            plan: Plan;
            operation: Operation | null;
        }>(refine(plan.id).url, {
            version: plan.version,
            message: message.trim(),
        });

        if (!result.ok) {
            setBusy(null);
            setError(result.message);

            return;
        }

        setOperation(result.data.operation);
        setMessage('');
    }

    async function acceptProposal() {
        if (plan === null) {
            return;
        }

        setBusy('accepting');
        setError(null);

        const result = await postJson<{ plan: Plan }>(accept(plan.id).url, {
            version: plan.version,
        });

        setBusy(null);

        if (!result.ok) {
            setError(result.message);

            return;
        }

        setPlan(result.data.plan);
        toast.success('Content system accepted for this version');
    }

    async function generateBatch() {
        if (plan === null) {
            return;
        }

        setBusy('generating');
        setError(null);

        const result = await postJson<{
            plan: Plan;
            operation: Operation | null;
        }>(generate(plan.id).url, {});

        if (!result.ok) {
            setBusy(null);
            setError(result.message);

            return;
        }

        setOperation(result.data.operation);
    }

    return (
        <>
            <Head title={`Studio — ${label}`} />

            <WorkspacePage className="xl:h-[calc(100svh-5rem)] xl:min-h-0 xl:overflow-hidden">
                <WorkspaceHeader
                    eyebrow="Content studio"
                    context={
                        operation?.status === 'pending'
                            ? 'Queued for generation'
                            : busy === 'proposing'
                              ? 'Building proposal'
                              : busy === 'refining'
                                ? 'Updating proposal'
                                : busy === 'generating'
                                  ? 'Generating drafts'
                                  : plan === null
                                    ? 'Reading the project'
                                    : readyPlan?.accepted
                                      ? `Accepted proposal v${readyPlan.version}`
                                      : readyPlan
                                        ? `Proposal v${readyPlan.version}`
                                        : 'Reading the project'
                    }
                    title={label}
                    description="Talk through the month with a site-aware content partner. The plan and every draft stay visible as working artifacts."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Previous month"
                                className="rounded-full bg-background/70"
                                onClick={() =>
                                    router.get(
                                        index({
                                            query: { month: previous },
                                        }),
                                    )
                                }
                            >
                                <ChevronLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Next month"
                                className="rounded-full bg-background/70"
                                onClick={() =>
                                    router.get(
                                        index({ query: { month: next } }),
                                    )
                                }
                            >
                                <ChevronRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                        </>
                    }
                />

                {error && <AlertError errors={[error]} />}

                <div className="grid min-w-0 gap-5 xl:min-h-0 xl:flex-1 xl:grid-cols-[minmax(0,1.45fr)_minmax(22rem,0.55fr)]">
                    <main className="min-w-0 xl:min-h-0">
                        <ConversationPanel
                            source={source}
                            plan={readyPlan}
                            busy={busy}
                            operationStatus={operation?.status ?? null}
                            proposalFailed={error !== null}
                            message={message}
                            onMessage={setMessage}
                            onSend={() => void sendRefinement()}
                            onBuild={() => void startProposal()}
                        />
                    </main>

                    <aside className="flex min-w-0 flex-col gap-4 xl:h-full xl:min-h-0 xl:overflow-y-auto xl:pr-1">
                        {readyPlan === null ? (
                            <ProposalLoading
                                source={source}
                                busy={busy}
                                operationStatus={operation?.status ?? null}
                                proposalFailed={error !== null}
                            />
                        ) : (
                            <>
                                <PlanActions
                                    plan={readyPlan}
                                    busy={busy}
                                    operationStatus={operation?.status ?? null}
                                    hasMissingDrafts={hasMissingDrafts}
                                    onAccept={() => void acceptProposal()}
                                    onGenerate={() => void generateBatch()}
                                />
                                <StrategyArtifact plan={readyPlan} />
                                <IdeasPanel
                                    plan={readyPlan}
                                    onRefreshed={(nextPlan, nextOperation) => {
                                        setPlan(nextPlan);
                                        setOperation(nextOperation);

                                        if (isActive(nextOperation)) {
                                            setBusy('generating');
                                        }
                                    }}
                                />
                            </>
                        )}
                    </aside>
                </div>
            </WorkspacePage>
        </>
    );
}

function ConversationPanel({
    source,
    plan,
    busy,
    operationStatus,
    proposalFailed,
    message,
    onMessage,
    onSend,
    onBuild,
}: {
    source: Source;
    plan: Plan | null;
    busy: Busy;
    operationStatus: Operation['status'] | null;
    proposalFailed: boolean;
    message: string;
    onMessage: (value: string) => void;
    onSend: () => void;
    onBuild: () => void;
}) {
    const questions = plan?.strategy?.questions ?? [];

    return (
        <section
            className={`${workspacePanelClass} flex min-h-[30rem] flex-col overflow-hidden xl:h-full xl:min-h-0`}
            aria-label="Content partner conversation"
        >
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-violet-500/12 text-violet-600 dark:text-violet-300">
                        <Sparkles className="size-4" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="font-semibold tracking-tight">
                            Content partner
                        </h2>
                        <p className="truncate text-xs text-muted-foreground">
                            Working from {source.site_name}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center justify-end gap-1.5">
                    <Badge variant="outline" className="rounded-full">
                        <Globe2 className="mr-1 size-3" aria-hidden="true" />
                        Website
                    </Badge>
                    {source.has_brief && (
                        <Badge variant="outline" className="rounded-full">
                            Brand brief
                        </Badge>
                    )}
                    <Badge variant="outline" className="rounded-full">
                        {source.site_articles} articles
                    </Badge>
                </div>
            </header>

            <div
                className="min-h-0 flex-1 overflow-y-auto px-5 py-6 sm:px-7"
                aria-live="polite"
            >
                <div className="mx-auto flex w-full max-w-4xl flex-col gap-5">
                    {plan === null ? (
                        <ChatAssistantBubble>
                            {busy === 'proposing'
                                ? `I’m reading ${source.site_name}, the active brief, and the existing corpus. I’ll come back with a point of view for the month.`
                                : proposalFailed
                                  ? 'I could not finish the first proposal. Retry when the model is available.'
                                  : `There is no proposal for this month yet. I’ll use ${source.site_name}, the active brief, and the existing corpus when you start it.`}
                        </ChatAssistantBubble>
                    ) : (
                        plan.messages.map((item) =>
                            item.role === 'user' ? (
                                <ChatUserBubble key={item.id}>
                                    {item.body}
                                </ChatUserBubble>
                            ) : (
                                <ChatAssistantBubble key={item.id}>
                                    {item.body}
                                </ChatAssistantBubble>
                            ),
                        )
                    )}

                    {(busy === 'refining' ||
                        busy === 'proposing' ||
                        busy === 'generating') && (
                        <div className="flex items-center gap-2 px-3 text-sm text-muted-foreground">
                            <WandSparkles
                                className="size-3.5 animate-pulse"
                                aria-hidden="true"
                            />
                            {operationStatus === 'pending'
                                ? 'Queued — waiting for a generation worker…'
                                : busy === 'proposing'
                                  ? 'Building the strategy and calendar…'
                                  : busy === 'generating'
                                    ? 'Creating starter drafts and visuals…'
                                    : 'Rebuilding the strategy and calendar…'}
                        </div>
                    )}
                </div>
            </div>

            <div className="border-t bg-background/85 p-4 backdrop-blur sm:p-5">
                <div className="mx-auto w-full max-w-4xl">
                    {plan === null ? (
                        busy === null && (
                            <Button
                                className="w-full rounded-xl"
                                onClick={onBuild}
                            >
                                <WandSparkles
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {proposalFailed
                                    ? 'Try the proposal again'
                                    : 'Build this month’s plan'}
                            </Button>
                        )
                    ) : (
                        <form
                            className="grid gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                onSend();
                            }}
                        >
                            {questions.length > 0 && (
                                <div
                                    className="grid min-w-0 gap-2 sm:grid-cols-2"
                                    aria-label="Suggested context to add"
                                >
                                    {questions.slice(0, 2).map((question) => (
                                        <button
                                            key={question}
                                            type="button"
                                            className="flex min-w-0 items-start gap-2 rounded-2xl border bg-muted/20 px-3.5 py-2.5 text-left text-xs leading-5 whitespace-normal text-muted-foreground transition-colors hover:border-foreground/20 hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                                            onClick={() => onMessage(question)}
                                        >
                                            <MessageCircle
                                                className="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span className="min-w-0">
                                                {question}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                            <div className="relative rounded-3xl border bg-muted/25 p-2 shadow-sm focus-within:ring-2 focus-within:ring-ring/30">
                                <label
                                    htmlFor="studio-message-primary"
                                    className="sr-only"
                                >
                                    Ask the content partner to change the plan
                                </label>
                                <Textarea
                                    id="studio-message-primary"
                                    value={message}
                                    onChange={(event) =>
                                        onMessage(event.target.value)
                                    }
                                    placeholder="Ask, challenge an assumption, or reshape the month…"
                                    rows={3}
                                    maxLength={5000}
                                    disabled={busy !== null}
                                    className="min-h-24 resize-none border-0 bg-transparent px-3 py-2 pr-14 shadow-none focus-visible:ring-0"
                                />
                                <Button
                                    type="submit"
                                    size="icon"
                                    aria-label="Send to content partner"
                                    disabled={
                                        busy !== null || message.trim() === ''
                                    }
                                    className="absolute right-3 bottom-3 rounded-full"
                                >
                                    <Send
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </div>
        </section>
    );
}

function ChatAssistantBubble({ children }: { children: string }) {
    return (
        <div className="flex items-start gap-3">
            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-violet-500/12 text-violet-600 dark:text-violet-300">
                <Sparkles className="size-3.5" aria-hidden="true" />
            </span>
            <div className="max-w-[44rem] pt-0.5 text-[0.95rem] leading-7">
                {children}
            </div>
        </div>
    );
}

function ChatUserBubble({ children }: { children: string }) {
    return (
        <div className="ml-auto max-w-[85%] rounded-2xl rounded-tr-md bg-muted px-4 py-3 text-sm leading-relaxed">
            {children}
        </div>
    );
}

function ProposalLoading({
    source,
    busy,
    operationStatus,
    proposalFailed,
}: {
    source: Source;
    busy: Busy;
    operationStatus: Operation['status'] | null;
    proposalFailed: boolean;
}) {
    return (
        <Card className={`${workspacePanelClass} min-h-[28rem]`}>
            <CardContent className="flex min-h-[28rem] flex-col items-center justify-center p-8 text-center">
                <span className="flex size-14 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                    {busy === 'proposing' ? (
                        <WandSparkles
                            className="size-6 animate-pulse"
                            aria-hidden="true"
                        />
                    ) : proposalFailed ? (
                        <CircleAlert className="size-6" aria-hidden="true" />
                    ) : (
                        <Sparkles className="size-6" aria-hidden="true" />
                    )}
                </span>
                <h2 className="mt-5 text-xl font-semibold tracking-tight">
                    {busy === 'proposing'
                        ? operationStatus === 'pending'
                            ? 'Your proposal is queued'
                            : `Building a first point of view on ${source.site_name}`
                        : proposalFailed
                          ? 'The proposal is not available yet'
                          : 'This month is ready for a proposal'}
                </h2>
                <p className="mt-2 max-w-lg text-sm leading-relaxed text-muted-foreground">
                    {operationStatus === 'pending'
                        ? 'A generation worker will pick it up shortly. You can reload or leave this page; the operation and its result are durable.'
                        : 'The assistant starts from the site, Brand Brief, existing articles, and business facts. The result will appear here as a strategy and editable calendar—not as a wall of chat.'}
                </p>
            </CardContent>
        </Card>
    );
}

function StrategyArtifact({ plan }: { plan: Plan }) {
    const [open, setOpen] = useState(false);
    const strategy = plan.strategy ?? {};
    const facts = strategy.site_facts ?? [];
    const assumptions = strategy.assumptions ?? [];
    const objectives = strategy.objectives ?? [];
    const pillars = strategy.pillars ?? [];
    const roles = strategy.channel_roles ?? {};
    const mixFindings = strategy.mix_findings ?? [];
    const month = new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${plan.month}-01T00:00:00Z`));

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <section
                className={`${workspacePanelClass} shrink-0 overflow-hidden`}
            >
                <button
                    type="button"
                    className="w-full px-5 py-4 text-left transition-colors hover:bg-muted/25 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                    aria-haspopup="dialog"
                    onClick={() => setOpen(true)}
                >
                    <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <p className="text-sm font-semibold">
                                    Strategy
                                </p>
                                <Badge
                                    variant="secondary"
                                    className="rounded-full"
                                >
                                    v{plan.version}
                                </Badge>
                            </div>
                            <p className="mt-1 line-clamp-2 text-sm leading-relaxed text-muted-foreground">
                                {plan.summary}
                            </p>
                        </div>
                        <Maximize2
                            className="mt-1 size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                </button>
            </section>

            <DialogContent className="flex h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none flex-col gap-0 overflow-hidden p-0 sm:max-w-none">
                <DialogHeader className="shrink-0 border-b px-6 py-5 pr-14 sm:px-8 sm:py-6 sm:pr-16">
                    <div className="flex flex-wrap items-center gap-2">
                        <DialogTitle className="text-xl tracking-tight sm:text-2xl">
                            Content strategy · {month}
                        </DialogTitle>
                        <Badge variant="secondary" className="rounded-full">
                            Version {plan.version}
                        </Badge>
                    </div>
                    <DialogDescription className="max-w-4xl text-sm leading-relaxed sm:text-base">
                        {plan.summary ||
                            'The assistant has not written a strategy summary yet.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <div className="mx-auto grid w-full max-w-6xl gap-8 px-6 py-7 sm:px-8 sm:py-9">
                        {/*
                          A month that stayed unbalanced after two asks is
                          proposed anyway, so this is the only place an operator
                          finds out. Storing the finding and not showing it
                          would be the silent machine the engine argues against.
                        */}
                        {mixFindings.length > 0 && (
                            <section className="rounded-2xl border border-amber-500/40 bg-amber-500/5 p-4">
                                <SectionLabel>Unbalanced month</SectionLabel>
                                <ul className="mt-3 grid gap-2 text-sm leading-relaxed">
                                    {mixFindings.map((finding) => (
                                        <li key={finding}>{finding}</li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {(objectives.length > 0 || pillars.length > 0) && (
                            <div className="grid gap-8 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
                                {objectives.length > 0 && (
                                    <section>
                                        <SectionLabel>Objectives</SectionLabel>
                                        <ol className="mt-4 grid gap-3">
                                            {objectives.map(
                                                (objective, index) => (
                                                    <li
                                                        key={objective}
                                                        className="grid grid-cols-[2rem_minmax(0,1fr)] gap-3 text-sm leading-relaxed"
                                                    >
                                                        <span className="flex size-8 items-center justify-center rounded-full bg-violet-500/10 text-xs font-semibold text-violet-700 dark:text-violet-300">
                                                            {index + 1}
                                                        </span>
                                                        <span className="pt-1.5">
                                                            {objective}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    </section>
                                )}

                                {pillars.length > 0 && (
                                    <section>
                                        <SectionLabel>
                                            Editorial pillars
                                        </SectionLabel>
                                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                            {pillars.map((pillar) => (
                                                <div
                                                    key={pillar.name}
                                                    className="rounded-2xl border bg-muted/20 p-4"
                                                >
                                                    <p className="font-medium">
                                                        {pillar.name}
                                                    </p>
                                                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                                        {pillar.purpose}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                )}
                            </div>
                        )}

                        <section className="border-t pt-7">
                            <SectionLabel>Channel jobs</SectionLabel>
                            <div className="mt-4 grid gap-3 md:grid-cols-3">
                                {(['threads', 'x', 'instagram'] as const).map(
                                    (channel) => (
                                        <div
                                            key={channel}
                                            className="rounded-2xl border bg-background p-4"
                                        >
                                            <ChannelLabel channel={channel} />
                                            <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                                                {roles[channel] ||
                                                    'No distinct role proposed yet.'}
                                            </p>
                                        </div>
                                    ),
                                )}
                            </div>
                        </section>

                        {(facts.length > 0 || assumptions.length > 0) && (
                            <div className="grid gap-8 border-t pt-7 lg:grid-cols-2">
                                {facts.length > 0 && (
                                    <section>
                                        <SectionLabel>
                                            Known from the project
                                        </SectionLabel>
                                        <ul className="mt-4 grid gap-3">
                                            {facts.map((fact) => (
                                                <li
                                                    key={fact.claim}
                                                    className="rounded-2xl border bg-background p-4"
                                                >
                                                    <p className="text-sm leading-relaxed">
                                                        {fact.claim}
                                                    </p>
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        {fact.source}
                                                    </p>
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                )}

                                {assumptions.length > 0 && (
                                    <section>
                                        <SectionLabel>
                                            Assumptions to validate
                                        </SectionLabel>
                                        <ul className="mt-4 grid gap-3">
                                            {assumptions.map((assumption) => (
                                                <li
                                                    key={assumption}
                                                    className="rounded-2xl border border-dashed bg-muted/15 p-4 text-sm leading-relaxed text-muted-foreground"
                                                >
                                                    {assumption}
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function PlanActions({
    plan,
    busy,
    operationStatus,
    hasMissingDrafts,
    onAccept,
    onGenerate,
}: {
    plan: Plan;
    busy: Busy;
    operationStatus: Operation['status'] | null;
    hasMissingDrafts: boolean;
    onAccept: () => void;
    onGenerate: () => void;
}) {
    const generated = plan.ideas.reduce(
        (count, idea) => count + idea.drafts.length,
        0,
    );

    return (
        <section
            className={`${workspacePanelClass} flex shrink-0 flex-col gap-4 px-5 py-4`}
            aria-label="Proposal actions"
        >
            <div className="flex items-center gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                    <BookOpenCheck className="size-4" aria-hidden="true" />
                </span>
                <div>
                    <p className="text-sm font-medium">
                        {plan.accepted
                            ? 'This version is the active content system'
                            : generated > 0
                              ? 'Starter drafts are previews — accept to continue'
                              : 'Review or refine before generating copy'}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {generated} drafts · {plan.ideas.length} ideas · nothing
                        publishes from this screen
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap gap-2 sm:pl-[3.25rem]">
                {!plan.accepted && (
                    <Button
                        variant="outline"
                        disabled={busy !== null}
                        onClick={onAccept}
                    >
                        <Check className="size-4" aria-hidden="true" />
                        {busy === 'accepting' ? 'Accepting…' : 'Use this plan'}
                    </Button>
                )}
                <Button
                    disabled={
                        !plan.accepted || busy !== null || !hasMissingDrafts
                    }
                    onClick={onGenerate}
                >
                    <WandSparkles className="size-4" aria-hidden="true" />
                    {busy === 'generating'
                        ? operationStatus === 'pending'
                            ? 'Queued…'
                            : 'Generating…'
                        : hasMissingDrafts
                          ? 'Generate next week'
                          : 'All batches drafted'}
                </Button>
            </div>
        </section>
    );
}

const STUDIO_WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * What a draft's media controls hand back when they have changed something.
 *
 * Threaded rather than synced from props: writing server props into state from
 * an effect is the pattern React now warns about, and it would also fight the
 * poll below for ownership of the same two values. One owner, told directly.
 */
type OnRefreshed = (plan: Plan, operation: Operation | null) => void;

function IdeasPanel({
    plan,
    onRefreshed,
}: {
    plan: Plan;
    onRefreshed: OnRefreshed;
}) {
    const [open, setOpen] = useState(false);
    const [selectedIdea, setSelectedIdea] = useState<Idea | null>(null);
    const ideas = plan.ideas;
    const month = new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${plan.month.slice(0, 7)}-01T00:00:00Z`));

    const closeArtifact = (nextOpen: boolean) => {
        setOpen(nextOpen);

        if (!nextOpen) {
            setSelectedIdea(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={closeArtifact}>
            <section
                className={`${workspacePanelClass} shrink-0 overflow-hidden`}
            >
                <button
                    type="button"
                    className="flex w-full items-center justify-between gap-3 border-b px-5 py-4 text-left transition-colors hover:bg-muted/25 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                    aria-haspopup="dialog"
                    onClick={() => setOpen(true)}
                >
                    <span className="flex min-w-0 items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:text-orange-300">
                            <CalendarDays
                                className="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">
                                Content map
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {ideas.length} ideas · open the monthly view
                            </span>
                        </span>
                    </span>
                    <Maximize2
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </button>
                <div className="grid gap-2 p-3">
                    {ideas.map((idea) => (
                        <IdeaCard
                            key={idea.id}
                            idea={idea}
                            onRefreshed={onRefreshed}
                        />
                    ))}
                </div>
            </section>

            <DialogContent className="flex h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none flex-col gap-0 overflow-hidden p-0 sm:max-w-none">
                <DialogHeader className="shrink-0 border-b px-6 py-5 pr-14 sm:px-8 sm:py-6 sm:pr-16">
                    <div className="flex flex-wrap items-center gap-2">
                        <DialogTitle className="text-xl tracking-tight sm:text-2xl">
                            Content plan · {month}
                        </DialogTitle>
                        <Badge variant="secondary" className="rounded-full">
                            {ideas.length} ideas
                        </Badge>
                    </div>
                    <DialogDescription className="max-w-4xl text-sm leading-relaxed sm:text-base">
                        Scan the month by date, topic and draft readiness. Open
                        an idea to inspect its evidence and native channel
                        versions.
                    </DialogDescription>
                </DialogHeader>

                <div className="relative min-h-0 flex-1 overflow-hidden">
                    <ContentCalendar
                        plan={plan}
                        selectedIdeaId={selectedIdea?.id ?? null}
                        onSelect={setSelectedIdea}
                    />
                    {selectedIdea !== null && (
                        <IdeaInspector
                            idea={selectedIdea}
                            onClose={() => setSelectedIdea(null)}
                            onRefreshed={onRefreshed}
                        />
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function ContentCalendar({
    plan,
    selectedIdeaId,
    onSelect,
}: {
    plan: Plan;
    selectedIdeaId: string | null;
    onSelect: (idea: Idea) => void;
}) {
    const [year, month] = plan.month
        .slice(0, 7)
        .split('-')
        .map((value) => Number(value));
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const startsOn =
        (new Date(Date.UTC(year, month - 1, 1)).getUTCDay() + 6) % 7;
    const trailing = (7 - ((startsOn + daysInMonth) % 7)) % 7;
    const byDay = new Map<number, Idea[]>();

    for (const idea of plan.ideas) {
        const day = Number(idea.date.slice(8, 10));
        byDay.set(day, [...(byDay.get(day) ?? []), idea]);
    }

    return (
        <div className="size-full overflow-auto overscroll-contain">
            <div className="hidden min-w-[68rem] p-6 sm:block lg:p-8">
                <div className="grid grid-cols-7 overflow-hidden rounded-t-2xl border border-b-0 bg-muted/20 text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                    {STUDIO_WEEKDAYS.map((weekday) => (
                        <div key={weekday} className="px-3 py-2.5">
                            {weekday}
                        </div>
                    ))}
                </div>
                <div className="grid grid-cols-7 overflow-hidden rounded-b-2xl border-t border-l bg-background/35">
                    {Array.from({ length: startsOn }).map((_, index) => (
                        <div
                            key={`leading-${index}`}
                            className="min-h-36 border-r border-b bg-muted/20"
                        />
                    ))}
                    {Array.from({ length: daysInMonth }).map((_, index) => {
                        const day = index + 1;
                        const dayIdeas = byDay.get(day) ?? [];

                        return (
                            <div
                                key={day}
                                className="flex min-h-36 min-w-0 flex-col gap-2 border-r border-b p-2.5"
                            >
                                <span className="text-xs font-medium text-muted-foreground">
                                    {day}
                                </span>
                                {dayIdeas.map((idea) => (
                                    <CalendarIdea
                                        key={idea.id}
                                        idea={idea}
                                        selected={idea.id === selectedIdeaId}
                                        onSelect={onSelect}
                                    />
                                ))}
                            </div>
                        );
                    })}
                    {Array.from({ length: trailing }).map((_, index) => (
                        <div
                            key={`trailing-${index}`}
                            className="min-h-36 border-r border-b bg-muted/20"
                        />
                    ))}
                </div>
            </div>

            <div className="grid gap-3 p-4 sm:hidden">
                {plan.ideas.map((idea) => (
                    <CalendarIdea
                        key={idea.id}
                        idea={idea}
                        selected={idea.id === selectedIdeaId}
                        onSelect={onSelect}
                        agenda
                    />
                ))}
            </div>
        </div>
    );
}

function CalendarIdea({
    idea,
    selected,
    onSelect,
    agenda = false,
}: {
    idea: Idea;
    selected: boolean;
    onSelect: (idea: Idea) => void;
    agenda?: boolean;
}) {
    const date = new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${idea.date}T00:00:00Z`));

    return (
        <button
            type="button"
            className={`min-w-0 rounded-xl border p-2.5 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-violet-500/40 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                selected
                    ? 'border-violet-500/60 bg-violet-500/10'
                    : 'bg-card/90'
            } ${agenda ? 'w-full p-4' : ''}`}
            aria-pressed={selected}
            onClick={() => onSelect(idea)}
        >
            {agenda && (
                <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {date}
                </span>
            )}
            <span
                className={`${agenda ? 'mt-2 text-sm' : 'text-xs'} line-clamp-3 block leading-snug font-medium`}
            >
                {idea.title}
            </span>
            <span className="mt-2 flex min-w-0 items-center gap-1 text-[10px] text-muted-foreground">
                <ImageIcon className="size-3 shrink-0" aria-hidden="true" />
                <span className="truncate">{productionSummary(idea)}</span>
            </span>
            <span className="mt-2 flex min-w-0 items-center justify-between gap-2">
                <span className="truncate text-[10px] font-medium text-muted-foreground">
                    {idea.kind_label}
                </span>
                <span className="shrink-0 text-[10px] font-medium text-muted-foreground">
                    {idea.drafts.length}/{idea.channels.length}
                </span>
            </span>
        </button>
    );
}

function ChannelApproach({ idea }: { idea: Idea }) {
    const { channelAngles, sharedAngle } = splitChannelAngles(idea.angle);

    return (
        <section>
            <SectionLabel>Channel approach</SectionLabel>
            {sharedAngle && (
                <div className="mt-3 rounded-2xl border bg-muted/15 p-4">
                    <p className="text-xs font-medium text-foreground">
                        Shared direction
                    </p>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {sharedAngle}
                    </p>
                </div>
            )}
            <div className="mt-3 grid gap-2.5">
                {idea.channels.map((channel) => {
                    const shape = idea.production?.[channel] ?? {
                        format: 'post',
                        visual: 'none',
                    };
                    const channelAngle = channelAngles[channel];

                    return (
                        <div
                            key={channel}
                            className="rounded-2xl border bg-background p-4"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <ChannelLabel channel={channel} />
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <Badge
                                        variant="secondary"
                                        className="rounded-full text-[10px]"
                                    >
                                        {productionFormatLabel(shape.format)}
                                    </Badge>
                                    <Badge
                                        variant="outline"
                                        className="rounded-full text-[10px]"
                                    >
                                        <ImageIcon
                                            className="mr-1 size-3"
                                            aria-hidden="true"
                                        />
                                        {productionVisualLabel(shape.visual)}
                                    </Badge>
                                </div>
                            </div>
                            {channelAngle && (
                                <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                                    {channelAngle}
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

function splitChannelAngles(angle: string | null): {
    channelAngles: Record<string, string>;
    sharedAngle: string | null;
} {
    const value = angle?.trim() ?? '';

    if (value === '') {
        return { channelAngles: {}, sharedAngle: null };
    }

    const matches = [...value.matchAll(/(?:^|\s)(Threads|Instagram|X):\s*/g)];

    if (matches.length === 0) {
        return { channelAngles: {}, sharedAngle: value };
    }

    const channelAngles: Record<string, string> = {};

    matches.forEach((match, index) => {
        const start = (match.index ?? 0) + match[0].length;
        const end = matches[index + 1]?.index ?? value.length;
        const channel = match[1].toLowerCase();
        const text = value.slice(start, end).trim();

        if (text !== '') {
            channelAngles[channel] = text;
        }
    });

    return { channelAngles, sharedAngle: null };
}

function productionFormatLabel(format: string): string {
    return (
        {
            post: 'Post',
            post_or_thread: 'Post / thread',
            image_post: 'Image post',
            carousel: 'Carousel',
        }[format] ?? format.replaceAll('_', ' ')
    );
}

function productionVisualLabel(visual: string): string {
    return (
        {
            image: 'Image',
            slides: 'Slides',
            none: 'Text only',
        }[visual] ?? visual.replaceAll('_', ' ')
    );
}

function productionSummary(idea: Idea): string {
    const shapes = Object.values(idea.production ?? {});
    const images = shapes.filter((shape) => shape.visual === 'image').length;
    const carousels = shapes.filter(
        (shape) => shape.visual === 'slides',
    ).length;
    const parts: string[] = [];

    if (images > 0) {
        parts.push(`${images} ${images === 1 ? 'image' : 'images'}`);
    }

    if (carousels > 0) {
        parts.push(
            `${carousels} ${carousels === 1 ? 'carousel' : 'carousels'}`,
        );
    }

    return parts.length > 0 ? parts.join(' · ') : 'Text only';
}

function IdeaInspector({
    idea,
    onClose,
    onRefreshed,
}: {
    idea: Idea;
    onClose: () => void;
    onRefreshed: OnRefreshed;
}) {
    const date = new Intl.DateTimeFormat(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${idea.date}T00:00:00Z`));

    return (
        <aside
            className="absolute inset-y-0 right-0 z-10 w-full max-w-md overflow-y-auto border-l bg-background shadow-[-24px_0_64px_rgba(0,0,0,0.28)]"
            aria-label={`Idea details: ${idea.title}`}
        >
            <div className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b bg-background/95 px-5 py-4 backdrop-blur">
                <div className="min-w-0">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {date}
                    </p>
                    <p className="mt-1 truncate text-sm font-semibold">
                        Idea inspector
                    </p>
                </div>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="shrink-0 rounded-full"
                    aria-label="Close idea inspector"
                    onClick={onClose}
                >
                    <X className="size-4" aria-hidden="true" />
                </Button>
            </div>

            <div className="grid gap-5 p-5">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        {/*
                          What the post is, beside what it is about. The kind
                          decides the channels and the Instagram format, so an
                          operator reading a two-channel idea needs it to make
                          sense of the row.
                        */}
                        <Badge className="rounded-full">
                            {idea.kind_label}
                        </Badge>
                        <Badge variant="secondary" className="rounded-full">
                            {idea.pillar}
                        </Badge>
                        <Badge variant="outline" className="rounded-full">
                            {idea.drafts.length}/{idea.channels.length} drafts
                        </Badge>
                    </div>
                    <h3 className="mt-3 text-xl leading-tight font-semibold tracking-tight">
                        {idea.title}
                    </h3>
                    <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                        {idea.thesis}
                    </p>
                </div>

                <ChannelApproach idea={idea} />

                {idea.evidence.length > 0 && (
                    <section className="rounded-2xl border bg-muted/15 p-4">
                        <SectionLabel>Evidence</SectionLabel>
                        <ul className="mt-2 grid gap-2 text-sm leading-relaxed text-muted-foreground">
                            {idea.evidence.map((evidence) => (
                                <li key={evidence}>{evidence}</li>
                            ))}
                        </ul>
                    </section>
                )}

                {idea.drafts.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-5 text-center text-sm text-muted-foreground">
                        Native Threads, X and Instagram drafts will appear here
                        when this batch is generated.
                    </p>
                ) : (
                    <div className="grid gap-3">
                        {idea.drafts.map((draft) => (
                            <DraftPreview
                                key={draft.id}
                                draft={draft}
                                onRefreshed={onRefreshed}
                            />
                        ))}
                    </div>
                )}
            </div>
        </aside>
    );
}

function IdeaCard({
    idea,
    onRefreshed,
}: {
    idea: Idea;
    onRefreshed: OnRefreshed;
}) {
    const [open, setOpen] = useState(idea.drafts.length > 0);
    const date = new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${idea.date}T00:00:00Z`));

    return (
        <article className="overflow-hidden rounded-xl border bg-background/65">
            <button
                type="button"
                className="grid w-full min-w-0 gap-3 p-4 text-left sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
                onClick={() => setOpen((current) => !current)}
                aria-expanded={open}
            >
                <div className="min-w-0">
                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            {date}
                        </p>
                        {idea.pillar && (
                            <Badge
                                variant="secondary"
                                className="max-w-full rounded-full font-normal whitespace-normal"
                            >
                                <span className="truncate">{idea.pillar}</span>
                            </Badge>
                        )}
                    </div>
                    <h3 className="mt-2 leading-snug font-medium tracking-tight">
                        {idea.title}
                    </h3>
                    <p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-muted-foreground">
                        {idea.thesis}
                    </p>
                    <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <ImageIcon
                            className="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {productionSummary(idea)}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-1.5 sm:hidden">
                        {idea.channels.map((channel) => (
                            <ChannelBadge
                                key={channel}
                                channel={channel}
                                drafted={idea.drafts.some(
                                    (draft) => draft.channel === channel,
                                )}
                            />
                        ))}
                    </div>
                </div>
                <div className="hidden items-center gap-2 sm:flex">
                    <Badge variant="outline" className="rounded-full">
                        {idea.drafts.length}/{idea.channels.length} drafts
                    </Badge>
                    <ChevronDown
                        className={`size-4 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`}
                        aria-hidden="true"
                    />
                </div>
            </button>

            {open && (
                <div className="grid gap-4 border-t bg-muted/10 p-4">
                    <ChannelApproach idea={idea} />

                    {idea.evidence.length > 0 && (
                        <div className="rounded-xl border bg-background/70 p-3.5 text-xs text-muted-foreground">
                            <SectionLabel>Evidence</SectionLabel>
                            <ul className="mt-2 grid gap-1.5 leading-relaxed">
                                {idea.evidence.map((evidence) => (
                                    <li key={evidence}>{evidence}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {idea.drafts.length === 0 ? (
                        <p className="rounded-xl border border-dashed px-4 py-5 text-center text-xs text-muted-foreground">
                            Planned, not written yet. Native Threads, X and
                            Instagram versions appear here when this batch is
                            generated.
                        </p>
                    ) : (
                        <div className="grid gap-3">
                            {idea.drafts.map((draft) => (
                                <DraftPreview
                                    key={draft.id}
                                    draft={draft}
                                    onRefreshed={onRefreshed}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </article>
    );
}

/**
 * The picture controls on a draft.
 *
 * Until these existed a draft's first picture was its only picture: the
 * generator drew one and the reviewer had no way to disagree with it that did
 * not involve deleting rows. The three things here are the three things a
 * person actually does — look at alternatives, say what is wrong, and pick one.
 *
 * The note is a note about the photograph, not a prompt. It goes to an art
 * director that rewrites the stored brief, so what accumulates on the draft is
 * an edited description rather than a pile of appended complaints.
 */
function DraftMedia({
    draft,
    onRefreshed,
}: {
    draft: Draft;
    onRefreshed: OnRefreshed;
}) {
    const [note, setNote] = useState('');
    const [pending, setPending] = useState<
        'drawing' | 'choosing' | 'uploading' | null
    >(null);
    const variants = draft.assets.filter((asset) => !asset.chosen);
    const notes = Array.isArray(draft.payload?.visual_notes)
        ? (draft.payload.visual_notes as Array<{ said: string }>)
        : [];

    async function draw(count: number) {
        setPending('drawing');

        const result = await postJson<unknown>(revise(draft.id).url, {
            instruction: note.trim() === '' ? null : note.trim(),
            variants: count,
        });

        setPending(null);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        setNote('');
        // The page polls while an operation is active, so handing the fresh
        // state back is what makes the pictures appear when the provider is
        // done rather than on the next manual reload.
        router.reload({
            only: ['plan', 'operation'],
            onSuccess: (page) => {
                const nextPlan = page.props.plan as Plan | null;

                if (nextPlan !== null) {
                    onRefreshed(
                        nextPlan,
                        page.props.operation as Operation | null,
                    );
                }
            },
        });
    }

    async function attach(file: File) {
        setPending('uploading');

        const body = new FormData();
        body.append('photo', file);

        const response = await fetch(upload(draft.id).url, {
            method: 'POST',
            body,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        setPending(null);

        if (!response.ok) {
            const problem = (await response.json().catch(() => null)) as {
                message?: string;
            } | null;

            toast.error(
                problem?.message ?? 'That photograph could not be used.',
            );

            return;
        }

        router.reload({
            only: ['plan'],
            onSuccess: (page) => {
                const nextPlan = page.props.plan as Plan | null;

                if (nextPlan !== null) {
                    onRefreshed(nextPlan, null);
                }
            },
        });
    }

    async function pick(assetId: string) {
        setPending('choosing');

        const result = await postJson<unknown>(
            choose([draft.id, assetId]).url,
            {},
        );

        setPending(null);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        router.reload({
            only: ['plan'],
            onSuccess: (page) => {
                const nextPlan = page.props.plan as Plan | null;

                if (nextPlan !== null) {
                    onRefreshed(nextPlan, null);
                }
            },
        });
    }

    return (
        <div className="mt-3 grid gap-2 border-t pt-3">
            {variants.length > 0 && (
                <div className="grid gap-1.5">
                    <SectionLabel>Other takes</SectionLabel>
                    <div className="flex gap-2 overflow-x-auto pb-1">
                        {variants.map((variant) => (
                            <button
                                key={variant.id}
                                type="button"
                                disabled={pending !== null}
                                onClick={() => pick(variant.id)}
                                title={
                                    variant.retired
                                        ? 'Previously used — put it back'
                                        : 'Use this one'
                                }
                                className="relative shrink-0 overflow-hidden rounded-md border transition hover:ring-2 hover:ring-primary disabled:opacity-50"
                            >
                                <img
                                    src={variant.url}
                                    alt={variant.alt}
                                    className="h-20 w-auto object-cover"
                                />
                                {variant.source === 'uploaded' && (
                                    <span className="absolute inset-x-0 top-0 bg-primary/90 py-0.5 text-center text-[9px] text-primary-foreground">
                                        photo
                                    </span>
                                )}
                                {variant.retired && (
                                    <span className="absolute inset-x-0 bottom-0 bg-background/80 py-0.5 text-center text-[9px]">
                                        was used
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <Textarea
                value={note}
                onChange={(event) => setNote(event.target.value)}
                placeholder="What is wrong with this picture? e.g. too clean, show the actual residue"
                rows={2}
                className="text-xs"
                disabled={pending !== null}
            />

            <div className="flex flex-wrap items-center gap-2">
                {/*
                  First, and not by accident. A real photograph beats anything
                  the provider can draw and costs nothing, so it is the option
                  an operator should reach for before the two that spend money.
                */}
                <label className="inline-flex">
                    <input
                        type="file"
                        accept="image/*"
                        className="sr-only"
                        disabled={pending !== null}
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            event.target.value = '';

                            if (file) {
                                void attach(file);
                            }
                        }}
                    />
                    <span className="inline-flex h-8 cursor-pointer items-center rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent">
                        {pending === 'uploading'
                            ? 'Uploading…'
                            : 'Use a real photo'}
                    </span>
                </label>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={pending !== null}
                    onClick={() => draw(1)}
                >
                    {pending === 'drawing' ? 'Drawing…' : 'Draw another'}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={pending !== null}
                    onClick={() => draw(3)}
                >
                    Three to choose from
                </Button>
                {/* Said before it is spent, not after. */}
                <span className="text-[10px] text-muted-foreground">
                    each picture is a paid generation
                </span>
            </div>

            {notes.length > 0 && (
                <ul className="grid gap-1 text-[11px] leading-relaxed text-muted-foreground">
                    {notes.map((entry, index) => (
                        <li key={`${entry.said}-${index}`}>· {entry.said}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function DraftPreview({
    draft,
    onRefreshed,
}: {
    draft: Draft;
    onRefreshed: OnRefreshed;
}) {
    const asset = draft.assets?.find((candidate) => candidate.chosen);
    const format =
        typeof draft.payload?.format === 'string'
            ? draft.payload.format
            : 'post';
    const guardFindings = Array.isArray(draft.payload?.guard_findings)
        ? (draft.payload.guard_findings as Array<{
              code: string;
              detail: string;
          }>)
        : [];
    const factCheck = draft.payload?.fact_check as
        { passed: boolean; findings: string[] } | undefined;

    return (
        <article className="min-w-0 overflow-hidden rounded-xl border bg-background">
            {asset && (
                // The asset's own shape, not a fixed 1.91:1 box. Each channel
                // now renders its own crop — 4:5 on Instagram, square on
                // Threads — and cropping them all back to an Open Graph strip
                // hid the one thing this screen exists to let an operator check.
                <div className="overflow-hidden border-b bg-muted">
                    <img
                        src={asset.url}
                        alt={asset.alt}
                        width={asset.width ?? undefined}
                        height={asset.height ?? undefined}
                        className="h-auto w-full object-contain"
                    />
                </div>
            )}
            <div className="p-3.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <ChannelLabel channel={draft.channel} />
                    <div className="flex items-center gap-1.5">
                        {asset && (
                            <Badge
                                variant="outline"
                                className="rounded-full text-[10px]"
                            >
                                <ImageIcon
                                    className="mr-1 size-3"
                                    aria-hidden="true"
                                />
                                visual
                            </Badge>
                        )}
                        <Badge
                            variant="secondary"
                            className="rounded-full text-[10px] capitalize"
                        >
                            {format}
                        </Badge>
                    </div>
                </div>
                <p className="mt-3 max-h-52 overflow-y-auto text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                    {draft.body}
                </p>

                {/*
                  The refusals and the fact-check verdict, which were written
                  into the payload and shown nowhere. The engine ships a flawed
                  draft on the argument that an operator can fix what they can
                  see — an argument that only holds if they can see it.
                */}
                {factCheck && !factCheck.passed && (
                    <div className="mt-3 rounded-lg border border-red-500/40 bg-red-500/5 p-2.5">
                        <p className="text-[11px] font-medium">
                            Unsupported by the business facts
                        </p>
                        <ul className="mt-1 grid gap-1 text-[11px] leading-relaxed text-muted-foreground">
                            {factCheck.findings.map((finding) => (
                                <li key={finding}>{finding}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {guardFindings.length > 0 && (
                    <div className="mt-2 rounded-lg border border-amber-500/40 bg-amber-500/5 p-2.5">
                        <p className="text-[11px] font-medium">
                            Would not publish as written
                        </p>
                        <ul className="mt-1 grid gap-1 text-[11px] leading-relaxed text-muted-foreground">
                            {guardFindings.map((finding) => (
                                <li key={finding.code}>{finding.detail}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <DraftMedia draft={draft} onRefreshed={onRefreshed} />
            </div>
        </article>
    );
}

function ChannelBadge({
    channel,
    drafted,
}: {
    channel: string;
    drafted: boolean;
}) {
    return (
        <Badge
            variant={drafted ? 'default' : 'outline'}
            className="rounded-full capitalize"
        >
            {drafted && <Check className="mr-1 size-3" aria-hidden="true" />}
            {channel}
        </Badge>
    );
}

function ChannelLabel({ channel }: { channel: string }) {
    const normalizedChannel = channel.toLowerCase();

    const icon =
        normalizedChannel === 'instagram' ? (
            <Instagram
                className="size-3.5 text-foreground"
                aria-hidden="true"
            />
        ) : normalizedChannel === 'x' ? (
            <span
                className="text-sm leading-none font-semibold text-foreground"
                aria-hidden="true"
            >
                𝕏
            </span>
        ) : (
            <MessageCircle
                className="size-3.5 text-foreground"
                aria-hidden="true"
            />
        );

    const label =
        normalizedChannel === 'x'
            ? 'X'
            : normalizedChannel.charAt(0).toUpperCase() +
              normalizedChannel.slice(1);

    return (
        <div className="flex items-center gap-2 text-xs font-semibold">
            {icon}
            <span className="text-muted-foreground">{label}</span>
        </div>
    );
}

function SectionLabel({ children }: { children: string }) {
    return (
        <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
            {children}
        </p>
    );
}
