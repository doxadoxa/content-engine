import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Globe2,
    Lock,
    MessageCircle,
    Send,
    Sparkles,
    WandSparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import AlertError from '@/components/alert-error';
import { SocialTabs } from '@/components/social-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { postJson } from '@/lib/json';
import {
    create as createTab,
    index as boardTab,
    plan as planRoute,
} from '@/routes/social';
import { store as storeGoal } from '@/routes/social/goal';
import { accept, generate, propose, refine } from '@/routes/studio';

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
    /** One sentence arguing that this cadence reaches this target. */
    expected_impact?: string;
};

type Goal = {
    kpi: string;
    kpi_label: string;
    unit: string;
    target: number;
    cadence: number;
    weeks: Array<{ objective: string }>;
    confirmed: boolean;
    week_of: number | null;
    total_weeks: number;
    /** Null when nothing connected can measure this KPI. */
    progress: number | null;
    /** What would make it measurable, when nothing can. */
    needs: string | null;
    /** Posts a week actually published in the four weeks before this month. */
    current_cadence: number;
    /** The target restated as a weekly rate. */
    per_week_needed: number;
};

type Kpi = {
    value: string;
    label: string;
    unit: string;
    measurable: boolean;
    requires: string;
};

type Idea = {
    id: string;
    title: string;
    channels: string[];
    drafts: Array<{ id: string }>;
};

type Message = {
    id: string;
    role: 'assistant' | 'user';
    body: string;
    version: number;
    created_at: string | null;
};

type Plan = {
    id: string;
    month: string;
    summary: string | null;
    strategy: Strategy;
    version: number;
    accepted: boolean;
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
    idea_id: string | null;
    result: { version?: number; created?: number; ideas?: number } | null;
};

type Props = {
    month: string;
    label: string;
    previous: string;
    next: string;
    source: Source;
    plan: Plan | null;
    goal: Goal | null;
    kpis: Kpi[];
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

    if (operation.action === 'generate_week') {
        const ideas = operation.result?.ideas;

        toast.success(
            ideas === 0
                ? 'Every idea in this proposal is already written'
                : typeof ideas === 'number'
                  ? `Writing ${ideas} ${ideas === 1 ? 'idea' : 'ideas'}…`
                  : 'The next batch is on its way',
        );

        return;
    }

    if (operation.action === 'generate_idea') {
        toast.success('An idea finished writing');

        return;
    }

    toast.success('The monthly proposal is ready');
}

/**
 * The month's argument, and the conversation that changes it.
 *
 * **One column, and much less of it than there used to be.** This screen was
 * the whole product for a release: a chat beside a narrow rail carrying the
 * strategy, every idea, every draft, the picture controls and a calendar in a
 * modal. Overview and Create now do three of those five better — the board owns
 * the month's state, Create owns picking something to make, and the composer
 * owns a draft and its pictures — so keeping them here was not a second view,
 * it was a second implementation.
 *
 * What is left is what only this tab can do: propose a month, argue with it, and
 * accept a version. The ideas it produced are linked to rather than listed,
 * because a list of nineteen in a 22rem rail is not a way to read anything.
 *
 * **Ordered as a decision, not as a document.** What survived the cut above was
 * still arranged the way the assistant thinks — objectives, pillars, channel
 * roles, the facts it found, the assumptions it made — which is the reasoning
 * behind the month and not the month. A reader had to get through all of it
 * before reaching anything they could agree or disagree with, and there was no
 * number anywhere to disagree *with*.
 *
 * So the order is now: what this month is aiming at, why that is thought
 * reachable, what it will feel like week by week, and one button. The reasoning
 * is kept — it is the best thing this engine produces — but folded, because it
 * answers "how did you get here", and that is a question you ask after the
 * claim, not before it.
 */
