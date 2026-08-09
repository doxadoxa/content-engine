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
    MessageCircle,
    Send,
    Sparkles,
    WandSparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import AlertError from '@/components/alert-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { postJson } from '@/lib/json';
import { accept, generate, index, propose, refine } from '@/routes/studio';

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
    }>;
};

type Idea = {
    id: string;
    key: string;
    date: string;
    title: string;
    pillar: string;
    thesis: string;
    evidence: string[];
    goal: string;
    audience: string;
    angle: string | null;
    channels: string[];
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
    action: 'proposal' | 'refine' | 'generate_week' | null;
    status: 'pending' | 'running' | 'failed' | 'completed' | 'cancelled';
    message: string | null;
    result: {
        version?: number;
        created?: number;
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

    if (operation?.action === 'generate_week') {
        return 'generating';
    }

    return 'proposing';
}

function notifyCompleted(operation: Operation, plan: Plan | null) {
    if (operation.action === 'refine' && plan !== null) {
        toast.success(`Proposal v${plan.version} is ready`);

        return;
    }

    if (operation.action === 'generate_week') {
        const created = operation.result?.created;

        toast.success(
            created === 0
                ? 'Every batch in this proposal is already drafted'
                : typeof created === 'number'
                  ? `${created} channel drafts created`
                  : 'The next weekly batch is ready',
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
                                <IdeasPanel ideas={readyPlan.ideas} />
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

    return (
        <section className={`${workspacePanelClass} shrink-0 overflow-hidden`}>
            <button
                type="button"
                className="w-full px-5 py-4 text-left"
                aria-expanded={open}
                onClick={() => setOpen((current) => !current)}
            >
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <p className="text-sm font-semibold">Strategy</p>
                            <Badge variant="secondary" className="rounded-full">
                                v{plan.version}
                            </Badge>
                        </div>
                        <p className="mt-1 line-clamp-2 text-sm leading-relaxed text-muted-foreground">
                            {plan.summary}
                        </p>
                    </div>
                    <ChevronDown
                        className={`mt-1 size-4 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`}
                        aria-hidden="true"
                    />
                </div>
            </button>
            {open && (
                <div className="grid gap-5 border-t px-5 py-5">
                    {objectives.length > 0 && (
                        <div>
                            <SectionLabel>Objectives</SectionLabel>
                            <ul className="mt-2 grid gap-2 text-sm leading-relaxed">
                                {objectives.map((objective) => (
                                    <li key={objective}>{objective}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {pillars.length > 0 && (
                        <div>
                            <SectionLabel>Editorial pillars</SectionLabel>
                            <div className="mt-2 grid gap-2">
                                {pillars.map((pillar) => (
                                    <div
                                        key={pillar.name}
                                        className="rounded-xl bg-muted/30 px-3.5 py-3"
                                    >
                                        <p className="text-sm font-medium">
                                            {pillar.name}
                                        </p>
                                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                            {pillar.purpose}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    <div>
                        <SectionLabel>Channel jobs</SectionLabel>
                        <div className="mt-2 grid gap-3">
                            {(['threads', 'x', 'instagram'] as const).map(
                                (channel) => (
                                    <div
                                        key={channel}
                                        className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 text-xs leading-relaxed"
                                    >
                                        <ChannelLabel channel={channel} />
                                        <p className="text-muted-foreground">
                                            {roles[channel] ||
                                                'No distinct role proposed yet.'}
                                        </p>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>

                    {(facts.length > 0 || assumptions.length > 0) && (
                        <div className="grid gap-4 border-t pt-4 text-xs leading-relaxed sm:grid-cols-2">
                            <div>
                                <SectionLabel>Known</SectionLabel>
                                <ul className="mt-2 grid gap-2 text-muted-foreground">
                                    {facts.map((fact) => (
                                        <li key={fact.claim}>{fact.claim}</li>
                                    ))}
                                </ul>
                            </div>
                            <div>
                                <SectionLabel>Assumed</SectionLabel>
                                <ul className="mt-2 grid gap-2 text-muted-foreground">
                                    {assumptions.map((assumption) => (
                                        <li key={assumption}>{assumption}</li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </section>
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

function IdeasPanel({ ideas }: { ideas: Idea[] }) {
    return (
        <section className={`${workspacePanelClass} shrink-0 overflow-hidden`}>
            <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:text-orange-300">
                        <CalendarDays className="size-4" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="text-sm font-semibold">Content map</h2>
                        <p className="text-xs text-muted-foreground">
                            {ideas.length} ideas for the month
                        </p>
                    </div>
                </div>
            </header>
            <div className="grid gap-2 p-3">
                {ideas.map((idea) => (
                    <IdeaCard key={idea.id} idea={idea} />
                ))}
            </div>
        </section>
    );
}

function IdeaCard({ idea }: { idea: Idea }) {
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
                        {idea.drafts.length}/{idea.channels.length}
                    </Badge>
                    <ChevronDown
                        className={`size-4 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`}
                        aria-hidden="true"
                    />
                </div>
            </button>

            {open && (
                <div className="grid gap-4 border-t bg-muted/10 p-4">
                    {(idea.angle || idea.evidence.length > 0) && (
                        <div className="grid gap-3 text-xs text-muted-foreground sm:grid-cols-2">
                            {idea.angle && (
                                <div className="rounded-xl border bg-background/70 p-3.5">
                                    <SectionLabel>Angle</SectionLabel>
                                    <p className="mt-2 leading-relaxed">
                                        {idea.angle}
                                    </p>
                                </div>
                            )}
                            {idea.evidence.length > 0 && (
                                <div className="rounded-xl border bg-background/70 p-3.5">
                                    <SectionLabel>Evidence</SectionLabel>
                                    <ul className="mt-2 grid gap-1.5 leading-relaxed">
                                        {idea.evidence.map((evidence) => (
                                            <li key={evidence}>{evidence}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}

                    {idea.drafts.length === 0 ? (
                        <p className="rounded-xl border border-dashed px-4 py-5 text-center text-xs text-muted-foreground">
                            Planned, not written yet. Weekly generation keeps
                            later copy open to new information.
                        </p>
                    ) : (
                        <div className="grid gap-3">
                            {idea.drafts.map((draft) => (
                                <DraftPreview key={draft.id} draft={draft} />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </article>
    );
}

function DraftPreview({ draft }: { draft: Draft }) {
    const asset = draft.assets?.[0];
    const format =
        typeof draft.payload?.format === 'string'
            ? draft.payload.format
            : 'post';

    return (
        <article className="min-w-0 overflow-hidden rounded-xl border bg-background">
            {asset && (
                <div className="aspect-[1.91/1] overflow-hidden border-b bg-muted">
                    <img
                        src={asset.url}
                        alt={asset.alt}
                        className="size-full object-cover"
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
    const icon =
        channel === 'instagram' ? (
            <Instagram className="size-3.5" aria-hidden="true" />
        ) : channel === 'threads' ? (
            <MessageCircle className="size-3.5" aria-hidden="true" />
        ) : (
            <span className="text-xs font-bold" aria-hidden="true">
                X
            </span>
        );

    return (
        <div className="flex items-center gap-2 text-xs font-semibold capitalize">
            {icon}
            {channel}
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
