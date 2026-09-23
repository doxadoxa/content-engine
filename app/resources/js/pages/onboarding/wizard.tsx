import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Globe,
    Rocket,
    Save,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { postJson } from '@/lib/json';
import { analyse, launch, save } from '@/routes/onboarding';
import { Chips } from './chips';

type Analysis = {
    name: string;
    description: string;
    audiences: string[];
    tone: string;
    visual_language: string;
    competitors: string[];
    seed_keywords: string[];
    forbidden: string[];
    language: string;
    market: string;
    is_ymyl: boolean;
    palette: Palette | null;
};

/** Counted off a picture of the site. Offered, never applied behind your back. */
type Palette = { fill: string; ink: string; accent: string | null };

type Draft = {
    id: string;
    name: string;
    slug: string;
    website_url: string | null;
    sitemap_url: string | null;
    market: string;
    language: string;
    is_ymyl: boolean;
    competitors: string[];
    seed_keywords: string[];
    weekly_target: number;
    autopublish: boolean;
    analysis: Analysis | null;
    onboarding: Record<string, Record<string, unknown>> | null;
};

type Offer = {
    key: string;
    version: number;
    name: string;
    price_cents: number;
    currency: string;
    limits: { articles: number; ai_frequency_days: number };
};
type Props = {
    selectedPlan: Offer;
    plans: Offer[];
    trialDays: number;
    articlesEnabled: boolean;
    draft: Draft | null;
};

const STEPS = [
    'Website',
    'Market',
    'Business',
    'Voice',
    'Competitors',
    'Publishing',
] as const;

/**
 * Setting up a project.
 *
 * The first step does the work — a URL is read and turned into a proposed
 * business, audience and voice — and the six after it are the operator
 * correcting that. Answering seven blank forms is the thing this avoids.
 *
 * Every step saves as it is left, against a project row that exists from the
 * moment the site is read. Closing the tab loses nothing.
 */
