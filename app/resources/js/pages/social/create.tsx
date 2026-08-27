import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    ExternalLink,
    Loader,
    Pencil,
    Radar,
    Sparkles,
    WandSparkles,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    socialCardClass,
    socialInsetClass,
    socialSurfaceClass,
} from '@/components/social-surface';
import { SocialTabs } from '@/components/social-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { WorkspaceHeader, WorkspacePage } from '@/components/workspace-page';
import { patchJson, postJson } from '@/lib/json';
import { index as socialIndex } from '@/routes/social';
import {
    generate as generateIdea,
    store as storeIdea,
    update as updateIdea,
} from '@/routes/studio/ideas';

type Idea = {
    id: string;
    date: string;
    title: string;
    kind_label: string;
    thesis: string;
    content_format: string;
    content_format_label: string;
    format_chosen: boolean;
    channels: string[];
    // What each format would really do to *this* idea. A carousel is Instagram
    // only and a text post is everywhere but Instagram, so "buys no picture at
    // all" is true of some ideas and a lie about others. Sent by the server
    // because the rule is ContentFormat::isAvailableOn() and a copy of it here
    // is a copy that goes stale.
    formats: { value: string; honoured: string[]; falls_back: string[] }[];
    drafted: number;
    planned: number;
};

type Signal = {
    id: string;
    kind: string;
    kind_label: string;
    source: string;
    source_label: string;
    title: string;
    url: string | null;
    entities: string[];
    occurred_at: string;
    weight: number;
};

type Kind = { value: string; label: string; channels: string[] };
type Format = { value: string; label: string; note: string };

type Props = {
    month: string;
    label: string;
    ideas: Idea[];
    idea_total: number;
    signals: Signal[];
    kinds: Kind[];
};

/**
 * The formats an idea can be made as, and what each costs.
 *
 * Mirrors `App\Enums\ContentFormat`, including the omission: there is no Reel,
 * because nothing in this engine produces a frame of video and a greyed-out
 * chip is a promise the product cannot keep.
 */
const FORMATS: Format[] = [
    {
        value: 'carousel',
        label: 'Carousel',
        note: 'Drawn panels with real type. Instagram only; costs compute, not a generation.',
    },
    {
        value: 'image',
        label: 'Single image + text',
        note: 'One generated photograph and the words beside it.',
    },
    {
        value: 'text',
        label: 'Text post',
        note: 'Words alone. Buys no picture at all.',
    },
];

/**
 * Where a post comes from.
 *
 * **Six ideas, not the month's whole backlog.** A shelf of everything unwritten
 * is a decision nobody makes; the rest live on the board in date order, which is
 * where "what is left" belongs. And the whole card is the target — a small
 * Create button on a card whose text has to be read first puts the action
 * somewhere other than where the attention is.
 */
export default function SocialCreate({
    month,
    label,
    ideas,
    idea_total: total,
    signals,
    kinds,
}: Props) {
    const [open, setOpen] = useState<Idea | null>(null);
    // Ideas started from this screen, so a card can say so immediately rather
    // than waiting for the server to have something new to report.
    const [working, setWorking] = useState<Record<string, true>>({});

    return (
        <>
            <Head title={`Create — ${label}`} />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Social"
                    context={label}
                    title="Create"
                    description="Start from a planned idea or respond to a recent signal."
                />

                <SocialTabs current="create" month={month} />

                <Ideas
                    ideas={ideas}
                    total={total}
                    working={working}
                    month={month}
                    onOpen={setOpen}
                />

                <Signals signals={signals} kinds={kinds} />
            </WorkspacePage>

            <IdeaSheet
                idea={open}
                onClose={() => setOpen(null)}
                onStarted={(id) => {
                    setWorking((current) => ({ ...current, [id]: true }));
                    setOpen(null);
                }}
            />
        </>
    );
}

