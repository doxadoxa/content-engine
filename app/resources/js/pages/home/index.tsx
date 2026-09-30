import { Deferred, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    Check,
    CheckCircle2,
    ExternalLink,
    FileText,
    Loader2,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import {
    PublicationActionButton,
    PublicationBadge,
    PublishNowButton,
} from '@/components/article-publication';
import type { PublicationPresentation } from '@/components/article-publication';
import { ContentActions } from '@/components/content-actions';
import type { ArticleWorkflow } from '@/components/content-actions';
import { DashboardCharts, ManagerResults } from '@/components/manager-results';
import type { ResultsSummary } from '@/components/manager-results';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspaceHeroClass,
    workspacePanelClass,
} from '@/components/workspace-page';
import { show as onboarding } from '@/routes/onboarding';

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
type Run = {
    id: string;
    pipeline: string;
    subject: string | null;
    subject_id: string | null;
    message?: string | null;
};
type Work = { launching: boolean; active: Run[]; failed: Run[] };
/** The first run, milestone by milestone. See `App\Support\Engine\FirstRun`. */
type JourneyStep = {
    key: 'site' | 'topics' | 'calendar' | 'articles' | 'ai';
    label: string;
    state: 'done' | 'active' | 'upcoming';
    detail: string | null;
    subject: string | null;
    subject_id: string | null;
};
type Journey = { steps: JourneyStep[] };
type Article = {
    id: string;
    title: string;
    status: string;
    presentation: PublicationPresentation;
    publish_at: string | null;
    reason: string | null;
};
/** "Get your first article live". See `App\Publishing\Articles\FirstArticle`. */
type FirstStepLink = { label: string; href: string };
type FirstStep = {
    key: 'business' | 'website' | 'publish';
    label: string;
    state: 'done' | 'current' | 'todo';
    detail: string | null;
    action: FirstStepLink | null;
    health?: string | null;
    article?: {
        id: string;
        title: string;
        presentation: PublicationPresentation | null;
    } | null;
    can_publish?: boolean;
    reason?: string | null;
    fix?: FirstStepLink | null;
};
type FirstArticle =
    | { state: 'in_progress'; done: number; steps: FirstStep[] }
    | {
          state: 'live';
          article: { id: string; title: string; url: string | null };
          steps: [];
      };
type Needs = { dead_deliveries: number };
type Dashboard = {
    mode: 'automatic' | 'review_first';
    workflow: ArticleWorkflow;
    timezone: string;
    has_articles: boolean;
    writing: number;
    needs_review: number;
    scheduled: number;
    published: number;
    upcoming: Article[];
    attention: Article[];
    recent: Article[];
};
/** The card-free sample, while it is what this screen is about. */
type Preview = {
    finished: boolean;
    topics: number;
    draft: { id: string; title: string; words: number } | null;
    plan: {
        key: string;
        name: string;
        price_cents: number;
        currency: string;
        articles: number | null;
    };
    trial_days: number;
};

type Props = {
    project: { id: string; name: string; site_name: string } | null;
    preview?: Preview | null;
    hasProjects: boolean;
    checklist: Step[];
    first_article?: FirstArticle | null;
    needs?: Needs;
    work?: Work;
    journey?: Journey | null;
    manager?: Dashboard;
    results?: ResultsSummary;
    health?: { healthy: boolean; reason: string | null };
};