export default function SocialPlan({
    month,
    label,
    previous,
    next,
    source,
    plan: initialPlan,
    goal: initialGoal,
    kpis,
    operation: initialOperation,
}: Props) {
    const [plan, setPlan] = useState(initialPlan);
    const [goal, setGoal] = useState(initialGoal);
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
                // The goal travels with the plan: a proposal writes one, so a
                // poll that refreshed the month but not what it is for would
                // land the finished strategy above an empty set of numbers.
                only: ['plan', 'goal', 'operation'],
                onSuccess: (page) => {
                    if (cancelled) {
                        return;
                    }

                    const serverPlan = page.props.plan as Plan | null;
                    const serverOperation = page.props
                        .operation as Operation | null;

                    if (serverPlan !== null && serverPlan.version > 0) {
                        setPlan(serverPlan);
                        setGoal(page.props.goal as Goal | null);
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
                                'The plan operation failed.',
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

        const result = await postJson<{ plan: Plan; goal: Goal | null }>(
            accept(plan.id).url,
            { version: plan.version },
        );

        setBusy(null);

        if (!result.ok) {
            setError(result.message);

            return;
        }

        setPlan(result.data.plan);
        // Accepting confirmed the goal too. Reading it back rather than
        // flipping a local flag, because the server decides whether a goal
        // confirmed weeks ago keeps its original date.
        setGoal(result.data.goal);
        toast.success('This version is the month you are working from');
    }

    /**
     * Overrule the proposed numbers.
     *
     * A full Inertia visit rather than the JSON post the rest of this screen
     * uses, because the endpoint is shared with a plain form and answers with a
     * redirect. The fresh props are read out of the response instead of being
     * waited for on the next render: this component holds the plan in state, so
     * a visit that quietly replaced the props would leave the two disagreeing.
     */
    function adjustGoal(form: HTMLFormElement) {
        const data = new FormData(form);

        setError(null);

        router.post(
            storeGoal().url,
            {
                month,
                kpi: String(data.get('kpi') ?? ''),
                target: Number(data.get('target') ?? 0),
                cadence: Number(data.get('cadence') ?? 0),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => setGoal(page.props.goal as Goal | null),
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'Those numbers could not be saved.',
                    ),
            },
        );
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
            <Head title={`Plan — ${label}`} />

            {/*
                The same width as Overview and Create, because they are three
                tabs of one surface and a tab strip that reflows the page under
                you reads as a navigation, not as a switch.

                It used to ask for `reading` to keep the summary legible. That
                was the wrong lever — `reading` is 1280px, still far past a
                measure — and the prose now carries its own cap, so the page can
                be as wide as its siblings without the paragraph following it.
            */}
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Social"
                    context={
                        readyPlan === null
                            ? 'No proposal yet'
                            : readyPlan.accepted
                              ? `Version ${readyPlan.version} · in use`
                              : `Version ${readyPlan.version} · not accepted`
                    }
                    title={label}
                    description="Set this month’s goal and strategy. Create drafts on Create and track progress on Overview."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Previous month"
                                className="rounded-full bg-background/70"
                                onClick={() =>
                                    router.get(
                                        planRoute({
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
                                        planRoute({ query: { month: next } }),
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

                <SocialTabs current="plan" month={month} />

                {error && <AlertError errors={[error]} />}

                {readyPlan === null ? (
                    <ProposalLoading
                        source={source}
                        busy={busy}
                        operationStatus={operation?.status ?? null}
                        proposalFailed={error !== null}
                        onBuild={() => void startProposal()}
                    />
                ) : (
                    <>
                        <GoalPanel
                            goal={goal}
                            kpis={kpis}
                            impact={readyPlan.strategy?.expected_impact ?? ''}
                            onAdjust={adjustGoal}
                        />
                        <MonthPanel plan={readyPlan} goal={goal} />
                        <Decision
                            plan={readyPlan}
                            month={month}
                            busy={busy}
                            onAccept={() => void acceptProposal()}
                            onGenerate={() => void generateBatch()}
                        />
                        <Reasoning plan={readyPlan} />
                    </>
                )}

                <Conversation
                    source={source}
                    plan={readyPlan}
                    busy={busy}
                    operationStatus={operation?.status ?? null}
                    message={message}
                    onMessage={setMessage}
                    onSend={() => void sendRefinement()}
                />
            </WorkspacePage>
        </>
    );
}

/**
 * What the month is aiming at, in numbers, above everything else.
 *
 * The first screenful, and the change this rewrite is really about. A month
 * described only in prose can be read but not judged: "build authority with
 * Lisbon homeowners" is agreeable and unfalsifiable, so accepting it was a
 * decision with nothing riding on it. Three figures with a *currently* beside
 * each turn the same proposal into a claim somebody can call optimistic.
 *
 * The third tile is derived and is the one that does the work. A target is an
 * abstraction — nobody has an instinct for whether 340 followers in a month is
 * ambitious — but "about 85 a week" is a rate a person recognises as reasonable
 * or absurd immediately, which is the judgement the button below is asking for.
 *
 * The KPI figure shows its precondition instead of a number when nothing
 * connected can report it, for the reason `SocialKpi` gives at length: a
 * confident zero beside a target is worse than an admitted gap.
 */
function GoalPanel({
    goal,
    kpis,
    impact,
    onAdjust,
}: {
    goal: Goal | null;
    kpis: Kpi[];
    impact: string;
    onAdjust: (form: HTMLFormElement) => void;
}) {
    if (goal === null) {
        return <UnsizedMonth kpis={kpis} onAdjust={onAdjust} />;
    }

    const delta = Math.round((goal.cadence - goal.current_cadence) * 10) / 10;

    return (
        <section
            className={`${workspacePanelClass} grid gap-5 p-5 sm:p-7`}
            aria-label="What this month is aiming at"
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <Tile
                    label={goal.kpi_label}
                    value={goal.target.toLocaleString()}
                    hint={goal.unit}
                />
                <Tile
                    label="Posts a week"
                    value={String(goal.cadence)}
                    badge={delta > 0 ? `+${formatRate(delta)}` : undefined}
                    hint={`Currently ${formatRate(goal.current_cadence)}`}
                />
                <Tile
                    label={`${goal.unit} a week`}
                    value={`~${formatRate(goal.per_week_needed)}`}
                    hint="Needed to hit the target"
                />
            </div>

            {impact !== '' && (
                <div className="rounded-2xl border border-amber-500/40 bg-amber-500/5 p-4">
                    <p className="max-w-[72ch] text-sm leading-relaxed">
                        <span className="font-medium">Expected impact. </span>
                        {impact}
                    </p>
                </div>
            )}

            {/*
                Said once, here, rather than on the tile. The gap belongs to the
                whole panel — it is why the first figure has no "currently"
                under it — and repeating it inside a tile that is already
                showing a target crowds the one number this screen exists for.
            */}
            {goal.needs !== null && (
                <p className="flex max-w-[80ch] items-start gap-2 text-xs leading-relaxed text-muted-foreground">
                    <Lock
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span>
                        Progress against this target stays blank until something
                        can report it — {goal.needs.toLowerCase()} The cadence
                        above is measured from your own published posts and is
                        tracked either way.
                    </span>
                </p>
            )}

            <GoalAdjust goal={goal} kpis={kpis} onAdjust={onAdjust} />
        </section>
    );
}

function Tile({
    label,
    value,
    hint,
    badge,
}: {
    label: string;
    value: string;
    hint: string;
    badge?: string;
}) {
    return (
        <div className="rounded-2xl border bg-muted/20 p-4">
            <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-2 flex items-baseline gap-2">
                <span className="text-3xl font-semibold tracking-tight tabular-nums">
                    {value}
                </span>
                {badge !== undefined && (
                    <span className="text-sm font-medium text-emerald-600 tabular-nums dark:text-emerald-400">
                        {badge}
                    </span>
                )}
            </p>
            <p className="mt-1.5 text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}

/** Rates read as decimals only when they are one — "0.8 a week", but "3". */
function formatRate(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

/**
 * The proposal that came back without numbers.
 *
 * Rare, and deliberately not papered over with a default: an invented target is
 * indistinguishable on screen from a reasoned one, and the operator would
 * approve a month against it. The ideas below are still worth having, so the
 * screen says what is missing and offers the form rather than refusing the
 * whole proposal.
 */
function UnsizedMonth({
    kpis,
    onAdjust,
}: {
    kpis: Kpi[];
    onAdjust: (form: HTMLFormElement) => void;
}) {
    return (
        <section
            className={`${workspacePanelClass} grid gap-4 p-5 sm:p-7`}
            aria-label="What this month is aiming at"
        >
            <div>
                <h2 className="font-semibold tracking-tight">
                    This month has no target yet
                </h2>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                    The assistant did not size this one. Set it here, or ask it
                    to below — the ideas underneath stand either way.
                </p>
            </div>
            <GoalFields goal={null} kpis={kpis} onAdjust={onAdjust} />
        </section>
    );
}

/** The numbers, editable, folded away until somebody disagrees with them. */
function GoalAdjust({
    goal,
    kpis,
    onAdjust,
}: {
    goal: Goal;
    kpis: Kpi[];
    onAdjust: (form: HTMLFormElement) => void;
}) {
    return (
        <details className="group border-t pt-4">
            <summary className="cursor-pointer list-none text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none">
                <span className="group-open:hidden">
                    Disagree with these numbers?
                </span>
                <span className="hidden group-open:inline">
                    Close without changing them
                </span>
            </summary>
            <div className="mt-4">
                {/*
                    Keyed on the numbers, so a goal that changes underneath
                    remounts the form on them.

                    `<details>` keeps its children mounted while closed, and
                    these fields seed their state once. A refinement that moved
                    the target from 340 to 520 left the folded form still
                    holding 340 — and opening it and pressing Save posted the
                    pre-refinement numbers over the new ones, confirmed, with
                    nothing on screen to suggest anything had been reverted.
                */}
                <GoalFields
                    key={`${goal.kpi}-${goal.target}-${goal.cadence}`}
                    goal={goal}
                    kpis={kpis}
                    onAdjust={onAdjust}
                />
            </div>
        </details>
    );
}

function GoalFields({
    goal,
    kpis,
    onAdjust,
}: {
    goal: Goal | null;
    kpis: Kpi[];
    onAdjust: (form: HTMLFormElement) => void;
}) {
    const [kpi, setKpi] = useState(goal?.kpi ?? kpis[0]?.value ?? 'engagement');
    const chosen = kpis.find((option) => option.value === kpi);

    return (
        <form
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                onAdjust(event.currentTarget);
            }}
        >
            <input type="hidden" name="kpi" value={kpi} />

            <div
                className="grid gap-2 sm:grid-cols-3"
                role="radiogroup"
                aria-label="Goal"
            >
                {kpis.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={option.value === kpi}
                        onClick={() => setKpi(option.value)}
                        className={`rounded-2xl p-3.5 text-left transition-all focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none ${
                            option.value === kpi
                                ? 'bg-violet-500/12 shadow-[inset_0_0_0_2px_rgba(139,92,246,0.45)]'
                                : 'bg-muted/45 hover:bg-muted/70'
                        }`}
                    >
                        <span className="flex items-center justify-between gap-2">
                            <span className="text-sm font-medium">
                                {option.label}
                            </span>
                            {!option.measurable && (
                                <Lock
                                    className="size-3.5 shrink-0 text-muted-foreground"
                                    aria-label="Not measurable yet"
                                />
                            )}
                        </span>
                        <span className="mt-1.5 block text-xs leading-relaxed text-muted-foreground">
                            {option.measurable
                                ? `Counted in ${option.unit}.`
                                : option.requires}
                        </span>
                    </button>
                ))}
            </div>

            <div className="grid gap-4 sm:max-w-md sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="goal-target">
                        Target ({chosen?.unit ?? 'units'})
                    </Label>
                    <Input
                        id="goal-target"
                        name="target"
                        type="number"
                        min={1}
                        defaultValue={goal?.target ?? ''}
                        required
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="goal-cadence">Posts a week</Label>
                    <Input
                        id="goal-cadence"
                        name="cadence"
                        type="number"
                        min={1}
                        max={50}
                        defaultValue={goal?.cadence ?? ''}
                        required
                    />
                </div>
            </div>

            <div>
                <Button type="submit" variant="outline" size="sm">
                    Save these numbers
                </Button>
            </div>
        </form>
    );
}

/**
 * The month in a paragraph, then week by week.
 *
 * Four rows with a line each, which is all a plan for four weeks needs to be
 * legible. It is the same `weeks` array the Overview counts against, so the
 * objective a person approves here is the objective the header reports on.
 */
function MonthPanel({ plan, goal }: { plan: Plan; goal: Goal | null }) {
    const weeks = goal?.weeks ?? [];
    const current = goal?.confirmed === true ? goal.week_of : null;

    return (
        <section
            className={`${workspacePanelClass} grid gap-6 p-5 sm:p-7 ${
                weeks.length > 0
                    ? 'lg:grid-cols-2 lg:items-start lg:gap-10'
                    : ''
            }`}
        >
            <div>
                <SectionLabel>The month in one paragraph</SectionLabel>
                {/*
                    Capped at a measure, not at the column.

                    A paragraph filling this panel ran about 140 characters a
                    line — roughly double what an eye tracks back from without
                    losing the row, and the reason this read as a slab. The cap
                    is on the text rather than on the page because the tiles and
                    the week rows want the width; `pre-line` so a summary the
                    assistant chose to break into paragraphs arrives as
                    paragraphs.
                */}
                <p className="mt-3 max-w-[68ch] text-[0.975rem] leading-8 whitespace-pre-line text-foreground/90">
                    {plan.summary ||
                        'The assistant has not written a summary yet.'}
                </p>
            </div>

            {/*
                Beside the paragraph once there is room for both. They are the
                same argument at two resolutions — the month, and the month
                divided into four — so reading one against the other is the
                point, and stacking them put a screenful of empty panel between
                them at this width.
            */}
            {weeks.length > 0 && (
                <div className="grid content-start gap-2.5 border-t pt-6 lg:border-t-0 lg:pt-0">
                    <SectionLabel>Week by week</SectionLabel>
                    {weeks.map((week, index) => {
                        const isCurrent = current === index + 1;

                        return (
                            <div
                                key={`${index}-${week.objective}`}
                                className={`grid grid-cols-[4.5rem_minmax(0,1fr)] items-baseline gap-3 rounded-2xl p-3.5 ${
                                    isCurrent
                                        ? 'bg-emerald-500/[0.08]'
                                        : 'bg-muted/25'
                                }`}
                            >
                                <p
                                    className={`text-sm font-medium ${
                                        isCurrent
                                            ? 'text-emerald-700 dark:text-emerald-300'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    Week {index + 1}
                                </p>
                                <p className="text-sm leading-relaxed">
                                    {week.objective}
                                    {isCurrent && (
                                        <span className="ml-2 text-xs text-emerald-700 dark:text-emerald-300">
                                            Current
                                        </span>
                                    )}
                                </p>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

/**
 * One button at a time.
 *
 * Approving and drafting used to sit side by side, which made the screen ask two
 * questions at once and answered neither clearly — the second was disabled until
 * the first was pressed, so half the control bar was permanently dead. They are
 * sequential in fact, so they are sequential here: approve the month, and the
 * same place then offers the next thing to do with it.
 *
 * The ideas are a **link**, not a list. Nineteen of them in a rail was the worst
 * of both: too cramped to read and a duplicate of two screens that show them
 * properly.
 */
function Decision({
    plan,
    month,
    busy,
    onAccept,
    onGenerate,
}: {
    plan: Plan;
    month: string;
    busy: Busy;
    onAccept: () => void;
    onGenerate: () => void;
}) {
    const unwritten = plan.ideas.filter(
        (idea) => idea.drafts.length < idea.channels.length,
    ).length;

    return (
        <section
            className={`${workspacePanelClass} flex flex-wrap items-center justify-between gap-4 px-5 py-4`}
            aria-label="This proposal"
        >
            <div className="min-w-0">
                <p className="text-sm font-medium">
                    {plan.accepted
                        ? 'This version is the month you are working from'
                        : 'Approving this sets the target and starts the month'}
                </p>
                <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                    <span>{plan.ideas.length} ideas</span>
                    <span aria-hidden="true">·</span>
                    <span>{unwritten} still to write</span>
                    <span aria-hidden="true">·</span>
                    <Link
                        href={createTab({ query: { month } })}
                        className="underline underline-offset-2 hover:text-foreground"
                    >
                        pick one on Create
                    </Link>
                    <span aria-hidden="true">·</span>
                    <Link
                        href={boardTab({ query: { month } })}
                        className="underline underline-offset-2 hover:text-foreground"
                    >
                        see the board
                    </Link>
                </p>
            </div>

            {plan.accepted ? (
                <Button
                    variant="secondary"
                    disabled={busy !== null || unwritten === 0}
                    onClick={onGenerate}
                >
                    <WandSparkles className="size-4" aria-hidden="true" />
                    {busy === 'generating'
                        ? 'Writing…'
                        : unwritten === 0
                          ? 'All written'
                          : 'Write the next week'}
                </Button>
            ) : (
                <Button disabled={busy !== null} onClick={onAccept}>
                    {busy === 'accepting' ? (
                        <Check className="size-4" aria-hidden="true" />
                    ) : null}
                    {busy === 'accepting' ? 'Approving…' : 'Approve this plan'}
                    {busy === 'accepting' ? null : (
                        <ArrowRight className="size-4" aria-hidden="true" />
                    )}
                </Button>
            )}
        </section>
    );
}

/**
 * How the assistant got here — folded.
 *
 * Every part of this was previously above the fold, in the order the model
 * produced it. None of it is wrong and some of it is the best thing this engine
 * makes: sourced facts with their provenance, and the assumptions it knows it is
 * making. But it answers "how did you arrive at this", and nobody asks that
 * before they have seen the claim. Kept whole, moved after the decision, and
 * closed by default.
 *
 * Native `<details>` rather than a controlled panel: it is open-able before
 * hydration, findable by the browser's own in-page search, and needs no state.
 */
function Reasoning({ plan }: { plan: Plan }) {
    const strategy = plan.strategy ?? {};
    const facts = strategy.site_facts ?? [];
    const assumptions = strategy.assumptions ?? [];
    const objectives = strategy.objectives ?? [];
    const pillars = strategy.pillars ?? [];
    const roles = strategy.channel_roles ?? {};
    const mixFindings = strategy.mix_findings ?? [];

    return (
        <>
            {/*
                Outside the fold, because it is a warning and not reasoning. A
                month that stayed unbalanced after two asks is proposed anyway,
                so this is the only place an operator finds out — and finding out
                after they have approved it is finding out too late.
            */}
            {mixFindings.length > 0 && (
                <section className="rounded-2xl border border-amber-500/40 bg-amber-500/5 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <CircleAlert className="size-4" aria-hidden="true" />
                        Unbalanced month
                    </p>
                    <ul className="mt-2 grid gap-1.5 text-sm leading-relaxed text-muted-foreground">
                        {mixFindings.map((finding) => (
                            <li key={finding}>{finding}</li>
                        ))}
                    </ul>
                </section>
            )}

            <details className={`${workspacePanelClass} group`}>
                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-5 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none sm:px-7">
                    How this was built
                    <span className="text-xs font-normal text-muted-foreground">
                        <span className="group-open:hidden">
                            Objectives, pillars, channels, sources
                        </span>
                        <span className="hidden group-open:inline">Hide</span>
                    </span>
                </summary>

                <div className="grid gap-7 border-t p-5 sm:p-7">
                    {(objectives.length > 0 || pillars.length > 0) && (
                        <div className="grid gap-7 lg:grid-cols-2">
                            {objectives.length > 0 && (
                                <section>
                                    <SectionLabel>Objectives</SectionLabel>
                                    <ol className="mt-3 grid gap-2.5">
                                        {objectives.map((objective, index) => (
                                            <li
                                                key={objective}
                                                className="grid grid-cols-[1.75rem_minmax(0,1fr)] gap-3 text-sm leading-relaxed"
                                            >
                                                <span className="flex size-7 items-center justify-center rounded-full bg-violet-500/10 text-xs font-semibold text-violet-700 dark:text-violet-300">
                                                    {index + 1}
                                                </span>
                                                <span className="pt-1">
                                                    {objective}
                                                </span>
                                            </li>
                                        ))}
                                    </ol>
                                </section>
                            )}

                            {pillars.length > 0 && (
                                <section>
                                    <SectionLabel>
                                        Editorial pillars
                                    </SectionLabel>
                                    <div className="mt-3 grid gap-2.5">
                                        {pillars.map((pillar) => (
                                            <div
                                                key={pillar.name}
                                                className="rounded-2xl border bg-muted/20 p-3.5"
                                            >
                                                <p className="text-sm font-medium">
                                                    {pillar.name}
                                                </p>
                                                <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                                                    {pillar.purpose}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            )}
                        </div>
                    )}

                    <section className="border-t pt-6">
                        <SectionLabel>What each channel is for</SectionLabel>
                        <div className="mt-3 grid gap-2.5 md:grid-cols-3">
                            {(['threads', 'x', 'instagram'] as const).map(
                                (channel) => (
                                    <div
                                        key={channel}
                                        className="rounded-2xl border bg-background p-3.5"
                                    >
                                        <ChannelLabel channel={channel} />
                                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                            {roles[channel] ||
                                                'No distinct role proposed.'}
                                        </p>
                                    </div>
                                ),
                            )}
                        </div>
                    </section>

                    {(facts.length > 0 || assumptions.length > 0) && (
                        <div className="grid gap-7 border-t pt-6 lg:grid-cols-2">
                            {facts.length > 0 && (
                                <section>
                                    <SectionLabel>
                                        Known from the project
                                    </SectionLabel>
                                    <ul className="mt-3 grid gap-2.5">
                                        {facts.map((fact) => (
                                            <li
                                                key={fact.claim}
                                                className="rounded-2xl border bg-background p-3.5"
                                            >
                                                <p className="text-sm leading-relaxed">
                                                    {fact.claim}
                                                </p>
                                                <p className="mt-1.5 text-xs text-muted-foreground">
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
                                    <ul className="mt-3 grid gap-2.5">
                                        {assumptions.map((assumption) => (
                                            <li
                                                key={assumption}
                                                className="rounded-2xl border border-dashed bg-muted/15 p-3.5 text-sm leading-relaxed text-muted-foreground"
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
            </details>
        </>
    );
}

/**
 * The conversation, which is the only way this month changes.
 *
 * Below the artefact rather than beside it: a person reads the strategy, then
 * disagrees with it. Side by side, at reading width, neither had room.
 */
function Conversation({
    source,
    plan,
    busy,
    operationStatus,
    message,
    onMessage,
    onSend,
}: {
    source: Source;
    plan: Plan | null;
    busy: Busy;
    operationStatus: Operation['status'] | null;
    message: string;
    onMessage: (value: string) => void;
    onSend: () => void;
}) {
    const questions = plan?.strategy?.questions ?? [];

    if (plan === null) {
        return null;
    }

    return (
        <section
            className={`${workspacePanelClass} overflow-hidden`}
            aria-label="Conversation with the content partner"
        >
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-violet-500/12 text-violet-600 dark:text-violet-300">
                        <Sparkles className="size-4" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="font-semibold tracking-tight">
                            Change the month
                        </h2>
                        <p className="truncate text-xs text-muted-foreground">
                            Working from {source.site_name}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
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
                className="max-h-[28rem] overflow-y-auto px-5 py-5"
                aria-live="polite"
            >
                <div className="grid gap-4">
                    {plan.messages.map((item) =>
                        item.role === 'user' ? (
                            <ChatUserBubble key={item.id}>
                                {item.body}
                            </ChatUserBubble>
                        ) : (
                            <ChatAssistantBubble key={item.id}>
                                {item.body}
                            </ChatAssistantBubble>
                        ),
                    )}

                    {(busy === 'refining' || busy === 'generating') && (
                        <div className="flex items-center gap-2 px-1 text-sm text-muted-foreground">
                            <WandSparkles
                                className="size-3.5 animate-pulse"
                                aria-hidden="true"
                            />
                            {operationStatus === 'pending'
                                ? 'Queued — waiting for a worker…'
                                : busy === 'generating'
                                  ? 'Writing drafts…'
                                  : 'Rebuilding the month…'}
                        </div>
                    )}
                </div>
            </div>

            <div className="border-t bg-background/85 p-4 backdrop-blur">
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
                                    <span className="min-w-0">{question}</span>
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="relative rounded-3xl border bg-muted/25 p-2 shadow-sm focus-within:ring-2 focus-within:ring-ring/30">
                        <label htmlFor="plan-message" className="sr-only">
                            Ask the content partner to change the month
                        </label>
                        <Textarea
                            id="plan-message"
                            value={message}
                            onChange={(event) => onMessage(event.target.value)}
                            placeholder="Challenge an assumption, or reshape the month…"
                            rows={2}
                            maxLength={5000}
                            disabled={busy !== null}
                            className="min-h-16 resize-none border-0 bg-transparent px-3 py-2 pr-14 shadow-none focus-visible:ring-0"
                        />
                        <Button
                            type="submit"
                            size="icon"
                            aria-label="Send"
                            disabled={busy !== null || message.trim() === ''}
                            className="absolute right-3 bottom-3 rounded-full"
                        >
                            <Send className="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                </form>
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
            <div className="min-w-0 pt-0.5 text-[0.95rem] leading-7">
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
    onBuild,
}: {
    source: Source;
    busy: Busy;
    operationStatus: Operation['status'] | null;
    proposalFailed: boolean;
    onBuild: () => void;
}) {
    return (
        <Card className={workspacePanelClass}>
            <CardContent className="flex min-h-[22rem] flex-col items-center justify-center p-8 text-center">
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
                            : `Reading ${source.site_name}`
                        : proposalFailed
                          ? 'The proposal is not available yet'
                          : 'This month has no plan yet'}
                </h2>
                <p className="mt-2 max-w-lg text-sm leading-relaxed text-muted-foreground">
                    {operationStatus === 'pending'
                        ? 'A worker will pick it up shortly. You can leave this page; the result is durable.'
                        : 'The assistant starts from the site, the brand brief and what has already been published, and comes back with a point of view for the month.'}
                </p>
                {busy === null && (
                    <Button className="mt-6 rounded-xl" onClick={onBuild}>
                        <WandSparkles className="size-4" aria-hidden="true" />
                        {proposalFailed
                            ? 'Try again'
                            : 'Build this month’s plan'}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function ChannelLabel({ channel }: { channel: string }) {
    const normalized = channel.toLowerCase();
    const label =
        normalized === 'x'
            ? 'X'
            : normalized.charAt(0).toUpperCase() + normalized.slice(1);

    return (
        <p className="text-xs font-semibold text-muted-foreground">{label}</p>
    );
}

function SectionLabel({ children }: { children: string }) {
    return (
        <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
            {children}
        </p>
    );
}
