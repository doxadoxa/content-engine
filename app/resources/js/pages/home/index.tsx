import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUp,
    MessagesSquare,
    BadgeCheck,
    BookMarked,
    CalendarDays,
    Check,
    ChevronRight,
    DoorOpen,
    LayoutList,
    ListChecks,
    Lock,
    MessageSquareQuote,
    Sofa,
    Tag,
    TriangleAlert,
} from 'lucide-react';
import type { ComponentType } from 'react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';
import AppLogoIcon from '@/components/app-logo-icon';
import type { ChatSummary } from '@/components/chat-thread';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { postJson } from '@/lib/json';
import { index as approvalsIndex } from '@/routes/approvals';
import {
    index as chatIndex,
    show as showChat,
    store as startChat,
} from '@/routes/assistant';
import { index as calendarIndex } from '@/routes/calendar';
import { index as channelsIndex } from '@/routes/channels';
import { show as contentShow } from '@/routes/content';
import { plan as planArticles } from '@/routes/content';
import { store as writeArticle } from '@/routes/content/articles';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { show as showOnboarding } from '@/routes/onboarding';
import { index as socialIndex } from '@/routes/social';
import { store as storeIdea } from '@/routes/studio/ideas';
import { index as visibilityIndex } from '@/routes/visibility';

/**
 * One measure for every band on the page.
 *
 * The composer sets it and the rest follow, because a column of cards that do
 * not share an edge reads as several screens stacked — which is the thing this
 * screen exists to stop being.
 */
const BAND = 'mx-auto w-full max-w-[46rem]';

type Step = {
    key: string;
    group: string;
    label: string;
    detail: string;
    done: boolean;
    locked: boolean;
    blocked_by: string | null;
    action: string | null;
    action_label: string | null;
};

type Kind = { value: string; label: string; channels: string[] };

type Needs = {
    conversations: number;
    longest_wait_seconds: number | null;
    reply_drafts: number;
    social_drafts: number;
    article_drafts: number;
    article_approvals: number;
    dead_deliveries: number;
    total: number;
};

type Metric = {
    key: string;
    label: string;
    unit: 'per_day' | 'ratio';
    measured: boolean;
    current: number | null;
    change: number | null;
    direction: 'up' | 'down' | 'flat' | null;
    points: { day: string; value: number | null }[];
};

type Figures = {
    visibility: {
        score: number | null;
        last_asked_on: string | null;
        monitored_prompts: number;
    };
    audience?: Metric;
    visitors?: Metric;
};

type Half = {
    planned: number;
    drafted: number;
    approved: number;
    published: number;
    month_proposed?: boolean;
};

type Refusals = {
    week_start: string;
    entries: {
        code: string;
        label: string;
        detail: string;
        at: string | null;
    }[];
    planned: number | null;
    ceiling: number | null;
    floor: number | null;
    nothing_to_report: boolean;
    severity: 'clear' | 'noted' | 'alarm';
    summary: string;
};

type Run = {
    id: string;
    pipeline: string;
    action: string | null;
    subject: string | null;
    subject_id: string | null;
    total_steps: number;
    done_steps: number;
    current_step: string | null;
};

type Work = {
    launching: boolean;
    active: Run[];
    failed: {
        id: string;
        pipeline: string;
        subject: string | null;
        step: string | null;
        message: string | null;
    }[];
};

type Props = {
    project: { name: string; site_name: string } | null;
    chats?: ChatSummary[];
    hasProjects: boolean;
    checklist: Step[];
    kinds: Kind[];
    refusals?: Refusals;
    needs?: Needs;
    figures?: Figures;
    halves?: { articles: Half; social: Half };
    work?: Work;
    health?: { healthy: boolean; reason: string | null };
};

/**
 * The landing screen, and now the only one.
 *
 * Act, owe, measure, account: a box to type into, what needs a person across
 * both halves of the engine, the three figures that change a decision, what each
 * half has, and what the engine refused to do. This replaced Home, Today and
 * Dashboard — three screens that all read as "dashboard" because they were one
 * question asked at three levels of anxiety.
 *
 * **No page header, and that is the point.** A page titled "Home" above a box
 * asking what you would like to make is a label restating what the control
 * already says. The composer's own heading is the page's `h1`, which keeps the
 * accessible name every other screen in the shell has while spending none of
 * the fold on it.
 */