export default function Home({
    project,
    preview,
    hasProjects,
    checklist,
    first_article: firstArticle,
    needs,
    work,
    journey,
    manager,
    results,
    health,
}: Props) {
    /*
     * `preview` is in this list because the panel below promises in so many
     * words that the page keeps itself up to date, and the sample it is
     * waiting on lands minutes later. `finished` arriving on that prop is the
     * only thing that turns the spinner into a finished article and the button
     * that starts the trial — so leaving it out asked the server, every
     * fifteen seconds, for everything except the one prop that had changed.
     * `journey` is here for the same reason: it says "this page updates
     * itself", and a step that never ticks over would make that a lie.
     */
    usePoll(15000, {
        only: [
            'preview',
            'work',
            'journey',
            'manager',
            'checklist',
            'results',
            'first_article',
        ],
    });
    const planning =
        work?.active.some((run) =>
            ['research', 'planning'].includes(run.pipeline),
        ) ?? false;

    /*
     * The sample owns this moment during a card-free preview, and two panels
     * both saying "Avyo is working" is one too many.
     */
    const shownJourney = preview ? null : (journey ?? null);

    /*
     * Whether an AI check is running, from either source: the journey during
     * the first run, the run panel for every check after it. A scheduled or
     * manual check on an existing sampling set runs as `ai_sample` pipelines
     * with no `visibility` run at all, so both count — otherwise the cards
     * said "Not checked yet" while the assistants were being asked.
     */
    const checking = Boolean(
        journey?.steps.some(
            (step) => step.key === 'ai' && step.state === 'active',
        ) ||
        work?.active.some((run) => AI_CHECK_PIPELINES.includes(run.pipeline)),
    );

    /*
     * While the journey is up, the result cards sit below the content engine:
     * the journey is the news, and a row of "not checked yet" above it is the
     * empty grid it replaced. Decided by the journey alone, not by whether
     * anything has been measured yet — the first AI answer lands mid-session,
     * and cards that jumped from the bottom of the page to the top under
     * somebody's cursor would lose them their place.
     */
    const resultsLater = shownJourney !== null;

    /*
     * The run panel, minus what the journey is already showing. Failures stay
     * in it, and it moves up under the journey so they are still read.
     */
    const laterWork =
        shownJourney && work
            ? {
                  launching: false,
                  active: work.active.filter(
                      (run) => !JOURNEY_PIPELINES.includes(run.pipeline),
                  ),
                  failed: work.failed,
              }
            : work;

    if (!project) {
        return (
            <>
                <Head title="Dashboard" />
                <WorkspacePage width="reading">
                    <section
                        className={`${workspacePanelClass} mx-auto w-full max-w-2xl p-8 text-center`}
                    >
                        <Sparkles className="mx-auto size-8 text-terracotta" />
                        <h1 className="mt-4 text-3xl font-semibold">
                            Get found by your next customers
                        </h1>
                        <p className="mt-3 text-sm leading-6 text-muted-foreground">
                            {hasProjects
                                ? 'Choose a business to see its search traffic, AI visibility, and content calendar.'
                                : 'Tell Avyo about your business. It will find useful topics, write content, and publish on your schedule.'}
                        </p>
                        {!hasProjects && (
                            <Button asChild className="mt-6">
                                <Link href={onboarding()}>
                                    Set up your business{' '}
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                        )}
                    </section>
                </WorkspacePage>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow={project.site_name}
                    title="Your growth at a glance"
                    description="See where customers find you, how often AI mentions you, and what’s bringing people to your website."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href="/visibility">
                                    <Sparkles className="size-4" /> AI
                                    visibility
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href="/calendar">
                                    <CalendarDays className="size-4" /> Open
                                    Calendar
                                </Link>
                            </Button>
                        </>
                    }
                />
                {firstArticle && <FirstArticlePanel first={firstArticle} />}
                {preview && <PreviewPanel preview={preview} />}
                {shownJourney && (
                    <>
                        <JourneyPanel journey={shownJourney} />
                        <WorkPanel work={laterWork} />
                    </>
                )}
                {results && !resultsLater && (
                    <ManagerResults results={results} checking={checking} />
                )}
                {manager && !preview && <PublishingStatus manager={manager} />}
                {results && !resultsLater && (
                    <DashboardCharts
                        results={results}
                        projectId={project.id}
                        checking={checking}
                    />
                )}
                <div className="flex flex-wrap items-center justify-between gap-4 border-t pt-6">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Your content engine
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            What’s being created to help more customers find
                            you.
                        </p>
                    </div>
                    <ContentActions
                        planning={planning}
                        primary={manager?.workflow.ready ? 'plan' : 'none'}
                    />
                </div>
                <Deferred data="health" fallback={() => null}>
                    {health && !health.healthy && (
                        <section className="rounded-xl border border-amber-400/40 bg-amber-50/40 p-4 text-sm dark:bg-amber-950/20">
                            <p className="font-medium">
                                Automatic work needs attention
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                Content stays saved while the connection is
                                restored.
                            </p>
                            <details className="mt-2">
                                <summary className="cursor-pointer">
                                    Connection details
                                </summary>
                                <p className="mt-2">{health.reason}</p>
                            </details>
                        </section>
                    )}
                </Deferred>
                {!shownJourney && <WorkPanel work={work} />}
                <Deferred data="manager" fallback={<LoadingPanel />}>
                    {manager && (
                        <>
                            {/*
                             * Four zeroes say nothing a new project does not
                             * already know, and they were half of the "empty
                             * fields" a paying customer read as a product that
                             * had not started. The row appears with the first
                             * article.
                             */}
                            {manager.writing +
                                manager.needs_review +
                                manager.scheduled +
                                manager.published >
                                0 && (
                                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                    <Count
                                        title="Being written"
                                        value={manager.writing}
                                        href="/content?view=writing"
                                    />
                                    <Count
                                        title="Needs attention"
                                        value={manager.needs_review}
                                        href="/content?view=review"
                                    />
                                    <Count
                                        title="Scheduled"
                                        value={manager.scheduled}
                                        href="/content?view=scheduled"
                                    />
                                    <Count
                                        title="Published"
                                        value={manager.published}
                                        href="/content?view=published"
                                    />
                                </div>
                            )}
                            <div className="grid items-start gap-5 lg:grid-cols-2">
                                <section
                                    className={`${workspacePanelClass} overflow-hidden`}
                                >
                                    <PanelTitle
                                        title="Coming up"
                                        href="/calendar"
                                        link="View Calendar"
                                    />
                                    <div className="divide-y">
                                        {manager.upcoming.length ? (
                                            manager.upcoming.map((article) => (
                                                <ArticleRow
                                                    key={article.id}
                                                    article={article}
                                                    timezone={manager.timezone}
                                                />
                                            ))
                                        ) : (
                                            <p className="p-6 text-sm leading-6 text-muted-foreground">
                                                {manager.has_articles
                                                    ? 'Your next article is not scheduled yet. Open Content to choose a date.'
                                                    : 'Your content plan will appear here. Ask Avyo to plan useful topics or create your first article.'}
                                            </p>
                                        )}
                                    </div>
                                </section>
                                <section
                                    className={`${workspacePanelClass} overflow-hidden`}
                                >
                                    <PanelTitle
                                        title="Needs your attention"
                                        href="/content?view=review"
                                        link="Review content"
                                    />
                                    <div className="divide-y">
                                        {(needs?.dead_deliveries ?? 0) > 0 && (
                                            <p className="flex items-start gap-2 bg-rose-50/40 px-5 py-3 text-sm dark:bg-rose-950/20">
                                                <TriangleAlert
                                                    className="mt-0.5 size-4 shrink-0 text-rose-700 dark:text-rose-400"
                                                    aria-hidden="true"
                                                />
                                                <span>
                                                    {needs?.dead_deliveries ===
                                                    1
                                                        ? '1 article couldn’t be sent to your website.'
                                                        : `${needs?.dead_deliveries} articles couldn’t be sent to your website.`}{' '}
                                                    <Link
                                                        href="/deliveries"
                                                        className="underline underline-offset-4"
                                                    >
                                                        Open publishing history
                                                    </Link>
                                                </span>
                                            </p>
                                        )}
                                        {manager.attention.length ? (
                                            manager.attention.map((article) => (
                                                <ArticleRow
                                                    key={article.id}
                                                    article={article}
                                                    timezone={manager.timezone}
                                                />
                                            ))
                                        ) : (
                                            <div className="flex gap-3 p-6 text-sm text-muted-foreground">
                                                <CheckCircle2 className="size-5 shrink-0 text-sage" />
                                                <p>
                                                    No content decisions
                                                    waiting. Scheduled articles
                                                    continue according to their
                                                    publishing settings.
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </section>
                            </div>
                        </>
                    )}
                </Deferred>
                {manager && manager.recent.length > 0 && (
                    <section
                        className={`${workspacePanelClass} overflow-hidden`}
                    >
                        <PanelTitle
                            title="Recently published"
                            href="/content?view=published"
                            link="All published content"
                        />
                        <div className="divide-y">
                            {manager.recent.map((article) => (
                                <ArticleRow
                                    key={article.id}
                                    article={article}
                                    timezone={manager.timezone}
                                />
                            ))}
                        </div>
                    </section>
                )}
                {results && resultsLater && (
                    <>
                        <ManagerResults results={results} checking={checking} />
                        <DashboardCharts
                            results={results}
                            projectId={project.id}
                            checking={checking}
                        />
                    </>
                )}
                <Setup checklist={checklist} projectId={project.id} />
            </WorkspacePage>
        </>
    );
}
/**
 * The sample, and the question that follows it.
 *
 * What used to be here at this moment was an amber warning strip reading "add
 * a card to start the engine" over a dashboard of empty tiles — somebody who
 * had just spent five minutes answering questions about their business, shown
 * a row of dashes and what looked like an error. The engine now writes first
 * and asks second, so this is the reading material and the question together.
 *
 * It replaces the publishing-status panel rather than sitting above it. During
 * a preview nothing publishes at all, and two panels arguing about publication
 * is how the point gets lost.
 */
function PreviewPanel({ preview }: { preview: Preview }) {
    /*
     * A sample that finished with nothing in it.
     *
     * Research can fail — a keyword provider that is down, a site that stopped
     * answering — and the launch settles either way, because a project stuck
     * on a spinner is worse than one that says what happened. What must not
     * happen is this panel announcing that a sample is ready to read over an
     * empty page, which is the same lie the amber banner used to tell.
     */
    const empty = preview.finished && preview.topics === 0 && !preview.draft;

    const price = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: preview.plan.currency.toUpperCase(),
        maximumFractionDigits: 0,
    }).format(preview.plan.price_cents / 100);

    return (
        <section
            className={`${workspacePanelClass} flex flex-col gap-5 p-5 sm:p-6`}
            aria-label="Your sample"
        >
            <div className="flex items-start gap-3">
                {empty ? (
                    <TriangleAlert
                        className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
                        aria-hidden="true"
                    />
                ) : preview.finished ? (
                    <Sparkles
                        className="mt-0.5 size-5 shrink-0 text-terracotta"
                        aria-hidden="true"
                    />
                ) : (
                    <Spinner className="mt-0.5 size-5 shrink-0" />
                )}
                <div>
                    <h2 className="text-lg font-semibold">
                        {empty
                            ? 'Your sample did not finish'
                            : preview.finished
                              ? 'Your sample is ready'
                              : 'Writing your sample now'}
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        {empty
                            ? 'Something went wrong while researching your market, so there is nothing to show you yet. Nothing was charged. Get in touch and we will look at it, or start the trial and Avyo will try again.'
                            : preview.finished
                              ? 'Read it before you decide anything. No card has been asked for and nothing has been published to your website.'
                              : 'Avyo is researching your market, planning a month of topics and writing the first article. A few minutes — this page keeps itself up to date.'}
                    </p>
                </div>
            </div>

            {(preview.topics > 0 || preview.draft) && (
                <div className="grid gap-3 sm:grid-cols-2">
                    {preview.topics > 0 && (
                        <Link
                            href="/calendar"
                            className="rounded-xl border p-4 transition-colors hover:bg-muted/40"
                        >
                            <p className="text-2xl font-semibold">
                                {preview.topics}
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                topics planned for you
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Chosen from what people in your market actually
                                search for. See the calendar →
                            </p>
                        </Link>
                    )}
                    {preview.draft && (
                        <Link
                            href={`/content/${preview.draft.id}`}
                            className="rounded-xl border p-4 transition-colors hover:bg-muted/40"
                        >
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Your first article
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {preview.draft.title}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {preview.draft.words > 0 &&
                                    `${preview.draft.words.toLocaleString()} words · `}
                                written from your site. Read the draft →
                            </p>
                        </Link>
                    )}
                </div>
            )}

            {preview.finished && (
                <div className="flex flex-wrap items-center justify-between gap-4 border-t pt-5">
                    <p className="text-sm leading-6 text-muted-foreground">
                        {empty
                            ? 'Add a card to start your '
                            : 'Happy with it? Add a card to start your '}
                        {preview.trial_days}-day trial.{' '}
                        {preview.plan.articles !== null &&
                            `${preview.plan.name} then writes ${preview.plan.articles} articles a month for ${price}. `}
                        Nothing is charged today, and cancelling before the
                        trial ends costs nothing.
                    </p>
                    <Button asChild>
                        <Link href="/billing">
                            Start my {preview.trial_days}-day trial{' '}
                            <ArrowRight className="size-4" />
                        </Link>
                    </Button>
                </div>
            )}
        </section>
    );
}