function Ideas({
    ideas,
    total,
    working,
    month,
    onOpen,
}: {
    ideas: Idea[];
    total: number;
    working: Record<string, true>;
    month: string;
    onOpen: (idea: Idea) => void;
}) {
    return (
        <section className={`${socialSurfaceClass} overflow-hidden p-3 sm:p-4`}>
            <header className="flex flex-wrap items-center justify-between gap-3 px-1 pb-4">
                <div className="flex items-center gap-2.5">
                    <Sparkles
                        className="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 className="font-semibold tracking-tight">
                            Post ideas
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {total > ideas.length
                                ? `${ideas.length} of ${total} unwritten — the rest are on the board`
                                : 'Planned for this month and not written yet'}
                        </p>
                    </div>
                </div>
                {total > ideas.length && (
                    <Button asChild size="sm" variant="ghost">
                        <a href={socialIndex({ query: { month } }).url}>
                            See all {total}
                        </a>
                    </Button>
                )}
            </header>

            {ideas.length === 0 ? (
                <p
                    className={`${socialInsetClass} px-5 py-10 text-center text-sm text-muted-foreground`}
                >
                    Nothing unwritten this month. Build a plan on the Plan tab,
                    or answer a signal below.
                </p>
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {ideas.map((idea) => (
                        <IdeaCard
                            key={idea.id}
                            idea={idea}
                            working={working[idea.id] === true}
                            onOpen={onOpen}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * One idea, and the whole of it is the button.
 *
 * A `<button>` wrapping the card rather than a small Create in its corner: the
 * decision is made by reading the title and the reason, so the target should be
 * the thing that was read.
 */
function IdeaCard({
    idea,
    working,
    onOpen,
}: {
    idea: Idea;
    working: boolean;
    onOpen: (idea: Idea) => void;
}) {
    const date = new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${idea.date}T00:00:00Z`));

    if (working) {
        return (
            <article
                className="flex min-w-0 flex-col items-center justify-center gap-2 rounded-2xl bg-violet-500/10 p-6 text-center"
                aria-live="polite"
            >
                <Loader
                    className="size-5 animate-spin text-violet-600 dark:text-violet-300"
                    aria-hidden="true"
                />
                <p className="text-sm font-medium">Writing it now</p>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {idea.title}
                </p>
                <p className="text-[11px] text-muted-foreground">
                    It appears on the board when the drafts land.
                </p>
            </article>
        );
    }

    return (
        <button
            type="button"
            onClick={() => onOpen(idea)}
            className={`${socialCardClass} flex min-w-0 flex-col p-4 text-left transition-all hover:-translate-y-0.5 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none`}
        >
            <span className="flex min-w-0 flex-wrap items-center gap-1.5">
                <Badge variant="secondary" className="rounded-full text-[10px]">
                    {idea.kind_label}
                </Badge>
                <span className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                    {date}
                </span>
            </span>

            <span className="mt-2 block text-sm leading-snug font-medium tracking-tight">
                {idea.title}
            </span>
            <span className="mt-1.5 line-clamp-3 flex-1 text-xs leading-relaxed text-muted-foreground">
                {idea.thesis}
            </span>

            <span className="mt-3 flex items-center justify-between gap-2 rounded-xl bg-muted/40 px-2.5 py-2">
                <span className="text-[11px] text-muted-foreground">
                    Content type
                </span>
                <Badge
                    variant="secondary"
                    className="rounded-full text-[10px] font-normal"
                >
                    {idea.content_format_label}
                </Badge>
            </span>
        </button>
    );
}

/**
 * What happens between picking an idea and making it.
 *
 * The reference product puts a panel here and it earns its place: the format is
 * a real decision with a real cost attached, and the reason the idea exists is
 * the thing somebody has to agree with before spending anything. Both are one
 * click deep rather than on the card, so the shelf stays scannable.
 */
function IdeaSheet({
    idea,
    onClose,
    onStarted,
}: {
    idea: Idea | null;
    onClose: () => void;
    onStarted: (id: string) => void;
}) {
    if (idea === null) {
        return null;
    }

    return (
        <Sheet open onOpenChange={(next) => !next && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                {/*
                    Keyed by the idea, so opening a different card mounts a
                    fresh panel with that idea's format and text already in
                    state. The alternative — one long-lived panel resynced from
                    an effect — is the pattern React now warns about, and it is
                    warning about a real bug: the first render after the switch
                    still holds the previous idea's answers.
                */}
                <IdeaSheetBody
                    key={idea.id}
                    idea={idea}
                    onStarted={onStarted}
                />
            </SheetContent>
        </Sheet>
    );
}

function IdeaSheetBody({
    idea,
    onStarted,
}: {
    idea: Idea;
    onStarted: (id: string) => void;
}) {
    const [format, setFormat] = useState(idea.content_format);
    const [thesis, setThesis] = useState(idea.thesis);
    const [editing, setEditing] = useState(false);
    const [why, setWhy] = useState(true);
    const [pending, setPending] = useState(false);

    const chosen = FORMATS.find((entry) => entry.value === format);
    const availability = (value: string) =>
        idea.formats.find((entry) => entry.value === value);
    const chosenFallsBack = availability(format)?.falls_back ?? [];

    async function create() {
        setPending(true);

        // Saved before it is made, so what gets written is what the panel says.
        if (format !== idea.content_format || thesis.trim() !== idea.thesis) {
            const saved = await patchJson<unknown>(updateIdea(idea.id).url, {
                content_format: format,
                thesis: thesis.trim(),
            });

            if (!saved.ok) {
                setPending(false);
                toast.error(saved.message);

                return;
            }
        }

        const result = await postJson<unknown>(generateIdea(idea.id).url, {});

        if (!result.ok) {
            setPending(false);
            toast.error(result.message);

            return;
        }

        onStarted(idea.id);
    }

    return (
        <>
            <SheetHeader className="space-y-3">
                <Badge variant="outline" className="w-fit rounded-full">
                    {idea.kind_label}
                </Badge>
                <SheetTitle className="text-xl leading-tight">
                    {idea.title}
                </SheetTitle>
                <SheetDescription className="sr-only">
                    Review this idea and choose what it should be made as.
                </SheetDescription>
            </SheetHeader>

            <div className="grid gap-5 px-4 pb-6">
                <div className={`${socialInsetClass} p-3.5`}>
                    <div className="flex items-start justify-between gap-2">
                        {editing ? (
                            <Textarea
                                value={thesis}
                                rows={4}
                                className="text-sm"
                                aria-label="What this post is about"
                                onChange={(event) =>
                                    setThesis(event.target.value)
                                }
                            />
                        ) : (
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                {thesis}
                            </p>
                        )}
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7 shrink-0"
                            aria-label={
                                editing
                                    ? 'Stop editing'
                                    : 'Edit what this post is about'
                            }
                            onClick={() => setEditing((now) => !now)}
                        >
                            <Pencil className="size-3.5" aria-hidden="true" />
                        </Button>
                    </div>
                </div>

                <div className={`${socialInsetClass} overflow-hidden`}>
                    <button
                        type="button"
                        className="flex w-full items-center justify-between gap-2 px-3.5 py-3 text-left text-sm font-medium hover:bg-muted/50"
                        aria-expanded={why}
                        onClick={() => setWhy((now) => !now)}
                    >
                        Why this idea?
                        <ChevronDown
                            className={`size-4 text-muted-foreground transition-transform ${why ? 'rotate-180' : ''}`}
                            aria-hidden="true"
                        />
                    </button>
                    {why && (
                        <p className="px-3.5 pb-3 text-sm leading-relaxed text-muted-foreground">
                            Planned as a <b>{idea.kind_label.toLowerCase()}</b>{' '}
                            for {idea.channels.join(' and ')}, because the kind
                            decides where an idea belongs — no idea goes to
                            every channel.
                        </p>
                    )}
                </div>

                <div className="grid gap-2">
                    <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                        Content type
                    </p>
                    <div
                        className="grid gap-2"
                        role="radiogroup"
                        aria-label="Content type"
                    >
                        {FORMATS.map((option) => {
                            // No channel of this idea produces it, so it is not
                            // a preference that quietly degrades — it is a
                            // choice that never happens. Offered greyed rather
                            // than hidden: an absent option reads as a bug, and
                            // the reason is worth saying once.
                            const unavailable =
                                (availability(option.value)?.honoured.length ??
                                    0) === 0;

                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={option.value === format}
                                    disabled={unavailable}
                                    onClick={() => setFormat(option.value)}
                                    className={`rounded-xl p-3 text-left transition-all focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none ${
                                        unavailable
                                            ? 'cursor-not-allowed bg-muted/25 opacity-50'
                                            : option.value === format
                                              ? 'bg-violet-500/12 shadow-[inset_0_0_0_2px_rgba(139,92,246,0.45)]'
                                              : 'bg-muted/45 hover:bg-muted/70'
                                    }`}
                                >
                                    <span className="block text-sm font-medium">
                                        {option.label}
                                    </span>
                                    <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                                        {unavailable
                                            ? `Not available for ${idea.channels.join(' and ')}.`
                                            : option.note}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {chosenFallsBack.length > 0 && (
                        <p className="rounded-xl bg-amber-500/10 p-3 text-xs leading-relaxed text-muted-foreground">
                            {chosenFallsBack.join(' and ')} cannot carry that,
                            so {chosenFallsBack.length > 1 ? 'they' : 'it'}{' '}
                            would get a single photograph instead. The other
                            channels are made as chosen.
                        </p>
                    )}
                </div>

                <div className="grid gap-2">
                    <Button
                        size="lg"
                        disabled={pending}
                        onClick={() => void create()}
                    >
                        <WandSparkles className="size-4" aria-hidden="true" />
                        {pending ? 'Starting…' : 'Create content'}
                    </Button>
                    <p className="text-center text-xs text-muted-foreground">
                        {chosen?.value === 'text'
                            ? `Writes ${idea.channels.length} drafts and buys no pictures.`
                            : `Writes ${idea.channels.length} native drafts and their visuals. Nothing publishes.`}
                    </p>
                </div>
            </div>
        </>
    );
}

function Signals({ signals, kinds }: { signals: Signal[]; kinds: Kind[] }) {
    return (
        <section className={`${socialSurfaceClass} overflow-hidden p-3 sm:p-4`}>
            <header className="flex items-center justify-between gap-3 px-1 pb-4">
                <div className="flex items-center gap-2.5">
                    <Radar
                        className="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 className="font-semibold tracking-tight">
                            Signals
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Questions, news, and mentions Avyo found
                        </p>
                    </div>
                </div>
                <Badge
                    variant="secondary"
                    className="rounded-full tabular-nums"
                >
                    {signals.length}
                </Badge>
            </header>

            {signals.length === 0 ? (
                <div className={`${socialInsetClass} px-5 py-10 text-center`}>
                    <p className="text-sm text-muted-foreground">
                        No signals yet.
                    </p>
                    <p className="mx-auto mt-2 max-w-lg text-xs leading-relaxed text-muted-foreground">
                        Connect Threads, add RSS feeds, or track keywords to see
                        timely topics here.
                    </p>
                </div>
            ) : (
                <div className="grid gap-3 md:grid-cols-2">
                    {signals.map((signal) => (
                        <SignalCard
                            key={signal.id}
                            signal={signal}
                            kinds={kinds}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

function SignalCard({ signal, kinds }: { signal: Signal; kinds: Kind[] }) {
    const [pending, setPending] = useState(false);
    const [kind, setKind] = useState(kinds[0]?.value ?? 'take');

    async function write() {
        setPending(true);

        const result = await postJson<unknown>(storeIdea().url, {
            title: signal.title.slice(0, 255),
            thesis: `Answering a ${signal.kind_label.toLowerCase()} picked up from ${signal.source_label}.`,
            kind,
            date: new Date().toISOString().slice(0, 10),
            signal_id: signal.id,
        });

        setPending(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        toast.success('Writing it now — it will appear on the board.');
        router.get(socialIndex());
    }

    return (
        <article className={`${socialCardClass} flex min-w-0 flex-col p-4`}>
            <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                <Badge variant="secondary" className="rounded-full text-[10px]">
                    {signal.kind_label}
                </Badge>
                <span className="truncate text-[11px] text-muted-foreground">
                    {signal.source_label} · {signal.occurred_at}
                </span>
            </div>

            <p className="mt-2 flex-1 text-sm leading-snug font-medium">
                {signal.title}
            </p>

            {signal.url !== null && (
                <a
                    href={signal.url}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="mt-1.5 flex items-center gap-1 text-xs text-muted-foreground hover:underline"
                >
                    <ExternalLink className="size-3" aria-hidden="true" />
                    Source
                </a>
            )}

            <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                <label className="sr-only" htmlFor={`kind-${signal.id}`}>
                    What kind of post should answer it?
                </label>
                <select
                    id={`kind-${signal.id}`}
                    value={kind}
                    onChange={(event) => setKind(event.target.value)}
                    className="h-8 rounded-md border bg-background px-2 text-xs"
                >
                    {kinds.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label} · {option.channels.join('+')}
                        </option>
                    ))}
                </select>
                <Button
                    size="sm"
                    disabled={pending}
                    onClick={() => void write()}
                >
                    <WandSparkles className="size-3.5" aria-hidden="true" />
                    {pending ? 'Writing…' : 'Write about this'}
                </Button>
            </div>
        </article>
    );
}
