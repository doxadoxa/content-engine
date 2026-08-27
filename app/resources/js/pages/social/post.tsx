import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CircleAlert,
    Lock,
    MessageSquareText,
    Plus,
    Send,
    Sparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
    socialInsetClass,
    socialSurfaceClass,
} from '@/components/social-surface';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { WorkspaceHeader, WorkspacePage } from '@/components/workspace-page';
import { postJson } from '@/lib/json';
import { approve } from '@/routes/content';
import { index as socialIndex } from '@/routes/social';
import { show as showPost } from '@/routes/social/posts';
import { edit as editPost, update } from '@/routes/social/posts';
import { choose, upload } from '@/routes/studio/image';

type Asset = {
    id: string;
    url: string;
    alt: string;
    width: number | null;
    height: number | null;
    chosen: boolean;
    retired: boolean;
    source: string;
    created_at: string | null;
};

/**
 * One drawn carousel slide.
 *
 * Not an {@see Asset}: a panel is never chosen, never rejected and never put
 * back, so the three fields that exist for that decision would be dead weight
 * here — and a type carrying `chosen` invites a control that promotes it.
 */
type Panel = {
    id: string;
    url: string;
    alt: string;
    width: number | null;
    height: number | null;
    /**
     * When it was drawn, which is the only thing tying a slide to the note that
     * caused it — an asset carries no reference to the sentence that produced
     * it, exactly as for a photograph.
     */
    created_at: string | null;
};

type Edit = {
    said: string;
    reply: string;
    changed: string[];
    /**
     * This note put a drawing on the queue, not just a revised brief.
     * Null on notes written before the field existed: unknown, not false.
     */
    redraw_queued?: boolean | null;
    at: string;
    /** Sent, and the answer has not come back yet. */
    thinking?: boolean;
    failed?: boolean;
};

type Post = {
    id: string;
    title: string;
    channel: string;
    channel_label: string;
    state: string;
    state_label: string;
    editable: boolean;
    format: string;
    body: string;
    segments: string[];
    caption: string | null;
    slides: Array<{ heading: string; body: string }>;
    character_limit: number | null;
    scheduled_for: string | null;
    slot_at: string | null;
    idea: {
        id: string;
        title: string;
        thesis: string;
        kind_label: string;
    } | null;
    guard_findings: Array<{ code: string; detail: string }>;
    fact_check: { passed: boolean; findings: string[] } | null;
    publishable: boolean;
    blocking: string[];
    assets: Asset[];
    /** A carousel's drawn slides, in publish order. Empty for every other format. */
    panels: Panel[];
    visual_notes: string[];
    edits: Edit[];
    /** What became of the last picture asked for: running, failed, done. */
    redraw: 'running' | 'failed' | 'done' | null;
};

type Props = { post: Post; editing: boolean };

/**
 * One post, as it will read, with everything else out of the way.
 *
 * **No steps.** The four-tab composer was a copy of a reference product's shape
 * that this screen had not earned: it split one artefact across four screens
 * and — worse — it made a reviewer choose the *control* before they could
 * describe the problem. A caption tab and a picture tab mean knowing in advance
 * which half of the post is at fault, and "lead with the checklist and shoot it
 * from above" is one thought.
 *
 * So the post is the page, the two things that are genuinely settings live in a
 * rail beside it, and every fix goes through one Edit conversation that works
 * out for itself which half it is about.
 */