/**
 * "Get your first article live" — the three steps the owner named: set up the
 * business, connect the website, publish the first article. The top of Home
 * until the first article is out, then one line of success for a day.
 */
function FirstArticlePanel({ first }: { first: FirstArticle }) {
    const { auth } = usePage().props;
    const owner = auth.project?.role === 'owner';

    if (first.state === 'live') {
        return (
            <section
                className={`${workspacePanelClass} flex flex-wrap items-center gap-3 px-5 py-4`}
                aria-label="Your first article"
            >
                <CheckCircle2
                    className="size-5 shrink-0 text-emerald-700 dark:text-emerald-400"
                    aria-hidden="true"
                />
                <p className="min-w-0 flex-1 text-sm">
                    <span className="font-medium">
                        Your first article is live.
                    </span>{' '}
                    <span className="text-muted-foreground">
                        {first.article.title}
                    </span>
                </p>
                {first.article.url ? (
                    <Button asChild size="sm" variant="outline">
                        <a
                            href={first.article.url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            View on your site
                            <ExternalLink
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            <span className="sr-only">
                                {' '}
                                (opens in a new tab)
                            </span>
                        </a>
                    </Button>
                ) : (
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/content/${first.article.id}`}>
                            Open the article
                        </Link>
                    </Button>
                )}
            </section>
        );
    }

    const current = first.steps.find((step) => step.state === 'current');
    const article = first.steps.find((step) => step.key === 'publish')?.article;

    return (
        <section
            className={`${workspacePanelClass} p-5 sm:p-6`}
            aria-labelledby="first-article-title"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        id="first-article-title"
                        className="text-lg font-semibold text-balance"
                    >
                        Get your first article live
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Three steps. After that, Avyo publishes on your
                        schedule.
                    </p>
                </div>
                <p className="rounded-full border px-3 py-1 text-xs text-muted-foreground tabular-nums">
                    {first.done} of {first.steps.length} done
                </p>
            </div>
            <ol className="mt-5 grid gap-3 lg:grid-cols-3">
                {first.steps.map((step, index) => (
                    <li
                        key={step.key}
                        aria-current={
                            step.state === 'current' ? 'step' : undefined
                        }
                        className={`flex min-w-0 flex-col gap-2 rounded-xl border p-4 ${step.state === 'current' ? 'border-foreground/25 bg-muted/30' : ''}`}
                    >
                        <div className="flex items-center gap-2">
                            <StepMarker state={step.state} number={index + 1} />
                            <p
                                className={`text-sm font-medium ${step.state === 'todo' ? 'text-muted-foreground' : ''}`}
                            >
                                <span className="sr-only">
                                    {
                                        {
                                            done: 'Done',
                                            current: 'Current step',
                                            todo: 'Not started',
                                        }[step.state]
                                    }
                                    :{' '}
                                </span>
                                {step.label}
                            </p>
                        </div>
                        {step.detail && (
                            <p className="text-sm leading-6 text-pretty text-muted-foreground">
                                {step.detail}
                            </p>
                        )}
                        {step.key === 'publish' ? (
                            <PublishStep step={step} owner={owner} />
                        ) : (
                            step.action &&
                            step.state !== 'done' && (
                                <Button
                                    asChild
                                    size="sm"
                                    className="mt-1 self-start"
                                >
                                    <Link href={step.action.href}>
                                        {step.action.label}
                                    </Link>
                                </Button>
                            )
                        )}
                    </li>
                ))}
            </ol>
            {/* Polled: a stable region says each change once. */}
            <p className="sr-only" role="status" aria-live="polite">
                {current
                    ? `Next: ${current.label}.${current.key === 'publish' && article?.presentation ? ` ${article.presentation.label}.` : ''}`
                    : ''}
            </p>
        </section>
    );
}

function StepMarker({
    state,
    number,
}: {
    state: FirstStep['state'];
    number: number;
}) {
    if (state === 'done') {
        return (
            <span
                className="flex size-6 shrink-0 items-center justify-center rounded-full bg-sage text-white"
                aria-hidden="true"
            >
                <Check className="size-3.5" strokeWidth={3} />
            </span>
        );
    }

    return (
        <span
            className={`flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium tabular-nums ${state === 'current' ? 'border-foreground/40 text-foreground' : 'text-muted-foreground'}`}
            aria-hidden="true"
        >
            {number}
        </span>
    );
}

/** The third step: the article, its status, and "Publish now" — or why not yet. */
function PublishStep({ step, owner }: { step: FirstStep; owner: boolean }) {
    const article = step.article ?? null;
    const status = article?.presentation ?? null;
    const underway =
        status !== null &&
        ['sending', 'retrying', 'delayed', 'failed'].includes(status.key);
    const reasonId = `first-article-reason-${article?.id ?? 'none'}`;

    return (
        <div className="flex flex-col gap-2">
            {article && (
                <Link
                    href={`/content/${article.id}`}
                    className="rounded-sm text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {article.title}
                </Link>
            )}
            {status && (
                <>
                    <PublicationBadge presentation={status} />
                    {status.detail && (
                        <p className="text-sm leading-6 text-muted-foreground">
                            {status.detail}
                        </p>
                    )}
                </>
            )}
            {underway ? (
                <PublicationActionButton
                    action={status?.action ?? null}
                    owner={owner}
                />
            ) : step.can_publish && article ? (
                <div className="self-start">
                    <PublishNowButton itemId={article.id} />
                </div>
            ) : (
                <>
                    <Button
                        type="button"
                        size="sm"
                        className="self-start"
                        disabled
                        aria-describedby={step.reason ? reasonId : undefined}
                    >
                        Publish now
                    </Button>
                    {step.reason && (
                        <p
                            id={reasonId}
                            className="text-sm text-muted-foreground"
                        >
                            {step.reason}{' '}
                            {step.fix && (
                                <Link
                                    href={step.fix.href}
                                    className="font-medium underline underline-offset-4"
                                >
                                    {step.fix.label}
                                </Link>
                            )}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}

/** The pipelines that ask the AI assistants: the check, and each sampled cell. */
const AI_CHECK_PIPELINES = ['visibility', 'ai_sample'];

/** The pipelines the journey already describes, by the step they drive. */
const JOURNEY_PIPELINES = [
    'site_audit',
    'research',
    'planning',
    'generation',
    ...AI_CHECK_PIPELINES,
];

/**
 * The first run, told as it happens.
 *
 * A customer who had just paid opened this screen to a row of dashes, and the
 * only sign of the engine was a spinner below the fold. They read it as a
 * product that had not started, which is the one thing it must never look
 * like. So while the first run lasts it leads the page: what is done, what is
 * happening now, and what is next.
 *
 * Steps rather than a percentage. They are not equal in length, and a bar
 * drawn from them would move in lies.
 */
function JourneyPanel({ journey }: { journey: Journey }) {
    const done = journey.steps.filter((step) => step.state === 'done').length;
    const active = journey.steps.filter((step) => step.state === 'active');
    // The first step still to come *after* the furthest one reached. An
    // article written on demand can finish before the calendar is planned,
    // and "up next: planning" beside a finished article reads backwards.
    const reached = journey.steps.reduce(
        (last, step, index) => (step.state === 'upcoming' ? last : index),
        -1,
    );
    const next =
        active.length === 0
            ? (journey.steps.find(
                  (step, index) => index > reached && step.state === 'upcoming',
              ) ?? null)
            : null;

    // One sentence for screen readers, spoken when it changes rather than on
    // every poll: the list itself re-renders every fifteen seconds.
    // Label and subject only, never the detail: the details are running
    // counts ("12 topics found so far") that change on most polls, and a
    // sentence that changes is a sentence a screen reader speaks again.
    const now = active.length
        ? active
              .map((step) =>
                  [step.label, step.subject].filter(Boolean).join(': '),
              )
              .join('. ')
        : next
          ? `Up next: ${next.label}`
          : 'Finishing up';

    return (
        <section className={workspaceHeroClass} aria-labelledby="journey-title">
            <div
                className="pointer-events-none absolute -top-24 -right-20 -z-10 size-80 rounded-full bg-[#f3cf6a]/12 blur-3xl motion-safe:animate-pulse motion-safe:[animation-duration:6s]"
                aria-hidden="true"
            />
            <div
                className="pointer-events-none absolute -bottom-32 left-10 -z-10 size-72 rounded-full bg-[#d6533c]/14 blur-3xl motion-safe:animate-pulse motion-safe:[animation-duration:8s]"
                aria-hidden="true"
            />

            <div className="p-6 sm:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-4">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-white/8 ring-1 ring-white/10">
                            <Sparkles
                                className="size-5 text-[#f3cf6a] motion-safe:animate-pulse motion-safe:[animation-duration:3s]"
                                aria-hidden="true"
                            />
                        </span>
                        <div>
                            <h2
                                id="journey-title"
                                className="text-xl font-semibold tracking-tight text-balance sm:text-2xl"
                            >
                                Avyo is working on your business
                            </h2>
                            <p className="mt-1 max-w-xl text-sm leading-6 text-pretty text-white/65">
                                Your first articles take a few minutes, and the
                                first AI check follows within the hour. You can
                                close this page. Avyo keeps going, and this page
                                updates itself.
                            </p>
                        </div>
                    </div>
                    <p className="rounded-full border border-white/10 bg-white/8 px-3 py-1 text-xs text-white/75 tabular-nums">
                        {done} of {journey.steps.length} done
                    </p>
                </div>

                <ol className="mt-8 grid gap-5 lg:grid-cols-5 lg:gap-4">
                    {journey.steps.map((step, index) => (
                        <JourneyStepItem
                            key={step.key}
                            step={step}
                            next={step === next}
                            last={index === journey.steps.length - 1}
                        />
                    ))}
                </ol>

                <p className="sr-only" aria-live="polite" aria-atomic="true">
                    {now}
                </p>
            </div>
        </section>
    );
}

function JourneyStepItem({
    step,
    next,
    last,
}: {
    step: JourneyStep;
    next: boolean;
    last: boolean;
}) {
    const status = {
        done: 'Done',
        active: 'In progress',
        upcoming: 'Not started yet',
    }[step.state];

    return (
        <li
            className="relative flex gap-3 lg:flex-col"
            aria-current={step.state === 'active' ? 'step' : undefined}
        >
            {!last && (
                // The thread between steps: down the side on a phone, across
                // the top on a wide screen. Lit once the step before is done.
                <span
                    className={`absolute top-8 -bottom-3 left-3 w-px lg:top-3 lg:-right-2 lg:bottom-auto lg:left-8 lg:h-px lg:w-auto ${step.state === 'done' ? 'bg-sage/70' : 'bg-white/15'}`}
                    aria-hidden="true"
                />
            )}
            <JourneyStepIcon state={step.state} />
            <div className="min-w-0 pb-1">
                <p
                    className={`text-sm leading-6 font-medium ${step.state === 'upcoming' ? 'text-white/55' : 'text-[#f3ecdd]'}`}
                >
                    <span className="sr-only">{status}: </span>
                    {step.label}
                </p>
                {step.subject &&
                    (step.subject_id ? (
                        <Link
                            href={`/content/${step.subject_id}`}
                            className="mt-1 line-clamp-2 text-xs leading-5 text-[#f3cf6a] underline underline-offset-4"
                        >
                            {step.subject}
                        </Link>
                    ) : (
                        <p className="mt-1 line-clamp-2 text-xs leading-5 text-[#f3cf6a]">
                            {step.subject}
                        </p>
                    ))}
                {step.detail && (
                    <p className="mt-1 text-xs leading-5 text-white/60">
                        {step.detail}
                    </p>
                )}
                {next && !step.detail && (
                    <p className="mt-1 text-xs leading-5 text-white/60">
                        Up next
                    </p>
                )}
            </div>
        </li>
    );
}

function JourneyStepIcon({ state }: { state: JourneyStep['state'] }) {
    if (state === 'done') {
        return (
            <span
                className="relative flex size-6 shrink-0 items-center justify-center rounded-full bg-sage text-white"
                aria-hidden="true"
            >
                <Check className="size-3.5" strokeWidth={3} />
            </span>
        );
    }

    if (state === 'active') {
        return (
            <span
                className="relative flex size-6 shrink-0 items-center justify-center rounded-full bg-[#17352f] shadow-[0_0_18px_rgba(243,207,106,0.35)] ring-1 ring-[#f3cf6a]/60"
                aria-hidden="true"
            >
                <Loader2 className="size-3.5 animate-spin text-[#f3cf6a] motion-reduce:animate-none" />
            </span>
        );
    }

    return (
        <span
            className="relative size-6 shrink-0 rounded-full border border-white/25 bg-[#17352f]"
            aria-hidden="true"
        />
    );
}

function PublishingStatus({ manager }: { manager: Dashboard }) {
    const next = manager.upcoming[0] ?? null;

    return (
        <section
            className={`${workspacePanelClass} grid gap-5 p-5 sm:grid-cols-[minmax(0,1fr)_minmax(14rem,0.7fr)] sm:items-center`}
            aria-label="Publishing status"
        >
            <div className="flex items-start gap-3">
                {manager.workflow.ready ? (
                    <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-sage" />
                ) : (
                    <CalendarDays className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                )}
                <div>
                    <p className="font-medium">
                        {manager.workflow.ready
                            ? manager.mode === 'automatic'
                                ? 'Automatic publishing is set up'
                                : 'Articles wait for your review'
                            : 'Publishing setup needed'}
                    </p>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        {manager.workflow.message}
                    </p>
                    {!manager.workflow.ready && (
                        <Button asChild size="sm" className="mt-3">
                            <Link href={manager.workflow.action}>
                                {manager.workflow.action_label}
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
            <div className="border-t pt-4 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-5">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Next article
                </p>
                {next ? (
                    <Link
                        href={`/content/${next.id}`}
                        className="mt-1 block text-sm font-medium hover:underline"
                    >
                        {next.title}
                        <span className="mt-1 block text-xs font-normal text-muted-foreground">
                            {next.presentation.detail ??
                                next.presentation.label}
                        </span>
                    </Link>
                ) : (
                    <p className="mt-1 text-sm text-muted-foreground">
                        Nothing scheduled
                    </p>
                )}
                <Link
                    href="/calendar"
                    className="mt-2 inline-block text-xs underline underline-offset-4"
                >
                    View Calendar
                </Link>
            </div>
        </section>
    );
}
function PanelTitle({
    title,
    href,
    link,
}: {
    title: string;
    href: string;
    link: string;
}) {
    return (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
            <h2 className="font-semibold">{title}</h2>
            <Link
                href={href}
                className="text-xs font-medium underline underline-offset-4"
            >
                {link}
            </Link>
        </header>
    );
}
function Count({
    title,
    value,
    href,
}: {
    title: string;
    value: number;
    href: string;
}) {
    return (
        <Link
            href={href}
            className="flex items-center justify-between gap-3 rounded-xl border bg-card/60 p-4 transition-colors hover:bg-muted/30"
        >
            <p className="text-sm text-muted-foreground">{title}</p>
            <p className="text-xl font-semibold tabular-nums">{value}</p>
        </Link>
    );
}
function ArticleRow({ article }: { article: Article; timezone: string }) {
    const status = article.presentation;

    return (
        <Link
            href={`/content/${article.id}`}
            className="flex items-start gap-3 px-5 py-4 transition-colors hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
        >
            <FileText
                className="mt-1 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div className="min-w-0">
                <p className="text-sm font-medium">{article.title}</p>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    <PublicationBadge presentation={status} />
                </div>
                {status.detail && (
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                        {status.detail}
                    </p>
                )}
            </div>
        </Link>
    );
}
function WorkPanel({ work }: { work?: Work }) {
    if (
        !work ||
        (!work.launching && !work.active.length && !work.failed.length)
    ) {
        return null;
    }

    const titles: Record<string, string> = {
        research: 'Finding topics your customers care about',
        planning: 'Preparing your content calendar',
        generation: 'Writing your article',
        refresh: 'Updating your article',
        site_audit: 'Getting to know your website',
        visibility: 'Checking sampled AI answers',
        ai_sample: 'Asking the AI assistants about you',
    };

    return (
        <section
            className={`${workspacePanelClass} space-y-3 p-5`}
            aria-label="Avyo is working"
        >
            {work.launching && work.active.length === 0 && (
                <p className="flex items-center gap-2 text-sm">
                    <Spinner className="size-4" /> Preparing your business and
                    content plan…
                </p>
            )}
            {work.active.map((run) => (
                <div key={run.id} className="flex items-start gap-3 text-sm">
                    <Spinner className="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p className="font-medium">
                            {titles[run.pipeline] ?? 'Working on your website'}
                        </p>
                        {run.subject && (
                            <Link
                                href={
                                    run.subject_id
                                        ? `/content/${run.subject_id}`
                                        : '/calendar'
                                }
                                className="mt-1 block text-muted-foreground underline underline-offset-4"
                            >
                                {run.subject}
                            </Link>
                        )}
                    </div>
                </div>
            ))}
            {work.failed.map((run) => (
                <div key={run.id} className="flex items-start gap-3 text-sm">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
                    <div>
                        <p className="font-medium">
                            {run.subject
                                ? `Work on “${run.subject}” stopped`
                                : 'Some content work could not finish'}
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            Your saved content is safe.{' '}
                            <Link
                                href={
                                    run.subject_id
                                        ? `/content/${run.subject_id}`
                                        : '/calendar'
                                }
                                className="underline underline-offset-4"
                            >
                                Open this work
                            </Link>
                        </p>
                        {run.message && (
                            <details className="mt-2 text-xs text-muted-foreground">
                                <summary className="cursor-pointer">
                                    Details
                                </summary>
                                <p className="mt-2">{run.message}</p>
                            </details>
                        )}
                    </div>
                </div>
            ))}
        </section>
    );
}
function Setup({
    checklist,
    projectId,
}: {
    checklist: Step[];
    projectId: string;
}) {
    const remaining = checklist.filter((step) => !step.done);

    return (
        <details className={`${workspacePanelClass} p-5`}>
            <summary className="cursor-pointer text-sm font-medium">
                Your business setup
                {remaining.length > 0
                    ? ` · ${remaining.length} steps remaining`
                    : ''}
            </summary>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                {remaining.map((step) => (
                    <div key={step.key}>
                        <p className="text-sm font-medium">{step.label}</p>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {step.blocked_by ?? step.detail}
                        </p>
                        {step.action && !step.locked && (
                            <Link
                                href={step.action}
                                className="mt-2 inline-block text-xs underline underline-offset-4"
                            >
                                {step.action_label}
                            </Link>
                        )}
                    </div>
                ))}
            </div>
            <div className="mt-4 flex flex-wrap gap-4 border-t pt-4 text-xs">
                <Link href="/brief" className="underline">
                    Business brief
                </Link>
                <Link
                    href={`/projects/${projectId}/edit`}
                    className="underline"
                >
                    Business settings
                </Link>
                <Link href="/channels" className="underline">
                    Website connection
                </Link>
                <Link href="/pages" className="underline">
                    Existing pages
                </Link>
            </div>
        </details>
    );
}
function LoadingPanel() {
    return (
        <div
            className={`${workspacePanelClass} flex min-h-24 items-center gap-2 p-6 text-sm text-muted-foreground`}
        >
            <Spinner className="size-4" /> Loading your latest work…
        </div>
    );
}