export default function Wizard({
    draft: initialDraft,
    articlesEnabled,
    selectedPlan: initialPlan,
    plans,
    trialDays,
}: Props) {
    const [selectedPlan, setSelectedPlan] = useState(initialPlan);
    const [draft, setDraft] = useState<Draft | null>(initialDraft);
    const [step, setStep] = useState(initialDraft ? 1 : 0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <>
            <Head title="Set up a project" />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="New project"
                    context={`Step ${step + 1} of ${STEPS.length}`}
                    title="Set up a project"
                    description="Start with the website, review what Avyo learns, and launch with a brand and publishing plan you control."
                    actions={
                        <Badge
                            variant="outline"
                            className="h-9 rounded-full bg-background/70 px-3"
                        >
                            {draft === null
                                ? 'Not started'
                                : `${draft.name || 'Project'} · saved`}
                        </Badge>
                    }
                />

                <section
                    className={`${workspacePanelClass} p-5`}
                    aria-label="Selected plan"
                >
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p className="font-medium">
                                Your plan after the {trialDays}-day trial
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {selectedPlan.limits.articles} articles per
                                billing month ·{' '}
                                {selectedPlan.limits.ai_frequency_days === 7
                                    ? 'Weekly'
                                    : 'Monthly'}{' '}
                                AI visibility checks
                            </p>
                        </div>
                        <label className="text-sm">
                            <span className="sr-only">Plan</span>
                            <select
                                className="rounded-lg border bg-background px-3 py-2"
                                value={selectedPlan.key}
                                disabled={busy}
                                onChange={async (event) => {
                                    const next = plans.find(
                                        (plan) =>
                                            plan.key === event.target.value,
                                    );

                                    if (!next) {
                                        return;
                                    }

                                    if (!draft) {
                                        router.visit(
                                            `/start?plan=${next.key}&plan_version=${next.version}`,
                                        );

                                        return;
                                    }

                                    setBusy(true);
                                    const result = await postJson<{
                                        project: Draft;
                                    }>(save(draft.id).url, {
                                        step: 'offer',
                                        answers: {
                                            key: next.key,
                                            version: next.version,
                                        },
                                    });
                                    setBusy(false);

                                    if (!result.ok) {
                                        setError(result.message);

                                        return;
                                    }

                                    setDraft(result.data.project);
                                    setSelectedPlan(next);
                                }}
                            >
                                {plans.map((plan) => (
                                    <option key={plan.key} value={plan.key}>
                                        {plan.name} ·{' '}
                                        {new Intl.NumberFormat(undefined, {
                                            style: 'currency',
                                            currency:
                                                plan.currency.toUpperCase(),
                                            maximumFractionDigits: 0,
                                        }).format(plan.price_cents / 100)}
                                        /month
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    {!plans.some(
                        (plan) =>
                            plan.key === selectedPlan.key &&
                            plan.version === selectedPlan.version,
                    ) && (
                        <p className="mt-3 text-sm text-amber-700">
                            The available plans have changed since you started
                            setup. Choose a current plan above and review its
                            price before checkout.
                        </p>
                    )}
                    <p className="mt-3 text-xs text-muted-foreground">
                        Trial: 3 articles and one AI check of 3 questions across
                        4 services. Add a card at checkout; the selected monthly
                        price starts when the trial ends. Cancel before then to
                        avoid the subscription charge.
                    </p>
                </section>
                <Progress step={step} />

                <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
                    <div className="flex min-w-0 flex-col gap-4">
                        {error && <AlertError errors={[error]} />}

                        {step === 0 && (
                            <WebsiteStep
                                draft={draft}
                                busy={busy}
                                onAnalyse={async (url) => {
                                    setBusy(true);
                                    setError(null);

                                    const result = await postJson<{
                                        project: Draft;
                                    }>(analyse().url, {
                                        url,
                                        project_id: draft?.id,
                                    });

                                    setBusy(false);

                                    if (!result.ok) {
                                        setError(result.message);

                                        return;
                                    }

                                    setDraft(result.data.project);
                                    setStep(1);
                                }}
                            />
                        )}

                        {step > 0 && draft && (
                            <Steps
                                packaged={selectedPlan.version >= 4}
                                focused={!articlesEnabled}
                                draft={draft}
                                step={step}
                                busy={busy}
                                onBack={() => setStep((current) => current - 1)}
                                /*
                                 * A list, because the last step answers two
                                 * questions — where articles go, and whether
                                 * they wait for you — and the two are saved
                                 * under the names the server already knows
                                 * them by rather than merged into a third.
                                 */
                                onSave={async (answers) => {
                                    setBusy(true);
                                    setError(null);

                                    let saved: Draft | null = null;

                                    for (const [name, values] of answers) {
                                        const result = await postJson<{
                                            project: Draft;
                                        }>(save(draft.id).url, {
                                            step: name,
                                            answers: values,
                                        });

                                        if (!result.ok) {
                                            setBusy(false);
                                            setError(result.message);

                                            return;
                                        }

                                        saved = result.data.project;
                                    }

                                    setBusy(false);

                                    if (saved !== null) {
                                        setDraft(saved);
                                    }

                                    if (step === STEPS.length - 1) {
                                        setBusy(true);
                                        router.post(
                                            launch(draft.id).url,
                                            {},
                                            {
                                                onError: () => setBusy(false),
                                            },
                                        );

                                        return;
                                    }

                                    setStep((current) => current + 1);
                                }}
                            />
                        )}
                    </div>

                    <SetupGuide
                        step={step}
                        hasDraft={draft !== null}
                        focused={!articlesEnabled}
                    />
                </div>
            </WorkspacePage>
        </>
    );
}

function Progress({ step }: { step: number }) {
    return (
        <section
            className={`${workspacePanelClass} flex flex-col gap-3 px-5 py-4 sm:px-6`}
            aria-label="Project setup progress"
        >
            <div className="flex items-center gap-2">
                {STEPS.map((label, index) => (
                    <div
                        key={label}
                        className={`h-1 flex-1 rounded-full ${
                            index <= step
                                ? 'bg-gradient-to-r from-violet-500 to-orange-400'
                                : 'bg-muted'
                        }`}
                        aria-hidden="true"
                    />
                ))}
            </div>
            <div
                role="progressbar"
                aria-label="Project setup"
                aria-valuemin={1}
                aria-valuemax={STEPS.length}
                aria-valuenow={step + 1}
                aria-valuetext={`Step ${step + 1} of ${STEPS.length}: ${STEPS[step]}`}
            >
                <p className="text-sm font-medium">
                    {STEPS[step]}
                    <span className="ml-2 font-normal text-muted-foreground">
                        {step + 1} of {STEPS.length}
                    </span>
                </p>
            </div>
            <ol
                className="hidden gap-2 lg:grid"
                style={{
                    gridTemplateColumns: `repeat(${STEPS.length}, minmax(0, 1fr))`,
                }}
            >
                {STEPS.map((label, index) => (
                    <li
                        key={label}
                        className={`truncate text-[10px] tracking-wide uppercase ${
                            index <= step
                                ? 'text-foreground'
                                : 'text-muted-foreground'
                        }`}
                    >
                        {label}
                    </li>
                ))}
            </ol>
        </section>
    );
}

function SetupGuide({
    step,
    hasDraft,
    focused,
}: {
    step: number;
    hasDraft: boolean;
    focused: boolean;
}) {
    return (
        <aside
            className={`${workspacePanelClass} hidden flex-col gap-5 p-5 lg:sticky lg:top-6 lg:flex`}
            aria-label="How project setup works"
        >
            <span className="flex size-10 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-600 dark:text-violet-300">
                <Sparkles className="size-5" aria-hidden="true" />
            </span>
            <div>
                <h2 className="font-semibold tracking-tight">
                    Built with you, not for you
                </h2>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                    The site gives us a useful first draft. You remain the
                    editor of every decision.
                </p>
            </div>
            <ol className="flex flex-col gap-4 text-sm">
                <GuideItem
                    done={step > 0}
                    title="Read the website"
                    detail="Business, audience, voice, and market signals."
                />
                <GuideItem
                    done={step >= STEPS.length - 1}
                    title="Review the proposal"
                    detail="Each step saves when you continue."
                />
                <GuideItem
                    done={false}
                    title="Launch the project"
                    detail={
                        focused
                            ? 'Your website audit begins. Track a page and confirm facts before choosing an improvement.'
                            : 'Avyo researches useful topics, plans the calendar and writes your first articles. Your publishing preference controls what happens next.'
                    }
                />
            </ol>
            {hasDraft && (
                <p className="flex items-center gap-2 border-t pt-4 text-xs text-muted-foreground">
                    <Save className="size-3.5" aria-hidden="true" />
                    Progress is attached to this project.
                </p>
            )}
        </aside>
    );
}

function GuideItem({
    done,
    title,
    detail,
}: {
    done: boolean;
    title: string;
    detail: string;
}) {
    return (
        <li className="flex gap-3">
            <span
                className={`mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border ${
                    done
                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600'
                        : 'border-border text-muted-foreground'
                }`}
            >
                {done ? (
                    <Check className="size-3" aria-hidden="true" />
                ) : (
                    <span className="size-1.5 rounded-full bg-current" />
                )}
            </span>
            <span>
                <span className="block font-medium">{title}</span>
                <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                    {detail}
                </span>
            </span>
        </li>
    );
}

function WebsiteStep({
    draft,
    busy,
    onAnalyse,
}: {
    draft: Draft | null;
    busy: boolean;
    onAnalyse: (url: string) => void;
}) {
    const [url, setUrl] = useState(draft?.website_url ?? '');

    return (
        <Card className={`${workspacePanelClass} overflow-hidden`}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Globe className="size-5" aria-hidden="true" />
                    What is the website?
                </CardTitle>
                <CardDescription>
                    We read the homepage and work out what the business does,
                    who it is for and how it sounds. Everything after this is
                    you correcting us.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onAnalyse(url);
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="website">Website URL</Label>
                        <Input
                            id="website"
                            value={url}
                            autoFocus
                            placeholder="example.com"
                            onChange={(event) => setUrl(event.target.value)}
                        />
                    </div>

                    <Button
                        type="submit"
                        disabled={busy || url.trim() === ''}
                        className="self-start rounded-full px-5"
                    >
                        {busy && <Spinner className="size-4" />}
                        {busy ? 'Reading the site…' : 'Analyse'}
                        {!busy && (
                            <ArrowRight className="size-4" aria-hidden="true" />
                        )}
                    </Button>

                    {busy && (
                        <p className="text-sm text-muted-foreground">
                            Fetching the page, reading its headings and links,
                            and asking a model what this business is. Ten
                            seconds or so.
                        </p>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * Steps 2–7.
 *
 * One component holding one draft of answers rather than seven forms with seven
 * pieces of state: the operator moves back and forth, and each step has to
 * still be there when they return to it.
 */
function Steps({
    focused,
    packaged,
    draft,
    step,
    busy,
    onBack,
    onSave,
}: {
    focused: boolean;
    packaged: boolean;
    draft: Draft;
    step: number;
    busy: boolean;
    onBack: () => void;
    onSave: (answers: [string, Record<string, unknown>][]) => void;
}) {
    const saved = <T,>(name: string, key: string, fallback: T): T =>
        (draft.onboarding?.[name]?.[key] as T | undefined) ?? fallback;

    const analysis = draft.analysis;

    const [market, setMarket] = useState(
        saved('market', 'market', draft.market),
    );
    const [language, setLanguage] = useState(
        saved('market', 'language', draft.language),
    );
    const [name, setName] = useState(saved('business', 'name', draft.name));
    const [description, setDescription] = useState(
        saved('business', 'description', analysis?.description ?? ''),
    );
    const [audiences, setAudiences] = useState<string[]>(
        saved('business', 'audiences', analysis?.audiences ?? []),
    );

    const [tone, setTone] = useState(
        saved('voice', 'tone', analysis?.tone ?? ''),
    );
    const [visual, setVisual] = useState(
        saved('voice', 'visual_language', analysis?.visual_language ?? ''),
    );
    const [forbidden, setForbidden] = useState<string[]>(
        saved('voice', 'forbidden', analysis?.forbidden ?? []),
    );
    const [authorName, setAuthorName] = useState(
        saved('voice', 'author_name', ''),
    );
    const [authorTitle, setAuthorTitle] = useState(
        saved('voice', 'author_title', ''),
    );
    const [sitemap, setSitemap] = useState(
        saved('voice', 'sitemap_url', draft.sitemap_url ?? ''),
    );
    const [liked, setLiked] = useState(saved('voice', 'example_liked', ''));
    const [disliked, setDisliked] = useState(
        saved('voice', 'example_disliked', ''),
    );

    const [competitors, setCompetitors] = useState<string[]>(draft.competitors);

    const [endpoint, setEndpoint] = useState(
        saved('channels', 'webhook_endpoint', ''),
    );
    /*
     * How articles reach the site, as a decision rather than as a blank
     * technical field. "Later" is a real answer and the one most people
     * arriving here can honestly give — articles queue either way — so it is
     * where the step starts rather than something to be talked into.
     */
    const [destination, setDestination] = useState<Destination>(
        saved(
            'channels',
            'destination',
            saved('channels', 'webhook_endpoint', '') !== ''
                ? 'custom'
                : 'later',
        ),
    );
    // Kept, not asked. Packaged plans set the cadence themselves; older ones
    // keep whatever the project already had rather than losing it here.
    const weeklyTarget = saved(
        'settings',
        'weekly_target',
        Math.min(7, draft.weekly_target),
    );
    const [automatic, setAutomatic] = useState(
        !draft.is_ymyl && saved('settings', 'autopublish', draft.autopublish),
    );
    const submit = () => {
        switch (step) {
            case 1:
                return onSave([['market', { market, language }]]);
            case 2:
                return onSave([['business', { name, description, audiences }]]);
            case 3:
                return onSave([
                    [
                        'voice',
                        {
                            tone,
                            visual_language: visual,
                            forbidden,
                            author_name: authorName,
                            author_title: authorTitle,
                            example_liked: liked,
                            example_disliked: disliked,
                        },
                    ],
                ]);
            case 4:
                return onSave([['competitors', { competitors }]]);
            default:
                return onSave([
                    [
                        'channels',
                        {
                            destination: focused ? 'later' : destination,
                            // Only a custom site has an address to send to.
                            // Picking WordPress or "later" and leaving a
                            // half-typed URL behind must not connect anything.
                            webhook_endpoint:
                                !focused && destination === 'custom'
                                    ? endpoint
                                    : null,
                            sitemap_url: sitemap,
                        },
                    ],
                    [
                        'settings',
                        {
                            ...(focused || packaged
                                ? {}
                                : { weekly_target: Number(weeklyTarget) }),
                            autopublish:
                                !focused && !draft.is_ymyl && automatic,
                        },
                    ],
                ]);
        }
    };

    const last = step === STEPS.length - 1;

    return (
        <Card className={`${workspacePanelClass} overflow-hidden`}>
            <CardHeader>
                <CardTitle>{HEADINGS[step].title}</CardTitle>
                <CardDescription>{HEADINGS[step].description}</CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    className="flex flex-col gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    {step === 1 && (
                        <>
                            <Field
                                id="market"
                                label="Market"
                                hint="Where the readers are. Search volumes and examples follow this."
                            >
                                <Input
                                    id="market"
                                    value={market}
                                    onChange={(e) => setMarket(e.target.value)}
                                    placeholder="pt"
                                />
                            </Field>
                            <Field
                                id="language"
                                label="Language"
                                hint={
                                    focused
                                        ? 'The language of the pages you want to improve.'
                                        : 'What the articles are written in.'
                                }
                            >
                                <Input
                                    id="language"
                                    value={language}
                                    onChange={(e) =>
                                        setLanguage(e.target.value)
                                    }
                                    placeholder="en"
                                />
                            </Field>

                            {draft.is_ymyl && <YmylNotice />}
                        </>
                    )}

                    {step === 2 && (
                        <>
                            <Field id="name" label="Project name">
                                <Input
                                    id="name"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                />
                            </Field>
                            <Field
                                id="description"
                                label="What the business does"
                                hint="Read off the site. Correct anything we got wrong — every article is written from this."
                                suggested={analysis !== null}
                            >
                                {/*
                                 * The longest thing anybody reads in this
                                 * wizard, and the one paragraph every article
                                 * for the next month is written from. It was
                                 * set at the same line height as a one-line
                                 * hint, which is what made it a wall.
                                 */}
                                <Textarea
                                    id="description"
                                    rows={6}
                                    className="min-h-40 text-base leading-7"
                                    value={description}
                                    onChange={(e) =>
                                        setDescription(e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="audiences"
                                label="Who it is for"
                                suggested={analysis !== null}
                            >
                                <Chips
                                    id="audiences"
                                    values={audiences}
                                    onChange={setAudiences}
                                    placeholder="Lisbon flat owners"
                                />
                            </Field>
                        </>
                    )}

                    {step === 3 && (
                        <>
                            <Field
                                id="tone"
                                label="How it should sound"
                                suggested={analysis !== null}
                            >
                                <Textarea
                                    id="tone"
                                    rows={3}
                                    value={tone}
                                    onChange={(e) => setTone(e.target.value)}
                                />
                            </Field>
                            <Field
                                id="visual"
                                label="Visual language"
                                hint="What pictures in your articles should look like."
                            >
                                <Textarea
                                    id="visual"
                                    rows={2}
                                    className="leading-7"
                                    value={visual}
                                    onChange={(e) => setVisual(e.target.value)}
                                />
                            </Field>
                            {analysis?.palette && (
                                <SitePalette palette={analysis.palette} />
                            )}
                            <Field
                                id="forbidden"
                                label="Never write about"
                                hint="Checked before anything is published."
                            >
                                <Chips
                                    id="forbidden"
                                    values={forbidden}
                                    onChange={setForbidden}
                                    placeholder="price predictions"
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="author"
                                    label="Author byline"
                                    hint={
                                        draft.is_ymyl
                                            ? 'Required for this project: money and health topics need a real named author.'
                                            : 'Optional. Left empty, articles are published under the brand.'
                                    }
                                >
                                    <Input
                                        id="author"
                                        value={authorName}
                                        onChange={(e) =>
                                            setAuthorName(e.target.value)
                                        }
                                    />
                                </Field>
                                <Field id="author-title" label="Their title">
                                    <Input
                                        id="author-title"
                                        value={authorTitle}
                                        onChange={(e) =>
                                            setAuthorTitle(e.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="liked"
                                    label="A sentence you would write"
                                    hint="One example is worth a paragraph of description."
                                >
                                    <Textarea
                                        id="liked"
                                        rows={2}
                                        value={liked}
                                        onChange={(e) =>
                                            setLiked(e.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    id="disliked"
                                    label="And one you never would"
                                >
                                    <Textarea
                                        id="disliked"
                                        rows={2}
                                        value={disliked}
                                        onChange={(e) =>
                                            setDisliked(e.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </>
                    )}

                    {step === 4 && (
                        <>
                            <Field
                                id="competitors"
                                label="Competitors"
                                hint="We read what they rank for and look for the gaps."
                                suggested={analysis !== null}
                            >
                                <Chips
                                    id="competitors"
                                    values={competitors}
                                    onChange={setCompetitors}
                                    placeholder="competitor.com"
                                />
                            </Field>
                            {draft.seed_keywords.length > 0 && (
                                <div className="rounded-lg border bg-muted/40 p-3">
                                    <p className="mb-2 text-sm font-medium">
                                        Research will start from
                                    </p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {draft.seed_keywords.map((keyword) => (
                                            <Badge
                                                key={keyword}
                                                variant="outline"
                                            >
                                                {keyword}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    )}

                    {last && (
                        <>
                            {focused ? (
                                <div className="rounded-xl border p-4 text-sm leading-6">
                                    <p>
                                        You will connect a specific existing
                                        page after setup. WordPress uses the
                                        Avyo receiver plugin and an application
                                        password. Compatible custom sites use a
                                        signed page receiver. Assisted
                                        publishing remains available.
                                    </p>
                                    <p className="mt-3 text-muted-foreground">
                                        Finish setup first, then open Content to
                                        select the page and inspect what can
                                        safely be edited.
                                    </p>
                                </div>
                            ) : (
                                <>
                                    <fieldset className="flex flex-col gap-2">
                                        <legend className="mb-2 text-sm font-medium">
                                            Where should finished articles go?
                                        </legend>
                                        <DestinationChoice
                                            value={destination}
                                            onChange={setDestination}
                                        />
                                        {destination === 'custom' && (
                                            <div className="mt-2">
                                                <Field
                                                    id="endpoint"
                                                    label="The address your developer gave you"
                                                    hint="We send a signed test request when you finish. If your site answers with anything other than success, nothing publishes and your dashboard says so."
                                                >
                                                    <Input
                                                        id="endpoint"
                                                        value={endpoint}
                                                        onChange={(e) =>
                                                            setEndpoint(
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="https://example.com/api/content-engine"
                                                    />
                                                </Field>
                                            </div>
                                        )}
                                    </fieldset>

                                    {/*
                                     * Beside the destination rather than in
                                     * the Voice step, where it used to sit
                                     * between two questions about how the
                                     * writing should sound: a sitemap is a
                                     * fact about the website.
                                     */}
                                    <Field
                                        id="sitemap"
                                        label="Sitemap address"
                                        hint="Optional. Used to find your own pages worth linking to from new articles."
                                    >
                                        <Input
                                            id="sitemap"
                                            value={sitemap}
                                            onChange={(e) =>
                                                setSitemap(e.target.value)
                                            }
                                            placeholder="https://example.com/sitemap.xml"
                                        />
                                    </Field>

                                    {draft.is_ymyl ? (
                                        /*
                                         * A rule, said as one.
                                         *
                                         * This used to be a disabled dropdown
                                         * showing "Let me review each article
                                         * first", which reads as a default
                                         * somebody chose for you rather than
                                         * as something the topic requires.
                                         */
                                        <div className="rounded-xl border bg-background/30 p-4 text-sm leading-6">
                                            <p className="font-medium">
                                                Every article waits for your
                                                approval
                                            </p>
                                            <p className="mt-1 text-muted-foreground">
                                                Your site covers money, health
                                                or safety topics. Avyo
                                                fact-checks those and holds them
                                                for you rather than publishing
                                                them itself.
                                            </p>
                                        </div>
                                    ) : (
                                        <Field
                                            id="publishing-mode"
                                            label="Once an article is written"
                                            hint="You can change this at any time, and pause any single article."
                                        >
                                            <Select
                                                value={
                                                    automatic
                                                        ? 'automatic'
                                                        : 'review'
                                                }
                                                onValueChange={(value) =>
                                                    setAutomatic(
                                                        value === 'automatic',
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="publishing-mode">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="automatic">
                                                        Publish it for me, on
                                                        the calendar
                                                    </SelectItem>
                                                    <SelectItem value="review">
                                                        Let me read it first
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                    )}
                                </>
                            )}
                            <p className="rounded-xl border bg-background/30 p-4 text-sm leading-6">
                                {focused
                                    ? 'Start with an existing page and current confirmed facts. The site audit runs during setup. Choose a bounded opportunity in Plan; review and publication each need your separate decision.'
                                    : destination === 'later'
                                      ? 'Avyo researches your topics, plans the month and writes the articles. They wait in your calendar until you connect your website — nothing is lost in the meantime.'
                                      : automatic && !draft.is_ymyl
                                        ? 'Avyo writes your articles and publishes them on schedule, once your website answers our test and the article passes its checks. Anything that needs attention waits for you.'
                                        : 'Avyo plans and writes your articles. Each one waits for your approval before its scheduled publication.'}
                            </p>
                        </>
                    )}

                    <div className="flex items-center justify-between gap-3 border-t pt-4">
                        <Button
                            type="button"
                            variant="ghost"
                            className="rounded-full"
                            onClick={onBack}
                            disabled={busy}
                        >
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            Back
                        </Button>
                        <Button
                            type="submit"
                            disabled={busy}
                            className="rounded-full px-5"
                        >
                            {busy && <Spinner className="size-4" />}
                            {last ? 'Start my content calendar' : 'Continue'}
                            {!busy &&
                                (last ? (
                                    <Rocket
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                ))}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

const HEADINGS = [
    { title: '', description: '' },
    {
        title: 'Who are you writing for?',
        description:
            'Choose where your customers are and the first language to focus on. Search evidence follows this market.',
    },
    {
        title: 'What does the business do?',
        description:
            'Read off your site. Every article is written from this, so it is worth a minute.',
    },
    {
        title: 'How should it sound?',
        description:
            'Tone, and the things that must never appear. Both are checked before anything is published.',
    },
    {
        title: 'Who else is in this space?',
        description:
            'We read what they rank for and look for what they have missed.',
    },
    {
        title: 'What happens to a finished article?',
        description:
            'Where it goes, and whether it waits for you. Neither has to be settled today.',
    },
] as const;

/** Where finished articles go. "Later" is an answer, not a postponement. */
type Destination = 'wordpress' | 'custom' | 'later';

const DESTINATIONS: {
    value: Destination;
    label: string;
    detail: string;
}[] = [
    {
        value: 'later',
        label: 'Decide later',
        detail: 'Articles wait in your calendar. Connect your site whenever you are ready, or copy each one across yourself.',
    },
    {
        value: 'wordpress',
        label: 'My site runs on WordPress',
        detail: 'We will give you a small plugin and a key after setup. Installing it takes a few minutes.',
    },
    {
        value: 'custom',
        label: 'Something else',
        detail: 'Your web developer gives you an address we send finished articles to.',
    },
];

/**
 * The destination, as three plainly worded cards.
 *
 * This step used to be one empty field labelled "your site's receiving
 * endpoint", which is a sentence written for whoever built the site rather
 * than for whoever owns it — and it had no visible way past, so the only
 * obvious move was to guess at a URL or abandon setup.
 */
function DestinationChoice({
    value,
    onChange,
}: {
    value: Destination;
    onChange: (value: Destination) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            {DESTINATIONS.map((option) => (
                <label
                    key={option.value}
                    className={`flex cursor-pointer gap-3 rounded-xl border p-4 text-sm transition-colors ${
                        value === option.value
                            ? 'border-primary bg-primary/5'
                            : 'hover:bg-muted/40'
                    }`}
                >
                    <input
                        type="radio"
                        name="destination"
                        className="mt-1 size-4 shrink-0 accent-primary"
                        value={option.value}
                        checked={value === option.value}
                        onChange={() => onChange(option.value)}
                    />
                    <span>
                        <span className="block font-medium">
                            {option.label}
                        </span>
                        <span className="mt-0.5 block leading-relaxed text-muted-foreground">
                            {option.detail}
                        </span>
                    </span>
                </label>
            ))}
        </div>
    );
}

/**
 * The colours counted off a picture of the site.
 *
 * Shown, not applied. They already existed at this point in setup and were
 * only ever offered on the Brand brief screen, which is somewhere nobody new
 * has been yet — so the first illustrated article arrived in colours the
 * owner had never been shown.
 */
function SitePalette({ palette }: { palette: Palette }) {
    const swatches = [
        { hex: palette.fill, label: 'Brand colour' },
        { hex: palette.ink, label: 'Text on it' },
        ...(palette.accent ? [{ hex: palette.accent, label: 'Accent' }] : []),
    ];

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm font-medium">
                Your colours{' '}
                <Badge
                    variant="outline"
                    className="ml-1 rounded-full text-xs font-normal"
                >
                    from your site
                </Badge>
            </p>
            <div className="flex flex-wrap gap-4 rounded-xl border p-4">
                {swatches.map((swatch) => (
                    <div
                        key={swatch.label}
                        className="flex items-center gap-2 text-sm"
                    >
                        <span
                            className="size-8 rounded-lg border"
                            style={{ backgroundColor: swatch.hex }}
                            aria-hidden="true"
                        />
                        <span>
                            <span className="block">{swatch.label}</span>
                            <span className="block font-mono text-xs text-muted-foreground uppercase">
                                {swatch.hex}
                            </span>
                        </span>
                    </div>
                ))}
            </div>
            <p className="text-sm leading-relaxed text-muted-foreground">
                Used for the pictures in your articles. Change them in Brand
                brief at any time.
            </p>
        </div>
    );
}

function YmylNotice() {
    return (
        <div className="flex gap-3 rounded-[1.25rem] border border-amber-500/40 bg-amber-50/50 p-4 text-sm dark:bg-amber-950/20">
            <Check
                className="mt-0.5 size-4 shrink-0 text-amber-600"
                aria-hidden="true"
            />
            <p>
                We read this as a money-or-health topic. Articles will be
                fact-checked and held for approval rather than published
                straight out.
            </p>
        </div>
    );
}

function Field({
    id,
    label,
    hint,
    suggested,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    suggested?: boolean;
    children: React.ReactNode;
}) {
    return (
        /*
         * `flex`, not `grid`.
         *
         * Two of these sit side by side in a two-column grid, and a grid child
         * stretches to the row's height. An auto-row grid inside that stretched
         * box distributes the spare height between its own rows — so the field
         * whose neighbour carries a hint had its label and its input pushed
         * apart by however many lines that hint ran to, and the two columns
         * lined up on nothing. A column flex leaves the spare height at the
         * bottom, where nobody can see it.
         */
        <div className="flex flex-col gap-2">
            <div className="flex min-h-6 items-center gap-2">
                <Label htmlFor={id}>{label}</Label>
                {suggested && (
                    <Badge
                        variant="outline"
                        className="rounded-full text-xs font-normal"
                    >
                        from your site
                    </Badge>
                )}
            </div>
            {children}
            {hint && (
                <p className="text-sm leading-relaxed text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}