export default function SocialPost({ post, editing }: Props) {
    const chosen = post.assets.find((asset) => asset.chosen);

    // A queued redraw takes tens of seconds and nothing was watching for it,
    // so the new picture arrived only if somebody happened to reload.
    //
    // An interval rather than a timeout, and that is the fix rather than a
    // preference. A `setTimeout` keyed on `[post.redraw, post.assets.length]`
    // reloaded once and then stopped: three seconds into a drawing that takes
    // thirty, the reload comes back with the same `running` and the same asset
    // count, so neither dependency changed, so the effect never re-ran and no
    // second timer was ever scheduled. The picture then arrived only on a
    // manual reload — exactly the failure this was added to prevent.
    useEffect(() => {
        if (post.redraw !== 'running') {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['post'] }),
            3_000,
        );

        return () => window.clearInterval(timer);
    }, [post.redraw]);

    return (
        <>
            <Head
                title={
                    post.idea
                        ? `${post.idea.title} — ${post.channel_label}`
                        : post.title
                }
            />

            <WorkspacePage
                className={
                    // Only while editing: the conversation has to fit the
                    // screen with its input on it, so the page stops being a
                    // scrolling document and becomes a fixed frame whose middle
                    // column scrolls instead. Reading a post is an ordinary
                    // page and keeps scrolling normally.
                    editing
                        ? 'xl:h-[calc(100svh-4rem)] xl:min-h-0 xl:overflow-hidden'
                        : undefined
                }
            >
                <WorkspaceHeader
                    eyebrow={post.channel_label}
                    context={post.state_label}
                    title={post.idea?.title ?? post.title}
                    description={post.idea?.thesis}
                    actions={
                        <Button
                            asChild
                            variant="ghost"
                            className="rounded-full"
                        >
                            <Link
                                href={
                                    editing ? showPost(post.id) : socialIndex()
                                }
                            >
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {editing ? 'Done editing' : 'Board'}
                            </Link>
                        </Button>
                    }
                />

                {editing ? (
                    /*
                        Editing inverts the page, and the references this
                        borrows from all agree on which way round it goes: the
                        conversation is the wide column and the thing being
                        worked on is the panel beside it. A 24rem rail was never
                        going to be a usable place to think in.
                    */
                    <div className="mx-auto grid w-full max-w-[78rem] min-w-0 gap-6 xl:min-h-0 xl:flex-1 xl:grid-cols-[minmax(0,1fr)_23rem]">
                        <section
                            aria-label="Edit conversation"
                            className="order-1 min-w-0 xl:min-h-0"
                        >
                            <EditChat post={post} />
                        </section>
                        <aside className="order-2 grid min-w-0 content-start gap-4 xl:min-h-0 xl:overflow-y-auto xl:pr-1">
                            <Artifact post={post} chosen={chosen} />
                            <Objections post={post} />
                            <Candidates post={post} />
                        </aside>
                    </div>
                ) : (
                    <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                        <section aria-label="Post preview" className="min-w-0">
                            <Preview post={post} chosen={chosen} />
                        </section>

                        <aside className="grid min-w-0 content-start gap-4">
                            <Objections post={post} />
                            <ReviewActions post={post} />
                            <Schedule post={post} />
                            <Candidates post={post} />
                        </aside>
                    </div>
                )}
            </WorkspacePage>
        </>
    );
}

/**
 * The post while it is being changed, small and always in view.
 *
 * Claude's artifacts panel and Codex's environment rail do the same job: the
 * subject of the conversation stays visible without competing with it for the
 * middle of the screen. Approving from here is deliberate — a person who has
 * just fixed the last thing wrong with a post should not have to leave the
 * conversation to say so.
 */
function Artifact({ post, chosen }: { post: Post; chosen?: Asset }) {
    return (
        <section
            className={`${socialSurfaceClass} overflow-hidden`}
            aria-label="The post"
        >
            {chosen && (
                <div className="relative">
                    <img
                        src={chosen.url}
                        alt={chosen.alt}
                        className="aspect-[4/3] w-full object-cover"
                    />
                    {post.redraw === 'running' && (
                        <span className="absolute inset-0 flex items-center justify-center gap-2 bg-background/70 text-sm font-medium backdrop-blur-sm">
                            <Sparkles
                                className="size-4 animate-pulse"
                                aria-hidden="true"
                            />
                            Drawing a new one…
                        </span>
                    )}
                    {/* So the rail does not imply a one-picture post while the
                        preview beside it is showing seven. */}
                    {post.panels.length > 0 && (
                        <span className="absolute right-2 bottom-2 rounded-full bg-background/85 px-2 py-0.5 text-xs font-medium backdrop-blur-sm">
                            +{post.panels.length} slides
                        </span>
                    )}
                </div>
            )}
            <div className="grid max-h-44 gap-2 overflow-y-auto p-4">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                        Current post
                    </p>
                    <Badge variant="secondary" className="rounded-full">
                        {post.channel_label}
                    </Badge>
                </div>
                {post.segments.map((segment, position) => (
                    <p
                        key={position}
                        className="text-sm leading-relaxed whitespace-pre-wrap"
                    >
                        {segment}
                    </p>
                ))}
            </div>
            <div className="flex items-center justify-between gap-2 bg-muted/35 px-4 py-3">
                <span className="text-xs text-muted-foreground">
                    {post.state_label}
                </span>
                {post.editable && <Approve post={post} />}
            </div>
        </section>
    );
}