export default function Home({
    project,
    hasProjects,
    checklist,
    kinds,
    chats,
    refusals,
    needs,
    figures,
    halves,
    work,
    health,
}: Props) {
    const busy = (work?.active.length ?? 0) > 0 || (work?.launching ?? false);

    // Poll only while work is active, slow down repeated requests, and do not
    // spend server time refreshing a tab nobody can see. A visibility change
    // resets the backoff so returning operators get current state promptly.
    //
    // This came off the dashboard with the run panel and was, for one commit,
    // left behind — which made the panel a screenshot: it showed a run at step
    // zero and stayed there until somebody reloaded by hand, so the honest
    // reading of the screen was that the engine had hung.
    useEffect(() => {
        if (!busy) {
            return;
        }

        let cancelled = false;
        let attempts = 0;
        let timer: number | undefined;

        const schedule = () => {
            const baseDelay = document.hidden
                ? 30_000
                : Math.min(20_000, 5_000 * 2 ** Math.min(attempts, 2));

            timer = window.setTimeout(
                poll,
                baseDelay + Math.floor(Math.random() * 1_000),
            );
        };

        const poll = () => {
            if (cancelled) {
                return;
            }

            if (document.hidden) {
                schedule();

                return;
            }

            router.reload({
                only: ['work', 'needs', 'halves'],
                onFinish: () => {
                    if (!cancelled) {
                        attempts += 1;
                        schedule();
                    }
                },
            });
        };

        const onVisibilityChange = () => {
            window.clearTimeout(timer);

            if (document.hidden) {
                schedule();

                return;
            }

            attempts = 0;
            poll();
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        schedule();

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [busy]);

    if (project === null) {
        return <NoProject hasProjects={hasProjects} />;
    }

    return (
        <>
            <Head title="Home" />

            <WorkspacePage width="reading">
                {/*
                    Above the composer, and the only thing allowed to be: an
                    engine that is not running work outranks a box asking what
                    to make. Both bands return null when there is nothing to
                    say, which is most of the time.
                */}
                <Deferred data="health" fallback={() => null}>
                    <StoppedNotice health={health} />
                </Deferred>

                <Composer
                    kinds={kinds}
                    siteName={project.site_name}
                    chats={chats ?? []}
                />

                <WorkInProgress work={work} />

                {/*
                    `Deferred` waits for the prop and renders the fallback while
                    it is in flight; it does not hand the prop to its children.
                    So the value has to be passed in — without it the prop was
                    permanently undefined and the band showed its skeleton
                    forever, which looks exactly like a slow request that never
                    lands.
                */}
                <Deferred data="needs" fallback={<BandSkeleton />}>
                    <NeedsYou needs={needs} />
                </Deferred>

                <Deferred data="figures" fallback={<FiguresSkeleton />}>
                    <FigureStrip figures={figures} />
                </Deferred>

                <Checklist steps={checklist} />

                <Deferred data="halves" fallback={<BandSkeleton />}>
                    <Halves halves={halves} />
                </Deferred>

                {/* Never deferred. See `RefusalLedger`. */}
                {refusals && <RefusalBand refusals={refusals} />}
            </WorkspacePage>
        </>
    );
}

/** The lucide face of each `PostKind`, at one stroke weight and one size. */
const KIND_ICONS: Record<string, ComponentType<{ className?: string }>> = {
    take: MessageSquareQuote,
    how_to: ListChecks,
    proof: BadgeCheck,
    behind: DoorOpen,
    life: Sofa,
    offer: Tag,
};

/**
 * The shared chip, so the two rows read as one control rather than two.
 *
 * `rounded-none` merges away against `rounded-full`, but the variant-prefixed
 * `first:`/`last:` radii live in their own merge group and have to be restated
 * or the end chips keep square shoulders. The selected colours are literals
 * rather than `bg-accent`, which renders the *pre-Avyo* lavender inside the
 * shell — see the note on the brand aliases in `app.css`.
 */
const CHIP =
    'h-8 gap-1.5 rounded-full border border-border/80 bg-transparent px-3 text-xs font-medium text-muted-foreground shadow-none first:rounded-full last:rounded-full hover:bg-muted/60 hover:text-foreground';

/**
 * What a sentence can become.
 *
 * **Ask is the default, and the other two are shortcuts.** This box used to be a
 * form: whatever you typed became a post, immediately, with no discussion — so
 * "how to clean a door" started writing an article nobody had agreed to and the
 * first anybody knew of it was a progress bar with a module name on it. Asking
 * is the honest default because most sentences are not decisions yet, and the
 * assistant is allowed to say so and ask what you are actually after.
 *
 * The two direct intents stay because somebody who already knows what they want
 * should not have to negotiate for it. Both consume the text in the box, which
 * is what earns them a place in it.
 */
const INTENTS = [
    { value: 'ask', label: 'Ask', icon: MessagesSquare },
    { value: 'post', label: 'A post', icon: LayoutList },
    { value: 'article', label: 'An article', icon: BookMarked },
] as const;

/** What a channel is called, rather than what the column stores. */
const CHANNEL_NAMES: Record<string, string> = {
    instagram: 'Instagram',
    threads: 'Threads',
    x: 'X',
};

/**
 * One post, from a sentence.
 *
 * The chip the reference product puts at the top of its home screen, with the
 * one difference that matters: this one has an engine behind it. Every idea in
 * this system used to come from a model reading the website once a month, so
 * the thing that happened this morning had nowhere to go.
 *
 * **One box, not a form that unfolds.** What was here before was a single-line
 * input that inserted a textarea, a five-across radiogroup and a native date
 * picker into the page on focus — roughly 230px of layout shift under the
 * cursor, with no way back and no `form` element, so Enter did nothing and the
 * only visible control was a paper plane whose `onClick` was `setOpen(true)`.
 * The shape below is the same three decisions in one resting card: what to say,
 * what kind of post it is, and when.
 *
 * The kind is a real choice rather than a decoration, because the kind decides
 * the channels — the same rule a proposal is held to, so a hand-written idea
 * cannot do the one thing every planned idea is forbidden. It stays six visible
 * chips rather than a collapsed select for the reason `PostKind` records in its
 * own docblock: a shape nobody is asked to choose becomes one shape, and the
 * first month of this engine came back twenty ideas that were all tips.
 */
function Composer({
    kinds,
    siteName,
    chats,
}: {
    kinds: Kind[];
    siteName: string;
    chats: ChatSummary[];
}) {
    const formRef = useRef<HTMLFormElement>(null);
    const promptRef = useRef<HTMLTextAreaElement>(null);
    const coarsePointer = useRef(false);

    const [prompt, setPrompt] = useState('');
    const [intent, setIntent] = useState<'ask' | 'post' | 'article'>('ask');
    const [kind, setKind] = useState(kinds[0]?.value ?? 'take');
    const [when, setWhen] = useState('today');
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        coarsePointer.current = window.matchMedia('(pointer: coarse)').matches;
    }, []);

    // `field-sizing: content` does the growing wherever it is supported and
    // this does nothing. Where it is not, a textarea left at `rows={1}` stays
    // one line tall however much is typed into it, which is the worst version
    // of this control rather than a degraded one.
    useLayoutEffect(() => {
        const element = promptRef.current;

        if (element === null || CSS.supports('field-sizing', 'content')) {
            return;
        }

        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, 192)}px`;
    }, [prompt]);

    const chosen = useMemo(
        () => kinds.find((entry) => entry.value === kind),
        [kinds, kind],
    );

    async function submit() {
        if (pending) {
            return;
        }

        const text = prompt.trim();

        // The server's floor, checked here. Both endpoints require `min:3`, and
        // an empty-string check let a two-character post through the button and
        // brought the rule back as a red toast after a round trip — an error
        // shown as something transient, in the wrong place, about a field the
        // operator had already stopped looking at.
        if (text.length < 3) {
            setError(
                intent === 'post'
                    ? 'Give it a sentence — three characters is not a post.'
                    : 'Give it a sentence — three characters is not much to go on.',
            );
            promptRef.current?.focus();

            return;
        }

        setError(null);
        setPending(true);

        // Asking starts a conversation and lands on it. The turn is
        // synchronous and can run several tool calls, so this request is slow
        // by design — the alternative was answering in place on a screen with
        // no address, which is how the first version of this produced a chat
        // nobody could get back to.
        if (intent === 'ask') {
            router.post(
                startChat().url,
                { message: text },
                {
                    onFinish: () => setPending(false),
                    onError: () =>
                        setError('That did not go through. Try again.'),
                },
            );

            return;
        }

        // An article is a page visit rather than a fetch: it lands in the
        // content plan and the operator should land there with it, where the
        // social half has a board of its own to arrive on.
        if (intent === 'article') {
            router.post(
                writeArticle().url,
                { prompt: text },
                {
                    onFinish: () => setPending(false),
                    onSuccess: () => setPrompt(''),
                    // Without this a topic past the server's limit came back
                    // 422 and the spinner simply stopped: no message, no toast,
                    // the sentence still sitting in the box.
                    onError: (errors) =>
                        setError(
                            typeof errors.prompt === 'string'
                                ? errors.prompt
                                : 'That did not go through. Try again.',
                        ),
                },
            );

            return;
        }

        const result = await postJson<{ idea: { id: string; title: string } }>(
            storeIdea().url,
            { thesis: text, kind, date: dateFor(when) },
        );

        setPending(false);

        if (!result.ok) {
            setError(result.message);

            return;
        }

        setPrompt('');
        toast.success('Writing it now — opening the board.');
        router.get(socialIndex());
    }

    const onKeyDown = useCallback(
        (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
            if (event.key === 'Escape') {
                event.currentTarget.blur();

                return;
            }

            if (event.key !== 'Enter') {
                return;
            }

            // Enter mid-composition commits the IME candidate, not the post.
            // Without this a Japanese or Chinese draft posts itself on the
            // first character anybody confirms.
            if (event.nativeEvent.isComposing) {
                return;
            }

            // On a phone the Return key is how a paragraph gets written; there
            // is a visible submit button an inch away for the other job.
            if (event.shiftKey || event.altKey || coarsePointer.current) {
                return;
            }

            event.preventDefault();
            formRef.current?.requestSubmit();
        },
        [],
    );

    return (
        <section
            aria-labelledby="composer-heading"
            className={`relative isolate ${BAND} pt-4 sm:pt-8`}
        >
            <div className="mb-5 flex flex-col items-center gap-2.5">
                <AppLogoIcon className="size-9" />
                <h1
                    id="composer-heading"
                    className="text-center text-sm font-medium text-muted-foreground"
                >
                    What should {siteName} publish next?
                </h1>
            </div>

            <ComposerGlow />

            {/*
                The hairline is the focus indicator and the ornament at once —
                but opacity alone is not a ≥3:1 change, so the ring carries the
                part a keyboard user is entitled to.
            */}
            <form
                ref={formRef}
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
                className="relative z-10 rounded-[1.75rem] bg-[linear-gradient(140deg,rgba(214,83,60,0.40),rgba(243,207,106,0.30)_38%,rgba(49,85,165,0.28))] p-px opacity-70 transition-opacity duration-200 focus-within:opacity-100 focus-within:ring-2 focus-within:ring-terracotta/45 focus-within:ring-offset-2 focus-within:ring-offset-background motion-reduce:transition-none"
            >
                <div className="rounded-[calc(1.75rem-1px)] bg-card shadow-[0_1px_2px_rgba(23,53,47,0.04),0_20px_56px_-12px_rgba(23,53,47,0.14)] forced-colors:border forced-colors:border-[ButtonBorder]">
                    <div className="flex items-end gap-2 px-4 pt-4 pb-1 sm:px-5 sm:pt-5">
                        <label htmlFor="composer-prompt" className="sr-only">
                            {intent === 'post'
                                ? `What should ${siteName} post?`
                                : `What should ${siteName} write about?`}
                        </label>
                        <textarea
                            id="composer-prompt"
                            ref={promptRef}
                            rows={1}
                            value={prompt}
                            maxLength={intent === 'article' ? 255 : 5000}
                            aria-describedby={
                                error === null
                                    ? 'composer-consequence'
                                    : 'composer-error'
                            }
                            aria-invalid={error !== null}
                            placeholder={
                                intent === 'ask'
                                    ? `Ask about ${siteName}'s marketing, or tell me what you want to do…`
                                    : intent === 'post'
                                      ? 'Something that happened today, or a point worth making…'
                                      : 'A question customers ask, or a topic worth ranking for…'
                            }
                            onChange={(event) => setPrompt(event.target.value)}
                            onKeyDown={onKeyDown}
                            className="[field-sizing:content] max-h-48 min-h-[2.75rem] w-full resize-none border-0 bg-transparent py-2.5 text-base leading-relaxed text-foreground shadow-none outline-none placeholder:text-muted-foreground/80 focus-visible:ring-0"
                        />
                        <button
                            type="submit"
                            disabled={pending}
                            aria-busy={pending}
                            className="mb-1.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-[linear-gradient(135deg,var(--color-terracotta),var(--color-terracotta-deep))] text-white shadow-[0_1px_2px_rgba(23,53,47,0.2),0_6px_16px_-4px_rgba(214,83,60,0.5)] transition-[transform,box-shadow] duration-150 hover:shadow-[0_1px_2px_rgba(23,53,47,0.2),0_8px_22px_-4px_rgba(214,83,60,0.6)] focus-visible:ring-2 focus-visible:ring-terracotta focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-none active:scale-[0.96] disabled:opacity-60 motion-reduce:transition-none motion-reduce:active:scale-100 dark:bg-[linear-gradient(135deg,var(--color-honey),#e0b44f)] dark:text-forest"
                        >
                            {pending ? (
                                <Spinner className="size-4" />
                            ) : (
                                <ArrowUp
                                    className="size-5"
                                    strokeWidth={2.25}
                                    aria-hidden="true"
                                />
                            )}
                            <span className="sr-only">
                                {pending
                                    ? 'Working'
                                    : intent === 'ask'
                                      ? 'Send'
                                      : intent === 'post'
                                        ? 'Write the post'
                                        : 'Write the article'}
                            </span>
                        </button>
                    </div>

                    {/*
                        Which half of the engine is being asked, and the reason
                        the row below it appears at all. This box used to say
                        "post" in its heading, its placeholder and its only
                        taxonomy — a fair description of `storeIdea`, and a
                        narrow one of a product that also researches keywords,
                        writes articles, audits the site and measures how it
                        shows up in AI answers.
                    */}
                    <ToggleGroup
                        type="single"
                        value={intent}
                        onValueChange={(value) => {
                            // Radix allows deselecting the active item in a
                            // single group, and an empty intent has nowhere to
                            // submit — that, and only that, is what this
                            // rejects. Listing the two shortcuts here left
                            // `ask` unreachable once either was chosen, so the
                            // assistant could be left and never returned to.
                            if (value !== '') {
                                setIntent(value as 'ask' | 'post' | 'article');
                            }
                        }}
                        aria-label="What should the engine make?"
                        className="flex w-full flex-wrap justify-start gap-1.5 border-b border-border/70 px-3 pt-1 pb-2.5 sm:px-4"
                    >
                        {INTENTS.map((option) => (
                            <ToggleGroupItem
                                key={option.value}
                                value={option.value}
                                className={`${CHIP} data-[state=on]:border-terracotta/45 data-[state=on]:bg-terracotta-wash data-[state=on]:text-forest dark:data-[state=on]:bg-terracotta-shade dark:data-[state=on]:text-cream`}
                            >
                                <option.icon
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                {option.label}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>

                    {intent === 'post' && (
                        <ToggleGroup
                            type="single"
                            value={kind}
                            onValueChange={(value) => {
                                // Radix allows deselecting the active item in a
                                // single group. The server has no such state, so an
                                // empty value here is a 422 later.
                                if (value !== '') {
                                    setKind(value);
                                }
                            }}
                            aria-label="What kind of post is it?"
                            className="flex w-full flex-wrap justify-start gap-1.5 px-3 pt-1 pb-3 sm:px-4"
                        >
                            {kinds.map((option) => {
                                const Icon = KIND_ICONS[option.value];

                                return (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                        // Two things are load-bearing here.
                                        //
                                        // `rounded-none` merges away against
                                        // `rounded-full`, but the variant-prefixed
                                        // `first:`/`last:` radii live in their own
                                        // merge group and have to be restated or
                                        // the end chips keep square shoulders.
                                        //
                                        // And the selected colours are literals
                                        // rather than `bg-accent`, which renders
                                        // the *pre-Avyo* lavender inside the shell:
                                        // `@theme` substitutes `--color-accent:
                                        // var(--accent)` at `:root`, so the value
                                        // that inherits down is already resolved
                                        // and `.product-shell`'s override never
                                        // reaches the utility. See the note on the
                                        // brand aliases in `app.css`.
                                        className="h-8 gap-1.5 rounded-full border border-border/80 bg-transparent px-3 text-xs font-medium text-muted-foreground shadow-none first:rounded-full last:rounded-full hover:bg-muted/60 hover:text-foreground data-[state=on]:border-terracotta/45 data-[state=on]:bg-terracotta-wash data-[state=on]:text-forest dark:data-[state=on]:bg-terracotta-shade dark:data-[state=on]:text-cream"
                                    >
                                        {Icon && (
                                            <Icon
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {option.label}
                                    </ToggleGroupItem>
                                );
                            })}
                        </ToggleGroup>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border/70 px-4 py-2.5 sm:px-5">
                        {error === null ? (
                            <p
                                id="composer-consequence"
                                aria-live="polite"
                                className="min-w-0 text-xs text-muted-foreground"
                            >
                                {intent === 'ask'
                                    ? 'I can look up how you are doing, and draft things. I never publish.'
                                    : intent === 'article'
                                      ? 'One article, researched against your site and written with its GEO layer. Nothing publishes.'
                                      : chosen === undefined
                                        ? null
                                        : consequence(chosen)}
                            </p>
                        ) : (
                            <p
                                id="composer-error"
                                role="alert"
                                className="min-w-0 text-xs font-medium text-terracotta-deep dark:text-[#e58a76]"
                            >
                                {error}
                            </p>
                        )}

                        {intent === 'post' && (
                            <Select value={when} onValueChange={setWhen}>
                                <SelectTrigger
                                    aria-label="When should it publish?"
                                    className="h-8! w-auto gap-1.5 rounded-full border-border/80 bg-transparent px-3 text-xs"
                                >
                                    <CalendarDays
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="today">Today</SelectItem>
                                    <SelectItem value="tomorrow">
                                        Tomorrow
                                    </SelectItem>
                                    <SelectItem value="weekend">
                                        This weekend
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                </div>
            </form>

            <p className="mt-2.5 hidden text-center text-[11px] text-muted-foreground/70 sm:block">
                Enter to write · Shift + Enter for a new line
            </p>

            <RecentChats chats={chats} />
        </section>
    );
}

/**
 * Light on the paper, rather than a sticker behind a card.
 *
 * Terracotta and honey carry the mass because they sit beside `#f3ecdd` on the
 * hue wheel and read as the page being lit; cobalt is the only genuinely cool
 * note here and is held low, because at the reference's saturation a blue this
 * size over warm cream turns grey-green. Weighted up and to the left rather
 * than centred — a symmetric halo reads as an effect, an off-centre one as a
 * source.
 *
 * Not animated. The global `prefers-reduced-motion` block zeroes transition
 * durations, so a moving gradient would snap for those users rather than
 * degrade, and this is decoration either way.
 */
function ComposerGlow() {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute -inset-x-10 -top-10 -bottom-8 z-0 overflow-hidden"
        >
            <div className="absolute top-16 left-1/2 h-44 w-[36rem] -translate-x-1/2 rounded-full bg-terracotta/22 blur-[70px] dark:bg-terracotta/26" />
            <div className="absolute top-10 right-[10%] size-52 rounded-full bg-honey/40 blur-[64px] dark:bg-honey/20" />
            <div className="absolute top-28 left-[12%] size-56 rounded-full bg-cobalt/13 blur-[76px] dark:bg-cobalt/20" />
        </div>
    );
}

/**
 * What the chosen kind is about to do, in one place.
 *
 * This fact used to be printed on all six tiles as `instagram · threads` and
 * then a seventh time in prose underneath. Once is enough, and once means it
 * can be a sentence rather than a lowercase enum value.
 */
function consequence(kind: Kind): string {
    const names = kind.channels.map(
        (channel) => CHANNEL_NAMES[channel] ?? channel,
    );
    const list =
        names.length <= 1
            ? (names[0] ?? '')
            : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

    return `${kind.label} → ${list}. ${
        kind.channels.length === 1
            ? 'One draft'
            : `${kind.channels.length} drafts`
    } and their pictures. Nothing publishes.`;
}

/**
 * The chosen day, in the operator's timezone.
 *
 * `toISOString()` is UTC: at UTC+3 before 03:00 it pre-filled yesterday and at
 * UTC-5 after 19:00 it pre-filled tomorrow, silently, on the field that decides
 * when a post goes out. `en-CA` is the locale whose date format is already
 * `YYYY-MM-DD`, which is what the server parses.
 */
function dateFor(when: string): string {
    const date = new Date();

    if (when === 'tomorrow') {
        date.setDate(date.getDate() + 1);
    }

    if (when === 'weekend') {
        // The coming Saturday, and next Saturday once it is the weekend.
        date.setDate(date.getDate() + ((6 - date.getDay() + 7) % 7 || 7));
    }

    return new Intl.DateTimeFormat('en-CA').format(date);
}

/**
 * What is set up, and what is genuinely not reachable yet.
 *
 * A padlock here means a real prerequisite, not a position in the list — see
 * `App\Social\ActivationChecklist` for why that divergence from the reference
 * is the whole point of the component.
 *
 * Grouped and collapsed, because six flat rows put the same visual weight on
 * "read the website", which happens once, as on "approve a post", which is the
 * loop. The first unfinished group opens; the finished ones stay shut with
 * their count, which is the only thing anybody wants from them.
 */
function Checklist({ steps }: { steps: Step[] }) {
    const groups = useMemo(() => {
        const order: string[] = [];
        const byName = new Map<string, Step[]>();

        for (const step of steps) {
            if (!byName.has(step.group)) {
                byName.set(step.group, []);
                order.push(step.group);
            }

            byName.get(step.group)?.push(step);
        }

        return order.map((name) => ({
            name,
            steps: byName.get(name) ?? [],
        }));
    }, [steps]);

    const firstUnfinished = groups.find((group) =>
        group.steps.some((step) => !step.done),
    );

    const [open, setOpen] = useState<string | null>(
        firstUnfinished?.name ?? null,
    );

    const done = steps.filter((step) => step.done).length;

    if (steps.length === 0 || done === steps.length) {
        return null;
    }

    return (
        <section
            aria-labelledby="checklist-heading"
            className={`${workspacePanelClass} ${BAND} overflow-hidden`}
        >
            <header className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
                <h2
                    id="checklist-heading"
                    className="text-sm font-semibold tracking-tight"
                >
                    Set up
                </h2>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {done} of {steps.length} done
                </span>
            </header>

            <div className="divide-y">
                {groups.map((group) => (
                    <ChecklistGroup
                        key={group.name}
                        name={group.name}
                        steps={group.steps}
                        open={open === group.name}
                        onOpenChange={(next) =>
                            setOpen(next ? group.name : null)
                        }
                    />
                ))}
            </div>
        </section>
    );
}

function ChecklistGroup({
    name,
    steps,
    open,
    onOpenChange,
}: {
    name: string;
    steps: Step[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const done = steps.filter((step) => step.done).length;
    const complete = done === steps.length;
    const current = steps.find((step) => !step.done && !step.locked);

    return (
        <Collapsible open={open} onOpenChange={onOpenChange}>
            <CollapsibleTrigger className="group flex w-full items-center gap-3 px-5 py-3.5 text-left transition-colors hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none">
                <ChevronRight
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-90 motion-reduce:transition-none"
                />
                <span
                    className={`min-w-0 flex-1 truncate text-sm font-medium ${complete ? 'text-muted-foreground' : ''}`}
                >
                    {name}
                </span>
                <Progress
                    value={(done / steps.length) * 100}
                    aria-label={`${name}: ${done} of ${steps.length} done`}
                    className="h-1.5 w-20 shrink-0 sm:w-24"
                    indicatorClassName={complete ? 'bg-sage' : 'bg-terracotta'}
                />
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {done}/{steps.length}
                </span>
            </CollapsibleTrigger>

            <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                <ol className="grid gap-1.5 px-3 pb-3">
                    {steps.map((step) => (
                        <ChecklistRow
                            key={step.key}
                            step={step}
                            current={step.key === current?.key}
                        />
                    ))}
                </ol>
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * Three states, one signal each.
 *
 * What was here put a check, a line-through *and* muted text on a done row —
 * three ways of saying one thing, and on cream the strike over 13px muted text
 * is mostly noise. The current step is the only row with a button, which is
 * what lets that button be the primary one on the screen.
 */
function ChecklistRow({ step, current }: { step: Step; current: boolean }) {
    const row = (
        <div
            className={`flex flex-wrap items-center justify-between gap-3 rounded-[calc(var(--radius)-1px)] px-4 py-3 ${
                current ? 'bg-card' : ''
            } ${step.locked ? 'opacity-60' : ''}`}
        >
            <div className="flex min-w-0 items-start gap-3">
                <span className="mt-0.5 shrink-0">
                    {step.done ? (
                        <>
                            <Check
                                className="size-4 text-sage"
                                aria-hidden="true"
                            />
                            <span className="sr-only">Done</span>
                        </>
                    ) : step.locked ? (
                        <>
                            <Lock
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span className="sr-only">Not available yet</span>
                        </>
                    ) : (
                        <span
                            className={`block size-4 rounded-full border-2 ${current ? 'border-terracotta' : 'border-muted-foreground/40'}`}
                            aria-hidden="true"
                        />
                    )}
                </span>
                <div className="min-w-0">
                    <p
                        className={`text-sm font-medium ${step.done ? 'text-muted-foreground' : ''}`}
                    >
                        {step.label}
                    </p>
                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                        {step.blocked_by ?? step.detail}
                    </p>
                </div>
            </div>
            {step.action !== null && !step.locked && (
                <Button
                    asChild
                    variant={current ? 'default' : 'outline'}
                    className="h-8 shrink-0 px-3 text-xs"
                >
                    <Link href={step.action}>{step.action_label}</Link>
                </Button>
            )}
        </div>
    );

    if (!current) {
        return <li>{row}</li>;
    }

    // The same hairline the composer wears, so the one thing to do next and the
    // one thing to type into read as the same idea rather than two treatments.
    return (
        <li className="rounded-[var(--radius)] bg-[linear-gradient(120deg,rgba(214,83,60,0.45),rgba(243,207,106,0.30))] p-px forced-colors:border forced-colors:border-[Highlight]">
            {row}
        </li>
    );
}

/**
 * Everything with a person's name on it, in one count.
 *
 * The band the whole screen is arranged around, and the only one that answers
 * the question people actually open this product to ask: can I close the tab.
 *
 * **Both halves, deliberately.** These counts used to be split across three
 * screens — social drafts on Home, reply drafts on Today, article drafts and
 * approvals on the dashboard — and the two that overlapped disagreed, because
 * one was scoped to `social()` and the other to `roots()` with nothing on either
 * screen saying so. An operator's morning does not care that articles and posts
 * are different subsystems.
 */
function NeedsYou({ needs }: { needs?: Needs }) {
    if (needs === undefined) {
        return <BandSkeleton />;
    }

    // `total` counts what a person has to act on and leaves the dead
    // deliveries out, so a project whose only news is a failed transport still
    // has something to say here rather than reading as a quiet morning.
    if (needs.total === 0 && needs.dead_deliveries === 0) {
        return (
            <section className={`${workspacePanelClass} ${BAND} px-5 py-6`}>
                <p className="text-center text-sm text-muted-foreground">
                    Nothing needs you. The engine will say when something does.
                </p>
            </section>
        );
    }

    // Approved-and-not-gone-out and dead deliveries are the two that cost money
    // per day, so they are the two allowed to shout.
    const stuck = needs.dead_deliveries > 0 || needs.article_approvals > 0;

    const lines: string[] = [];

    if (needs.conversations > 0) {
        lines.push(
            `${needs.conversations} ${needs.conversations === 1 ? 'reply' : 'replies'} waiting` +
                (needs.longest_wait_seconds === null
                    ? ''
                    : ` · longest ${waited(needs.longest_wait_seconds)}`),
        );
    }

    if (needs.reply_drafts > 0) {
        lines.push(`${needs.reply_drafts} reply drafts ready to send`);
    }

    if (needs.social_drafts > 0) {
        lines.push(`${needs.social_drafts} posts in draft`);
    }

    if (needs.article_drafts > 0) {
        lines.push(`${needs.article_drafts} articles in draft`);
    }

    return (
        <section
            aria-labelledby="needs-heading"
            className={`${workspacePanelClass} ${BAND} overflow-hidden`}
        >
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
                <h2
                    id="needs-heading"
                    className="text-sm font-semibold tracking-tight"
                >
                    {needs.total}{' '}
                    {needs.total === 1 ? 'thing needs' : 'things need'} you
                </h2>
                <Button
                    asChild
                    size="sm"
                    variant="outline"
                    className="h-8 text-xs"
                >
                    <Link href={approvalsIndex()} prefetch>
                        Open the queue
                        <ArrowRight className="size-3.5" aria-hidden="true" />
                    </Link>
                </Button>
            </header>

            <div className="grid gap-2 px-5 py-4">
                {lines.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {lines.join(' · ')}
                    </p>
                )}

                {stuck && (
                    <div className="grid gap-1.5">
                        {needs.article_approvals > 0 && (
                            <Stuck>
                                {needs.article_approvals} approved{' '}
                                {needs.article_approvals === 1
                                    ? 'article has'
                                    : 'articles have'}{' '}
                                not gone out.
                            </Stuck>
                        )}
                        {needs.dead_deliveries > 0 && (
                            <Stuck>
                                {needs.dead_deliveries}{' '}
                                {needs.dead_deliveries === 1
                                    ? 'delivery'
                                    : 'deliveries'}{' '}
                                gave up.{' '}
                                <Link
                                    href={deliveriesIndex()}
                                    className="underline underline-offset-4"
                                >
                                    See why
                                </Link>
                                .
                            </Stuck>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}

/** A count that is costing something per day it stays true. */
function Stuck({ children }: { children: React.ReactNode }) {
    return (
        <p className="flex items-start gap-2 text-sm font-medium text-terracotta-deep dark:text-[#e58a76]">
            <TriangleAlert
                className="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
            <span>{children}</span>
        </p>
    );
}

/**
 * Three figures, and only three.
 *
 * Published counts, targeted search volume, citation coverage and impressions
 * were all on the dashboard and none of them is here, because each is a fact
 * about the past that changes no decision and each already sits on the screen
 * that owns it. What is left is: do the assistants know we exist, is the
 * audience growing, is anybody coming back.
 *
 * The whole strip disappears rather than rendering three dashes. A grid of
 * zeroes reads as a broken product rather than a young one, and a card that
 * reads "—" forever teaches an operator to stop reading the row it is in.
 */
function FigureStrip({ figures }: { figures?: Figures }) {
    if (figures === undefined) {
        return <FiguresSkeleton />;
    }

    const audience = figures.audience;
    const visitors = figures.visitors;
    const anything =
        figures.visibility.score !== null ||
        audience?.measured === true ||
        visitors?.measured === true;

    if (!anything) {
        return (
            <section className={`${workspacePanelClass} ${BAND} px-5 py-6`}>
                <p className="text-center text-sm text-muted-foreground">
                    No figures yet — nothing has been measured.{' '}
                    <Link
                        href={channelsIndex()}
                        className="underline underline-offset-4"
                    >
                        Connect Analytics and a channel
                    </Link>{' '}
                    and they appear here.
                </p>
            </section>
        );
    }

    return (
        <section
            aria-label="How the project is doing"
            className={`${workspacePanelClass} ${BAND} grid gap-px overflow-hidden bg-border sm:grid-cols-3`}
        >
            <Figure
                label="AI visibility"
                value={
                    figures.visibility.score === null
                        ? null
                        : `${figures.visibility.score}%`
                }
                note={
                    figures.visibility.score === null
                        ? 'No prompt sweep has run yet'
                        : `${figures.visibility.monitored_prompts} prompts monitored`
                }
                href={visibilityIndex().url}
            />
            <MetricFigure label="Audience" metric={audience} />
            <MetricFigure label="Visitors" metric={visitors} />
        </section>
    );
}

function Figure({
    label,
    value,
    note,
    href,
    children,
}: {
    label: string;
    value: string | null;
    note: string;
    href?: string;
    children?: React.ReactNode;
}) {
    const body = (
        <>
            <p className="text-[11px] font-medium tracking-[0.12em] text-muted-foreground uppercase">
                {label}
            </p>
            {value === null ? (
                // Not a zero, and deliberately not styled like a number. §6
                // makes every vendor column nullable so that "the connection is
                // gone" and "the audience left" cannot look the same.
                <p className="mt-2 text-sm text-muted-foreground italic">
                    Not measured
                </p>
            ) : (
                <p className="mt-2 font-serif text-3xl tabular-nums">{value}</p>
            )}
            {children}
            <p className="mt-2 text-xs text-muted-foreground">{note}</p>
        </>
    );

    if (href === undefined) {
        return <div className="bg-card px-5 py-4">{body}</div>;
    }

    return (
        <Link
            href={href}
            className="bg-card px-5 py-4 transition-colors hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
        >
            {body}
        </Link>
    );
}

function MetricFigure({ label, metric }: { label: string; metric?: Metric }) {
    if (metric === undefined) {
        return (
            <Figure
                label={label}
                value={null}
                note="Nothing reports this yet"
            />
        );
    }

    return (
        <Figure
            label={label}
            value={
                metric.measured && metric.current !== null
                    ? `${Math.round(metric.current).toLocaleString()}/day`
                    : null
            }
            note={
                metric.change === null
                    ? 'Nothing to compare it with yet'
                    : `${metric.change > 0 ? '+' : ''}${(metric.change * 100).toFixed(0)}% on the fortnight before`
            }
        >
            <Sparkline points={metric.points} />
        </Figure>
    );
}

/**
 * The shape, with the holes left in.
 *
 * A day nobody measured draws no bar at all rather than a bar of height zero.
 * The whole reason `project_states` keeps a row a day is that the trend carries
 * the meaning, and a trend with unmeasured days flattened into it is a
 * different trend.
 */
function Sparkline({ points }: { points: Metric['points'] }) {
    const values = points
        .map((point) => point.value)
        .filter((value): value is number => value !== null);
    const peak = Math.max(1, ...values);

    return (
        <div className="mt-3 flex h-6 items-end gap-0.5" aria-hidden="true">
            {points.map((point) => (
                <div key={point.day} className="flex-1">
                    {point.value === null ? (
                        <div className="h-px w-full bg-border" />
                    ) : (
                        <div
                            className="w-full rounded-t-sm bg-sage/70"
                            style={{
                                height: `${Math.max(4, (point.value / peak) * 24)}px`,
                            }}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * The two jobs this engine does, said out loud.
 *
 * Nothing in the product used to name them on a landing screen, which is how a
 * half could stop without anybody noticing. The articles line is the one that
 * matters: a month of articles is chosen from keywords by a planning run that
 * fires once at onboarding and is on no schedule after it, so "45 planned, 0
 * published" is a sentence somebody needs to read.
 */
function Halves({ halves }: { halves?: { articles: Half; social: Half } }) {
    if (halves === undefined) {
        return <BandSkeleton />;
    }

    return (
        <section
            aria-labelledby="halves-heading"
            className={`${workspacePanelClass} ${BAND} overflow-hidden`}
        >
            <header className="border-b px-5 py-3.5">
                <h2
                    id="halves-heading"
                    className="text-sm font-semibold tracking-tight"
                >
                    What the engine has
                </h2>
            </header>

            <div className="divide-y">
                <HalfRow
                    label="Articles"
                    icon={<BookMarked className="size-4" aria-hidden="true" />}
                    half={halves.articles}
                    href={calendarIndex().url}
                    hrefLabel="Content plan"
                    // The verb this half never had. A month of articles is
                    // chosen from the research by a `planning` run that fires
                    // once inside `ProjectLaunch` and is on no schedule after
                    // it — so until this button there was no way to ask for a
                    // second month from anywhere in the product.
                    action={{
                        url: planArticles().url,
                        label: 'Plan next month',
                    }}
                />
                <HalfRow
                    label="Social"
                    icon={<LayoutList className="size-4" aria-hidden="true" />}
                    half={halves.social}
                    href={socialIndex().url}
                    hrefLabel="The board"
                />
            </div>
        </section>
    );
}

function HalfRow({
    label,
    icon,
    half,
    href,
    hrefLabel,
    action,
}: {
    label: string;
    icon: React.ReactNode;
    half: Half;
    href: string;
    hrefLabel: string;
    action?: { url: string; label: string };
}) {
    const parts = [
        `${half.planned} planned`,
        `${half.drafted} in draft`,
        `${half.approved} approved`,
        `${half.published} published`,
    ];

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
            <div className="flex min-w-0 items-start gap-3">
                <span className="mt-0.5 shrink-0 text-muted-foreground">
                    {icon}
                </span>
                <div className="min-w-0">
                    <p className="text-sm font-medium">{label}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground tabular-nums">
                        {parts.join(' · ')}
                    </p>
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                {action !== undefined && (
                    <Button
                        variant="outline"
                        className="h-8 px-3 text-xs"
                        onClick={() => router.post(action.url)}
                    >
                        {action.label}
                    </Button>
                )}
                <Button asChild variant="ghost" className="h-8 px-3 text-xs">
                    <Link href={href} prefetch>
                        {hrefLabel}
                    </Link>
                </Button>
            </div>
        </div>
    );
}

/**
 * What the engine did not do this week, and why.
 *
 * §7's mandatory line. Collapsed by default, because it sits at the bottom of a
 * screen that leads with a composer — and *loud in proportion to the news*,
 * which is the one thing the screen it came from got wrong. That page drew "The
 * week was never planned" and "Nothing was refused this week" in the same
 * rose-coloured card with the same icon, so an operator had to read prose to
 * tell a catastrophe from an all-clear.
 *
 * It always renders. A week that refused nothing gets a sentence saying so; it
 * never gets an empty panel, because an empty panel is the silence §7 forbids.
 */
function RefusalBand({ refusals }: { refusals: Refusals }) {
    const [open, setOpen] = useState(refusals.severity === 'alarm');
    const alarm = refusals.severity === 'alarm';

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className={`${workspacePanelClass} ${BAND} overflow-hidden ${
                alarm ? 'border-terracotta/45' : ''
            }`}
        >
            <CollapsibleTrigger
                disabled={refusals.nothing_to_report}
                className="group flex w-full items-center gap-3 px-5 py-3.5 text-left transition-colors hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-default disabled:hover:bg-transparent motion-reduce:transition-none"
            >
                {refusals.nothing_to_report ? (
                    <Check
                        className="size-4 shrink-0 text-sage"
                        aria-hidden="true"
                    />
                ) : (
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-90 motion-reduce:transition-none"
                    />
                )}
                <span className="min-w-0 flex-1 text-sm font-medium">
                    What the engine did not do this week
                </span>
                {refusals.nothing_to_report ? (
                    <span className="shrink-0 text-xs text-muted-foreground">
                        Nothing
                    </span>
                ) : (
                    <span
                        className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium tabular-nums ${
                            alarm
                                ? 'bg-terracotta-wash text-forest dark:bg-terracotta-shade dark:text-cream'
                                : 'bg-muted text-muted-foreground'
                        }`}
                    >
                        {refusals.entries.length}{' '}
                        {refusals.entries.length === 1 ? 'thing' : 'things'}
                    </span>
                )}
            </CollapsibleTrigger>

            <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                <div className="border-t px-5 py-3.5">
                    <p className="text-sm">{refusals.summary}</p>
                    {refusals.planned !== null && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {refusals.planned} planned this week against a
                            ceiling of {refusals.ceiling} and a floor of{' '}
                            {refusals.floor}.
                        </p>
                    )}
                </div>

                {refusals.entries.length > 0 && (
                    <ul className="flex flex-col divide-y border-t">
                        {refusals.entries.map((entry, index) => (
                            <li
                                key={`${entry.code}-${index}`}
                                className="px-5 py-3.5"
                            >
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {entry.label}
                                </p>
                                <p className="mt-1 text-sm">{entry.detail}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

type Label = { one: string; many: (n: number) => string };

const label = (one: string, many?: (n: number) => string): Label => ({
    one,
    many: many ?? ((n) => `${one} · ${n} running`),
});

/**
 * How the pipeline keys read to somebody who did not build them.
 *
 * Without this the panel prints the key: "content studio — apply content studio
 * action, 0/1", which names the module doing the work and not the work. It is
 * the difference between a machine reporting to its author and a product
 * telling somebody what is happening to their project.
 */
const PIPELINE_LABELS: Record<string, Label> = {
    research: label('Researching the market'),
    planning: label('Planning the month'),
    generation: label('Writing an article', (n) => `Writing ${n} articles`),
    content_studio: label("Working on the month's social content"),
    publishing: label('Publishing a post', (n) => `Publishing ${n} posts`),
    refresh: label('Refreshing a page', (n) => `Refreshing ${n} pages`),
    repurpose: label('Cutting an article down for social'),
    site_audit: label('Reading the site'),
    site_audit_fix_plan: label('Writing a fix plan for the site audit'),
    feedback: label('Reading what the published work did'),
    visibility: label('Asking the assistants about the brand'),
};

/**
 * The one pipeline whose key does not say what it is doing.
 *
 * `content_studio` carries six jobs, and calling every one of them by the
 * pipeline's name makes a month drafting read as eighteen simultaneous
 * proposals of the same thing — not a near-enough label, the wrong sentence.
 */
const STUDIO_LABELS: Record<string, Label> = {
    proposal: label("Proposing the month's social content"),
    refine: label('Rethinking the month'),
    generate: label('Working out what to write next'),
    generate_idea: label('Writing a post', (n) => `Writing ${n} posts`),
    revise_image: label(
        'Redrawing a picture',
        (n) => `Redrawing ${n} pictures`,
    ),
};

function labelFor(pipeline: string, action: string | null): Label {
    if (pipeline === 'content_studio' && action && STUDIO_LABELS[action]) {
        return STUDIO_LABELS[action];
    }

    return PIPELINE_LABELS[pipeline] ?? label(pipeline.replaceAll('_', ' '));
}

type RunGroup = {
    key: string;
    label: Label;
    subject: string | null;
    subjectId: string | null;
    count: number;
    doneSteps: number;
    totalSteps: number;
    currentStep: string | null;
};

/**
 * Runs doing the same job, shown once.
 *
 * Grouped by what they are and what they are about, so anything with its own
 * subject keeps its own row and only the indistinguishable ones collapse.
 * Progress is summed rather than averaged: "4 of 18" is a thing an operator can
 * watch move, where eighteen bars each reading "0 of 1" are not.
 */
function groupRuns(runs: Run[]): RunGroup[] {
    const groups = new Map<string, RunGroup>();

    for (const run of runs) {
        const key = `${run.pipeline}:${run.action ?? ''}:${run.subject ?? ''}`;
        const group = groups.get(key);

        if (group) {
            group.count += 1;
            group.doneSteps += run.done_steps;
            group.totalSteps += run.total_steps;
            group.currentStep = group.currentStep ?? run.current_step;
            continue;
        }

        groups.set(key, {
            key,
            label: labelFor(run.pipeline, run.action),
            subject: run.subject,
            subjectId: run.subject_id,
            count: 1,
            doneSteps: run.done_steps,
            totalSteps: run.total_steps,
            // Only worth naming when one run is being watched. Across a fan-out
            // it is whichever of eighteen happened to sort first, which reads
            // as information and is not any.
            currentStep: run.current_step,
        });
    }

    return [...groups.values()];
}

/**
 * The first hour.
 *
 * A project set up ten minutes ago has no articles and no impressions, and a
 * grid of zeroes reads as a broken product rather than a young one. What it does
 * have is work in flight, so this is what the top of the screen is until the
 * work stops — and nothing at all once it has.
 */
function WorkInProgress({ work }: { work?: Work }) {
    if (work === undefined) {
        return null;
    }

    if (work.active.length === 0 && work.failed.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="work-heading"
            className={`${workspacePanelClass} ${BAND} overflow-hidden`}
        >
            <header className="flex items-center gap-3 border-b px-5 py-3.5">
                <Spinner className="size-4 text-muted-foreground" />
                <h2
                    id="work-heading"
                    className="text-sm font-semibold tracking-tight"
                >
                    {work.launching ? 'Setting the project up' : 'Working'}
                </h2>
            </header>

            {work.active.length > 0 && (
                <ul className="divide-y">
                    {groupRuns(work.active).map((group) => (
                        <li key={group.key} className="px-5 py-3.5">
                            <RunProgress group={group} />
                        </li>
                    ))}
                </ul>
            )}

            {work.failed.length > 0 && (
                <ul className="divide-y border-t">
                    {work.failed.map((run) => (
                        <li key={run.id} className="px-5 py-3.5">
                            <p className="text-xs font-medium tracking-wide text-terracotta-deep uppercase dark:text-[#e58a76]">
                                Stopped · {run.pipeline.replace(/_/g, ' ')}
                            </p>
                            <p className="mt-1 text-sm">
                                {run.subject ?? run.step ?? 'A run stopped.'}
                            </p>
                            {run.message !== null && (
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {run.message}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * One job, in a sentence, with a way into what it is making.
 *
 * The link is the half that was missing. A panel that says work is happening
 * and gives no route to the thing being worked on leaves an operator watching a
 * bar and unable to answer "where is it" — which is the same silence §7 argues
 * about, one level down.
 */
function RunProgress({ group }: { group: RunGroup }) {
    const text =
        group.count > 1 ? group.label.many(group.count) : group.label.one;
    const percent =
        group.totalSteps > 0
            ? Math.round((group.doneSteps / group.totalSteps) * 100)
            : 0;

    // A bar is worth drawing only when it can move. These pipelines are not all
    // the same shape: writing an article walks eleven steps and genuinely fills
    // up, while drafting a post is one step, so a fan-out of eighteen of them
    // reads 0 of 18 until the moment each one vanishes. That is a progress bar
    // that is empty for the whole time it is on screen, which looks broken
    // rather than busy.
    const stepped = group.totalSteps > group.count;

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="min-w-0 truncate font-medium">
                    {text}
                    {group.subject !== null && (
                        <span className="text-muted-foreground">
                            {' · '}
                            {group.subjectId === null ? (
                                group.subject
                            ) : (
                                <Link
                                    href={contentShow(group.subjectId).url}
                                    className="underline underline-offset-4 hover:text-foreground"
                                >
                                    {group.subject}
                                </Link>
                            )}
                        </span>
                    )}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                    {group.totalSteps === 0
                        ? 'starting'
                        : stepped
                          ? `${group.doneSteps} of ${group.totalSteps}`
                          : 'still going'}
                </span>
            </div>
            {stepped && (
                <Progress
                    value={percent}
                    className="h-1.5"
                    indicatorClassName="bg-terracotta"
                    aria-label={`${text} progress`}
                    aria-valuetext={`${group.doneSteps} of ${group.totalSteps} steps complete`}
                />
            )}
            {group.count === 1 && group.currentStep !== null && (
                <p
                    className="text-xs text-muted-foreground"
                    role="status"
                    aria-live="polite"
                >
                    {group.currentStep.replaceAll('_', ' ')}
                </p>
            )}
        </div>
    );
}

/** The stack is down. Outranks everything, including the composer. */
function StoppedNotice({
    health,
}: {
    health?: { healthy: boolean; reason: string | null };
}) {
    if (health === undefined || health.healthy) {
        return null;
    }

    return (
        <section
            className={`${BAND} rounded-[var(--radius)] border border-terracotta/45 bg-terracotta-wash px-5 py-4 dark:bg-terracotta-shade`}
        >
            <p className="flex items-start gap-2 text-sm font-medium text-forest dark:text-cream">
                <TriangleAlert
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    The engine is not running.{' '}
                    <span className="font-normal">
                        {health.reason ??
                            'Nothing will be written or published until it is back.'}
                    </span>
                </span>
            </p>
        </section>
    );
}

function NoProject({ hasProjects }: { hasProjects: boolean }) {
    return (
        <>
            <Head title="Home" />
            <WorkspacePage width="reading">
                <div className={`${BAND} pt-10 text-center`}>
                    <h1 className="text-lg font-semibold tracking-tight">
                        {hasProjects ? 'Choose a project' : 'No project yet'}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {hasProjects
                            ? 'Pick one from the switcher and the engine has something to read.'
                            : 'Create a project and the engine has something to read.'}
                    </p>
                    {!hasProjects && (
                        <Button asChild className="mt-5">
                            <Link href={showOnboarding()}>
                                Create a project
                            </Link>
                        </Button>
                    )}
                </div>
            </WorkspacePage>
        </>
    );
}

/**
 * The wait in the words a person would use.
 *
 * The thresholds match `waited()` in `engage/index.tsx` and
 * `InteractionController::waited()` to the second, because a wait described two
 * ways on two screens is the one number on either that must not be wrong.
 */
function waited(seconds: number): string {
    if (seconds < 60) {
        return `${Math.max(0, seconds)}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h`;
    }

    return `${Math.floor(seconds / 86400)}d`;
}

function BandSkeleton() {
    return (
        <section className={`${workspacePanelClass} ${BAND} p-5`}>
            <div className="h-4 w-32 animate-pulse rounded bg-muted" />
            <div className="mt-4 h-10 w-full animate-pulse rounded bg-muted" />
        </section>
    );
}

function FiguresSkeleton() {
    return (
        <section
            className={`${workspacePanelClass} ${BAND} grid gap-4 p-5 sm:grid-cols-3`}
        >
            {[0, 1, 2].map((index) => (
                <div
                    key={index}
                    className="h-20 animate-pulse rounded-xl bg-muted/60"
                />
            ))}
        </section>
    );
}

/**
 * The way back into a conversation you were in the middle of.
 *
 * Five, because this is a landing screen and the full list has a page. Without
 * it the box on this screen only ever starts things — which is precisely the
 * complaint that a chat with no address answers once and is then lost.
 */
function RecentChats({ chats }: { chats: ChatSummary[] }) {
    if (chats.length === 0) {
        return null;
    }

    return (
        <div className="mt-5 flex flex-wrap items-center justify-center gap-x-2 gap-y-1.5">
            {chats.map((chat) => (
                <Link
                    key={chat.id}
                    href={showChat(chat.id).url}
                    className="max-w-full truncate rounded-full border border-border/70 px-3 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                >
                    {chat.title ?? 'Untitled'}
                </Link>
            ))}
            <Link
                href={chatIndex().url}
                className="rounded-full px-3 py-1 text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
            >
                All chats
            </Link>
        </div>
    );
}