/** The post at the size it will be read, and nothing else in the frame. */
function Preview({ post, chosen }: { post: Post; chosen?: Asset }) {
    const hasPictures = chosen !== undefined || post.panels.length > 0;

    return (
        <article
            className={`${socialSurfaceClass} mx-auto grid w-full overflow-hidden ${hasPictures ? 'md:grid-cols-[minmax(0,1.08fr)_minmax(17rem,0.92fr)]' : 'max-w-2xl'}`}
        >
            {post.panels.length > 0 ? (
                <Panels post={post} />
            ) : (
                chosen && (
                    <div className="flex min-h-64 items-center bg-muted/35 md:min-h-[28rem]">
                        <img
                            src={chosen.url}
                            alt={chosen.alt}
                            width={chosen.width ?? undefined}
                            height={chosen.height ?? undefined}
                            className="max-h-[32rem] w-full object-contain"
                        />
                    </div>
                )
            )}

            <div className="flex min-w-0 flex-col p-5 sm:p-6">
                <div className="mb-5 flex items-center justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                            Post copy
                        </p>
                        <p className="mt-1 text-sm font-medium">
                            {post.channel_label}
                        </p>
                    </div>
                    <Badge variant="secondary" className="rounded-full">
                        {post.state_label}
                    </Badge>
                </div>

                <div className="grid gap-3">
                    {post.segments.map((segment, position) => (
                        <p
                            key={position}
                            className="text-[0.95rem] leading-relaxed whitespace-pre-wrap"
                        >
                            {segment}
                        </p>
                    ))}
                </div>

                {post.slides.length > 0 && (
                    <ol className="mt-5 grid gap-2">
                        {post.slides.map((slide, position) => (
                            <li
                                key={`${slide.heading}-${position}`}
                                className={`${socialInsetClass} p-3`}
                            >
                                <p className="text-sm font-medium">
                                    {position + 1}. {slide.heading}
                                </p>
                                {slide.body !== '' && (
                                    <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                                        {slide.body}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </article>
    );
}

/**
 * A carousel, previewed as a carousel.
 *
 * This screen used to show a carousel as one photograph. The slides were drawn
 * — `CarouselPanels` renders each one through the panel service, and
 * `WebhookPayload` publishes them alongside the hero — but the composer's only
 * picture concept was "the chosen one, plus rejected candidates", and a panel is
 * neither. So they were filtered out at the controller and never reached the
 * page, which left an operator approving six pictures they had never seen on the
 * one screen whose entire job is to look at the post before it goes out. The
 * slide *text* was listed beside the copy, which made it look intentional.
 *
 * **All of them at once, not a scroller.** A horizontal strip in this column
 * showed two and a half slides and clipped the third mid-card, which is the
 * shape of an accident rather than of an affordance. The question being asked
 * here is whether the *sequence* holds together, and that is answered by seeing
 * the set — so it is a grid, and any one of them opens full size for the
 * question a thumbnail cannot answer.
 *
 * **Nothing is renumbered.** Each panel already carries the counter the
 * renderer drew into it — "3 / 6" — and an overlaid badge counting the cover as
 * one put a "4" on top of a slide that says 3. Two numbers disagreeing on the
 * same picture is worse than no number, and the drawn one is the one that
 * publishes. Only the cover is labelled, because it is the one frame that is
 * not part of that count.
 */
function Panels({ post }: { post: Post }) {
    const [looking, setLooking] = useState<Panel | null>(null);

    // The panels, and only the panels. The chosen photograph used to be
    // prepended here as a cover frame, which was right while it published ahead
    // of them — and became a duplicate the moment the cover panel started being
    // drawn *on* that photograph. The screen showed the picture twice: once
    // bare, then again with the hook on it.
    //
    // It has not stopped mattering, it has stopped being a frame. The operator
    // still chooses it among the candidates below, and `CarouselPanels` still
    // reads it to draw slide one.
    const frames = post.panels;

    return (
        // `self-start` so the column sizes to the slides. Grid items stretch by
        // default, and the copy beside this one is long enough that stretching
        // opened a screenful of empty panel above and below the pictures.
        <div className="self-start bg-muted/35 p-4">
            <ul
                className="grid grid-cols-2 gap-2"
                aria-label={`${post.panels.length} slides, in the order they publish`}
            >
                {frames.map((frame) => (
                    <li key={frame.id} className="relative">
                        <button
                            type="button"
                            onClick={() => setLooking(frame)}
                            aria-label={`View slide: ${frame.alt}`}
                            className="block w-full overflow-hidden rounded-xl focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                        >
                            <img
                                src={frame.url}
                                alt={frame.alt}
                                width={frame.width ?? undefined}
                                height={frame.height ?? undefined}
                                className="aspect-[4/5] w-full bg-background object-contain transition-opacity hover:opacity-90"
                            />
                        </button>
                    </li>
                ))}
            </ul>
            <p className="mt-3 text-xs text-muted-foreground">
                {post.panels.length} slides · already drawn · click one to see
                it full size
            </p>

            <PanelLightbox panel={looking} onClose={() => setLooking(null)} />
        </div>
    );
}

/**
 * One slide, big enough to read.
 *
 * Its own dialog rather than {@see Lightbox}: that one carries the button that
 * promotes a picture to the post's cover, and offering that on slide four is an
 * invitation to break the sequence it belongs to.
 */
function PanelLightbox({
    panel,
    onClose,
}: {
    panel: Panel | null;
    onClose: () => void;
}) {
    if (panel === null) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="w-[calc(100vw-2rem)] max-w-2xl gap-0 overflow-hidden p-0">
                <DialogHeader className="sr-only">
                    <DialogTitle>{panel.alt || 'Slide'}</DialogTitle>
                    <DialogDescription>
                        A slide drawn for this carousel, full size.
                    </DialogDescription>
                </DialogHeader>
                <img
                    src={panel.url}
                    alt={panel.alt}
                    width={panel.width ?? undefined}
                    height={panel.height ?? undefined}
                    className="max-h-[calc(100dvh-8rem)] w-full bg-muted object-contain"
                />
            </DialogContent>
        </Dialog>
    );
}

/** Keep the review decision visible instead of burying it under the preview. */
function ReviewActions({ post }: { post: Post }) {
    return (
        <section
            className={`${socialSurfaceClass} grid gap-3 p-4`}
            aria-label="Review this post"
        >
            <div className="flex items-start gap-3">
                <span
                    className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${post.publishable ? 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-300' : 'bg-amber-500/12 text-amber-700 dark:text-amber-300'}`}
                >
                    {post.publishable ? (
                        <Check className="size-4" aria-hidden="true" />
                    ) : (
                        <CircleAlert className="size-4" aria-hidden="true" />
                    )}
                </span>
                <div>
                    <h2 className="text-sm font-semibold">
                        {post.publishable
                            ? 'Ready for your review'
                            : 'Needs attention first'}
                    </h2>
                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                        {post.publishable
                            ? 'Approve it, or describe what should change.'
                            : 'Resolve the issues above before approval.'}
                    </p>
                </div>
            </div>

            {post.editable ? (
                <div className="grid grid-cols-2 gap-2">
                    <Button asChild variant="outline">
                        <Link
                            href={showPost(post.id, {
                                query: { edit: 1 },
                            })}
                        >
                            <MessageSquareText
                                className="size-4"
                                aria-hidden="true"
                            />
                            Suggest changes
                        </Link>
                    </Button>
                    <Approve post={post} />
                </div>
            ) : (
                <p className="flex items-center gap-2 rounded-xl bg-muted/45 p-3 text-xs text-muted-foreground">
                    <Lock className="size-3.5" aria-hidden="true" />
                    {post.state_label} — send it back from the queue to make
                    changes.
                </p>
            )}
        </section>
    );
}

function Approve({ post }: { post: Post }) {
    return (
        <Form
            action={approve(post.id)}
            method="post"
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    disabled={processing || !post.publishable}
                    title={
                        post.publishable
                            ? undefined
                            : 'Fix the blocking problems first'
                    }
                >
                    <Check className="size-4" aria-hidden="true" />
                    {processing ? 'Approving…' : 'Approve'}
                </Button>
            )}
        </Form>
    );
}

/**
 * The only settings that are settings.
 *
 * Channel and format follow from the idea's kind and are facts about the post
 * rather than choices on it, so they read as facts. The date and the slot are
 * the two things a person actually sets here.
 */
function Schedule({ post }: { post: Post }) {
    const form = useForm({
        segments: post.segments,
        scheduled_for: post.scheduled_for ?? '',
        slot_at: post.slot_at ?? '',
    });

    const longest = Math.max(0, ...post.segments.map((one) => one.length));

    return (
        <section className={`${socialSurfaceClass} grid gap-4 p-4`}>
            <div>
                <h2 className="text-sm font-semibold">Schedule</h2>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    When this approved post should go out.
                </p>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="scheduled-for">Publish date</Label>
                <Input
                    id="scheduled-for"
                    type="date"
                    value={form.data.scheduled_for}
                    readOnly={!post.editable}
                    onChange={(event) =>
                        form.setData('scheduled_for', event.target.value)
                    }
                />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="slot-at">Time slot (optional)</Label>
                <Input
                    id="slot-at"
                    type="datetime-local"
                    value={form.data.slot_at}
                    readOnly={!post.editable}
                    onChange={(event) =>
                        form.setData('slot_at', event.target.value)
                    }
                />
            </div>

            {post.editable && (
                <Button
                    size="sm"
                    variant="outline"
                    disabled={form.processing || !form.isDirty}
                    onClick={() => {
                        form.transform((data) => ({
                            ...data,
                            slot_at: data.slot_at === '' ? null : data.slot_at,
                        }));
                        form.patch(update(post.id).url, {
                            preserveScroll: true,
                        });
                    }}
                >
                    {form.processing ? 'Saving…' : 'Save schedule'}
                </Button>
            )}

            <dl className="grid gap-2 rounded-xl bg-muted/35 p-3 text-xs">
                <Fact term="Format" value={post.format.replace('_', ' ')} />
                <Fact term="State" value={post.state_label} />
                {post.character_limit !== null && (
                    <Fact
                        term="Longest part"
                        value={`${longest} / ${post.character_limit}`}
                    />
                )}
            </dl>
        </section>
    );
}

function Fact({ term, value }: { term: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-2">
            <dt className="text-muted-foreground">{term}</dt>
            <dd className="text-right capitalize">{value}</dd>
        </div>
    );
}

/** The other pictures this draft has, so a rejected one can be put back. */
function Candidates({ post }: { post: Post }) {
    const others = post.assets.filter((asset) => !asset.chosen);

    if (others.length === 0) {
        return null;
    }

    return (
        <section className={`${socialSurfaceClass} grid gap-3 p-4`}>
            <h2 className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                Other takes
            </h2>
            <div className="flex flex-wrap gap-2">
                {others.map((asset) => (
                    <Form
                        key={asset.id}
                        action={choose([post.id, asset.id])}
                        method="post"
                        options={{ preserveScroll: true }}
                    >
                        <button
                            type="submit"
                            title={
                                asset.retired
                                    ? 'Previously used — put it back'
                                    : 'Use this one'
                            }
                            className="relative overflow-hidden rounded-md border transition hover:ring-2 hover:ring-primary"
                        >
                            <img
                                src={asset.url}
                                alt={asset.alt}
                                className="h-16 w-auto object-cover"
                            />
                            {asset.retired && (
                                <span className="absolute inset-x-0 bottom-0 bg-background/80 py-0.5 text-center text-[9px]">
                                    was used
                                </span>
                            )}
                        </button>
                    </Form>
                ))}
            </div>
        </section>
    );
}

/** Everything standing between this post and somebody's approval. */
function Objections({ post }: { post: Post }) {
    const factCheckFailed = post.fact_check !== null && !post.fact_check.passed;

    if (
        post.blocking.length === 0 &&
        post.guard_findings.length === 0 &&
        !factCheckFailed
    ) {
        return null;
    }

    return (
        <section className="grid gap-2">
            {factCheckFailed && (
                <Objection
                    tone="red"
                    title="Unsupported by the business facts"
                    items={post.fact_check?.findings ?? []}
                />
            )}
            {post.guard_findings.length > 0 && (
                <Objection
                    tone="amber"
                    title="Would not publish as written"
                    items={post.guard_findings.map((finding) => finding.detail)}
                />
            )}
            {post.blocking.length > 0 && (
                <Objection tone="red" title="Blocking" items={post.blocking} />
            )}
        </section>
    );
}

function Objection({
    tone,
    title,
    items,
}: {
    tone: 'red' | 'amber';
    title: string;
    items: string[];
}) {
    return (
        <div
            className={`rounded-2xl p-3.5 ${
                tone === 'red' ? 'bg-red-500/10' : 'bg-amber-500/10'
            }`}
        >
            <p className="flex items-center gap-2 text-sm font-medium">
                <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
                {title}
            </p>
            <ul className="mt-1.5 grid gap-1 text-xs leading-relaxed text-muted-foreground">
                {items.map((item) => (
                    <li key={item}>{item}</li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Describe the change; the engine works out which half of the post it is about.
 *
 * One input rather than a caption box and a picture box, because a reviewer
 * should not have to classify their own complaint before making it. The server
 * side of that decision is `App\ContentStudio\PostDirector`.
 *
 * A photograph can be attached and becomes a candidate immediately — it costs
 * nothing and beats anything a provider can draw. Documents are not accepted:
 * nothing in this engine reads one, and a control that silently discarded a PDF
 * would be worse than not offering it.
 */
function EditChat({ post }: { post: Post }) {
    const [said, setSaid] = useState('');
    const [pending, setPending] = useState(false);
    const [log, setLog] = useState<Edit[]>(post.edits);
    const [looking, setLooking] = useState<Asset | null>(null);
    const suggestions = [
        'Lead with the checklist.',
        'Too clean — show the actual residue.',
        'Shorter, and drop the question.',
    ];

    // Which note is waiting on the picture that is being drawn right now.
    // `post.redraw` says a redraw is running; it cannot say which sentence
    // asked for it, and the newest one that asked is the only honest answer.
    const lastDrawing = log.reduce(
        (found, entry, index) =>
            entry.redraw_queued !== false && entry.changed.includes('picture')
                ? index
                : found,
        -1,
    );

    async function send() {
        if (said.trim() === '') {
            return;
        }

        const instruction = said.trim();

        // On screen before the request leaves. The message used to appear only
        // once the server answered, so the input emptied and nothing else
        // happened for several seconds — long enough to wonder whether the
        // send had worked at all.
        setLog((current) => [
            ...current,
            {
                said: instruction,
                reply: '',
                changed: [],
                at: new Date().toISOString(),
                thinking: true,
            },
        ]);
        setSaid('');
        setPending(true);

        const result = await postJson<{
            reply: string;
            changed: string[];
            redrawing: boolean;
        }>(editPost(post.id).url, { instruction });

        setPending(false);

        setLog((current) =>
            current.map((entry, index) =>
                index === current.length - 1
                    ? {
                          ...entry,
                          thinking: false,
                          failed: !result.ok,
                          reply: result.ok ? result.data.reply : result.message,
                          changed: result.ok ? result.data.changed : [],
                      }
                    : entry,
            ),
        );

        if (!result.ok) {
            return;
        }

        // The post itself is server state; re-reading it is what puts the new
        // words on the preview beside this panel — and, when a picture is on
        // its way, starts the poll that will show it landing.
        router.reload({ only: ['post'] });
    }

    async function attach(file: File) {
        setPending(true);

        const body = new FormData();
        body.append('photo', file);

        const response = await fetch(upload(post.id).url, {
            method: 'POST',
            body,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        setPending(false);

        if (!response.ok) {
            const problem = (await response.json().catch(() => null)) as {
                message?: string;
            } | null;

            toast.error(
                problem?.message ?? 'That photograph could not be used.',
            );

            return;
        }

        toast.success('Added as a candidate — pick it under Other takes.');
        router.reload({ only: ['post'] });
    }

    return (
        <section
            className={`${socialSurfaceClass} flex min-h-[26rem] w-full flex-col overflow-hidden xl:h-full xl:min-h-0`}
            aria-label="Edit this post"
        >
            <header className="flex items-start justify-between gap-3 px-5 pt-5 pb-3">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-2 font-semibold tracking-tight">
                        <Sparkles className="size-4" aria-hidden="true" />
                        Edit this post
                    </h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Say what is wrong. Words, picture, or both — it works
                        out which.
                    </p>
                </div>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto p-4">
                {log.length === 0 ? (
                    <div className="grid gap-3">
                        <div>
                            <p className="text-sm font-medium">
                                What should change?
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Choose a direction or write your own below.
                            </p>
                        </div>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {suggestions.map((suggestion) => (
                                <button
                                    key={suggestion}
                                    type="button"
                                    onClick={() => setSaid(suggestion)}
                                    className={`${socialInsetClass} min-h-20 p-3 text-left text-xs leading-relaxed text-muted-foreground transition-colors hover:bg-muted/70 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none`}
                                >
                                    “{suggestion}”
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    <ol className="grid gap-4">
                        {log.map((entry, position) => (
                            <li
                                key={`${entry.at}-${position}`}
                                className="grid gap-2"
                            >
                                <p className="ml-auto max-w-[85%] rounded-2xl rounded-tr-md bg-muted px-3.5 py-2.5 text-sm">
                                    {entry.said}
                                </p>

                                {/* Above the row rather than inside the text
                                    column, indented to meet it. Inside, the
                                    avatar aligned itself to this small line
                                    instead of to the reply and sat a few pixels
                                    high of everything else. */}
                                {!entry.thinking &&
                                    entry.changed.length > 0 && (
                                        <p className="ml-8 text-xs text-muted-foreground">
                                            {entry.changed
                                                .map((what) =>
                                                    label(what, entry, post),
                                                )
                                                .join(' · ')}
                                        </p>
                                    )}

                                <div className="flex items-start gap-2">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-violet-500/12 text-violet-600 dark:text-violet-300">
                                        <Sparkles
                                            className={`size-3 ${entry.thinking ? 'animate-pulse' : ''}`}
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div className="min-w-0 pt-0.5">
                                        {entry.thinking ? (
                                            <Thinking />
                                        ) : (
                                            <p
                                                className={`text-sm leading-relaxed ${entry.failed ? 'text-red-600 dark:text-red-400' : ''}`}
                                            >
                                                {entry.reply}
                                            </p>
                                        )}

                                        {/* The picture this note produced,
                                            where it was asked for. A redraw is
                                            a candidate rather than a
                                            replacement — the engine has always
                                            worked that way — so it arrives with
                                            the button that promotes it. */}
                                        {entry.redraw_queued !== false && (
                                            <Drawn
                                                post={post}
                                                // Only the note that queued the
                                                // running redraw waits for it.
                                                // `post.redraw` is a fact about
                                                // the post, and reading it per
                                                // message put a placeholder
                                                // over every past picture at
                                                // once — each one claiming to
                                                // be drawing, and each hiding
                                                // the picture it had already
                                                // produced.
                                                waiting={
                                                    position === lastDrawing
                                                }
                                                produced={producedBy(
                                                    entry,
                                                    log[position + 1] ?? null,
                                                    post,
                                                )}
                                                onOpen={setLooking}
                                            />
                                        )}

                                        {/* And the slides it redrew beside it.
                                            Revising a carousel's picture redraws
                                            its panels too, so a conversation
                                            showing only the photograph would
                                            report one change on a post where
                                            seven things moved. */}
                                        {entry.redraw_queued !== false && (
                                            <Slides
                                                produced={panelsBy(
                                                    entry,
                                                    log[position + 1] ?? null,
                                                    post,
                                                )}
                                            />
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </div>

            <div className="bg-muted/20 p-4">
                <form
                    className="grid gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void send();
                    }}
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2 px-1">
                        <Label htmlFor="post-edit">Describe the change</Label>
                        <span className="text-[11px] text-muted-foreground">
                            Enter to send · Shift+Enter for a new line
                        </span>
                    </div>
                    <div className="relative rounded-2xl border bg-muted/25 p-2 focus-within:ring-2 focus-within:ring-ring/30">
                        <Textarea
                            id="post-edit"
                            value={said}
                            rows={3}
                            maxLength={4000}
                            disabled={pending}
                            placeholder="Make the opening more direct…"
                            // Enter sends, Shift+Enter is a newline. A textarea
                            // in a form does neither on its own, so the button
                            // was the only way to send — and every chat this
                            // borrows from has taught people otherwise.
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' &&
                                    !event.shiftKey &&
                                    !event.nativeEvent.isComposing
                                ) {
                                    event.preventDefault();
                                    void send();
                                }
                            }}
                            className="min-h-20 resize-none border-0 bg-transparent px-2 py-1.5 shadow-none focus-visible:ring-0"
                            onChange={(event) => setSaid(event.target.value)}
                        />
                        <div className="flex items-center justify-between gap-2 px-2 pb-1">
                            <label
                                title="Attach a photo"
                                aria-label="Attach a photo"
                                className="inline-flex size-8 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors focus-within:ring-2 focus-within:ring-ring/40 focus-within:outline-none hover:bg-muted hover:text-foreground"
                            >
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="sr-only"
                                    disabled={pending}
                                    onChange={(event) => {
                                        const file = event.target.files?.[0];
                                        event.target.value = '';

                                        if (file) {
                                            void attach(file);
                                        }
                                    }}
                                />
                                <Plus className="size-4" aria-hidden="true" />
                            </label>
                            <Button
                                type="submit"
                                size="icon"
                                className="size-8 rounded-full"
                                aria-label="Send"
                                disabled={pending || said.trim() === ''}
                            >
                                <Send className="size-3.5" aria-hidden="true" />
                            </Button>
                        </div>
                    </div>
                    {/* A standing note, not a status line. It used to become
                        "Working…", which put the only sign that anything was
                        happening below the input and away from the
                        conversation — where the answer was going to appear. */}
                    <p className="text-[11px] text-muted-foreground">
                        A change to the picture is a paid redraw.
                    </p>
                </form>
            </div>

            <Lightbox
                post={post}
                asset={looking}
                onClose={() => setLooking(null)}
            />
        </section>
    );
}

/**
 * One picture, big enough to judge.
 *
 * The decision this panel exists for — is this the right photograph — cannot be
 * made at 224 pixels wide, and until now that was the only size it was ever
 * shown at inside the conversation. The button that promotes it comes along,
 * because the moment somebody can finally see it is the moment they know.
 */
function Lightbox({
    post,
    asset,
    onClose,
}: {
    post: Post;
    asset: Asset | null;
    onClose: () => void;
}) {
    if (asset === null) {
        return null;
    }

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="w-[calc(100vw-2rem)] max-w-4xl gap-0 overflow-hidden p-0">
                <DialogHeader className="sr-only">
                    <DialogTitle>{asset.alt || 'Picture'}</DialogTitle>
                    <DialogDescription>
                        A picture drawn for this post, full size.
                    </DialogDescription>
                </DialogHeader>

                <img
                    src={asset.url}
                    alt={asset.alt}
                    width={asset.width ?? undefined}
                    height={asset.height ?? undefined}
                    className="max-h-[calc(100dvh-12rem)] w-full bg-muted object-contain"
                />

                <div className="flex flex-wrap items-center justify-between gap-3 border-t px-5 py-3.5">
                    <p className="min-w-0 text-xs text-muted-foreground">
                        {asset.alt}
                    </p>
                    {asset.chosen ? (
                        <span className="shrink-0 text-xs text-muted-foreground">
                            In use on the post
                        </span>
                    ) : (
                        <Form
                            action={choose([post.id, asset.id])}
                            method="post"
                            options={{ preserveScroll: true }}
                            // Closed here rather than on the visit's success:
                            // the picture is chosen either way, and a modal
                            // that lingers over a decision already made reads
                            // as a decision that did not take.
                            onSubmit={() => onClose()}
                        >
                            <Button size="sm">Use this one</Button>
                        </Form>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * What actually happened, in the right tense.
 *
 * "Changed the picture" was wrong twice over: the picture had not changed, and
 * it was not going to for another half minute. Then the opposite bug — once the
 * run finished, the badge fell back to "new picture brief" over a picture that
 * had just been drawn and was on screen. So it reads the run's outcome, not
 * merely whether one is in flight.
 */
function label(what: string, entry: Edit, post: Post): string {
    if (what === 'text') {
        return 'rewrote the words';
    }

    // Only an explicit false means nothing was bought — a real outcome, since
    // a post with no plan has nowhere to hang a run. Null is a note written
    // before the field existed, and reading it as false made the badge deny a
    // redraw that had already happened.
    if (entry.redraw_queued === false) {
        return 'new picture brief';
    }

    return (
        {
            running: 'drawing a new picture…',
            failed: 'the redraw failed',
            done: 'drew a new picture',
        }[post.redraw ?? 'done'] ?? 'drew a new picture'
    );
}

/**
 * The pictures a note produced: the ones that arrived after it was said.
 *
 * An asset carries no reference to the sentence that caused it, and giving it
 * one would mean a column written by a queue worker that a person can never
 * correct. Time is enough here — a redraw is the only thing that adds assets,
 * and the conversation is ordered.
 */
function producedBy(entry: Edit, next: Edit | null, post: Post): Asset[] {
    return post.assets.filter(
        (asset) =>
            asset.created_at !== null &&
            asset.created_at > entry.at &&
            // Bounded above by the next note, or every later picture would be
            // attributed to every earlier note as well.
            (next === null || asset.created_at < next.at),
    );
}

/**
 * The slides a note redrew, by the same clock and for the same reason.
 *
 * Separate from {@link producedBy} rather than folded into it because the two
 * lists are separate on the server and separate on purpose: `assets()` is the
 * candidates a person chooses between, `panels()` is the sequence the post
 * publishes. Merging them here would put a "Use this one" under a slide that
 * has no alternative to be used instead of.
 *
 * Only the live panels are ever in this list — a redraw supersedes rather than
 * duplicating — so a note that redrew a carousel shows its slides, and the
 * notes before it show none. That is the honest reading: the older slides are
 * gone, not hidden.
 */
function panelsBy(entry: Edit, next: Edit | null, post: Post): Panel[] {
    return post.panels.filter(
        (panel) =>
            panel.created_at !== null &&
            panel.created_at > entry.at &&
            (next === null || panel.created_at < next.at),
    );
}

/**
 * A picture being drawn, then the picture, then the button that uses it.
 *
 * The placeholder matters as much as the result: a redraw takes tens of
 * seconds, and a conversation that says "drawing…" and shows nothing gives a
 * person no reason to believe it.
 */
function Drawn({
    post,
    waiting,
    produced,
    onOpen,
}: {
    post: Post;
    waiting: boolean;
    produced: Asset[];
    onOpen: (asset: Asset) => void;
}) {
    if (waiting && post.redraw === 'running') {
        return (
            <div
                className="mt-2 flex aspect-[4/3] w-56 items-center justify-center rounded-xl border border-dashed bg-muted/30"
                role="status"
                aria-label="Drawing a new picture"
            >
                <Sparkles
                    className="size-5 animate-pulse text-muted-foreground"
                    aria-hidden="true"
                />
            </div>
        );
    }

    if (produced.length === 0) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {produced.map((asset) => (
                <figure key={asset.id} className="w-56">
                    {/* A photograph at 224px is a colour swatch. Judging one is
                        the entire reason this panel shows it, so it opens. */}
                    <button
                        type="button"
                        onClick={() => onOpen(asset)}
                        aria-label="See this picture full size"
                        className="block w-full overflow-hidden rounded-xl border transition hover:ring-2 hover:ring-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <img
                            src={asset.url}
                            alt={asset.alt}
                            className="h-auto w-full object-cover"
                        />
                    </button>
                    <figcaption className="mt-1.5">
                        {asset.chosen ? (
                            <span className="text-[11px] text-muted-foreground">
                                In use
                            </span>
                        ) : (
                            <Form
                                action={choose([post.id, asset.id])}
                                method="post"
                                options={{ preserveScroll: true }}
                            >
                                <Button size="sm" variant="outline">
                                    Use this one
                                </Button>
                            </Form>
                        )}
                    </figcaption>
                </figure>
            ))}
        </div>
    );
}

/**
 * The slides a note redrew — evidence, not a choice.
 *
 * Deliberately unlike {@link Drawn} beside it, and the difference is the point.
 * A photograph arrives as a candidate with the button that promotes it, because
 * there is a decision to make. A slide has no alternative: `CarouselPanels`
 * supersedes rather than duplicating, so by the time this renders the redrawn
 * panel *is* slide two. Putting "Use this one" under it would invent a choice
 * that does not exist.
 *
 * Smaller than the photograph for the same reason. This is here to answer "did
 * my instruction reach the carousel", which a strip answers at a glance;
 * judging a slide happens on the sequence viewer beside the post, where they
 * are shown at a size worth judging and in the order they publish.
 */
function Slides({ produced }: { produced: Panel[] }) {
    if (produced.length === 0) {
        return null;
    }

    return (
        <div className="mt-2">
            <p className="mb-1.5 text-[11px] text-muted-foreground">
                {produced.length} {produced.length === 1 ? 'slide' : 'slides'}{' '}
                redrawn from the brief
            </p>
            <div className="flex flex-wrap gap-1.5">
                {produced.map((panel) => (
                    <img
                        key={panel.id}
                        src={panel.url}
                        alt={panel.alt}
                        className="h-20 w-auto rounded-lg border object-cover"
                    />
                ))}
            </div>
        </div>
    );
}

/** Three dots, so a wait reads as a wait rather than as nothing. */
function Thinking() {
    return (
        <p
            className="flex items-center gap-1 py-1"
            role="status"
            aria-label="Thinking"
        >
            {[0, 1, 2].map((dot) => (
                <span
                    key={dot}
                    className="size-1.5 animate-bounce rounded-full bg-muted-foreground/60"
                    style={{ animationDelay: `${dot * 0.15}s` }}
                />
            ))}
        </p>
    );
}
