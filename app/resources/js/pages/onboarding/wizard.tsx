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
import { DutyHoursField } from '@/components/duty-hours-field';
import type { DutyHoursValue } from '@/components/duty-hours-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
};

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
    duty_hours: DutyHoursValue;
    analysis: Analysis | null;
    onboarding: Record<string, Record<string, unknown>> | null;
};

type ChannelType = { value: string; label: string; is_social: boolean };

type Props = {
    draft: Draft | null;
    channelTypes: ChannelType[];
};

const STEPS = [
    'Website',
    'Market',
    'Business',
    'Voice',
    'Competitors',
    'Where it goes',
    'Cadence',
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
export default function Wizard({ draft: initialDraft, channelTypes }: Props) {
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
                    description="Start with the website, review what the engine learns, and launch with a brand and publishing plan you control."
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
                                draft={draft}
                                step={step}
                                busy={busy}
                                channelTypes={channelTypes}
                                onBack={() => setStep((current) => current - 1)}
                                onSave={async (name, answers) => {
                                    setBusy(true);
                                    setError(null);

                                    const result = await postJson<{
                                        project: Draft;
                                    }>(save(draft.id).url, {
                                        step: name,
                                        answers,
                                    });

                                    setBusy(false);

                                    if (!result.ok) {
                                        setError(result.message);

                                        return;
                                    }

                                    setDraft(result.data.project);

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

                    <SetupGuide step={step} hasDraft={draft !== null} />
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
            <ol className="hidden grid-cols-7 gap-2 lg:grid">
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

function SetupGuide({ step, hasDraft }: { step: number; hasDraft: boolean }) {
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
                    title="Launch the engine"
                    detail="Research, planning, and first drafts begin."
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
    draft,
    step,
    busy,
    channelTypes,
    onBack,
    onSave,
}: {
    draft: Draft;
    step: number;
    busy: boolean;
    channelTypes: ChannelType[];
    onBack: () => void;
    onSave: (step: string, answers: Record<string, unknown>) => void;
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
    // §4.3: the window the operator is around for, read in the project's own
    // time zone — which is why it is answered on this step and not the last.
    const [dutyHours, setDutyHours] = useState<DutyHoursValue>(
        saved('market', 'duty_hours', draft.duty_hours ?? {}),
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
    const [social, setSocial] = useState<string[]>(
        saved('channels', 'social', []),
    );

    const [weekly, setWeekly] = useState(String(draft.weekly_target));
    const [words, setWords] = useState(
        String(saved('settings', 'target_words', 1400)),
    );
    const [autopublish, setAutopublish] = useState(
        saved('settings', 'autopublish', false),
    );

    const submit = () => {
        switch (step) {
            case 1:
                return onSave('market', {
                    market,
                    language,
                    duty_hours: dutyHours,
                });
            case 2:
                return onSave('business', { name, description, audiences });
            case 3:
                return onSave('voice', {
                    tone,
                    visual_language: visual,
                    forbidden,
                    author_name: authorName,
                    author_title: authorTitle,
                    sitemap_url: sitemap,
                    example_liked: liked,
                    example_disliked: disliked,
                });
            case 4:
                return onSave('competitors', { competitors });
            case 5:
                return onSave('channels', {
                    webhook_endpoint: endpoint,
                    social,
                });
            default:
                return onSave('settings', {
                    weekly_target: Number(weekly),
                    target_words: Number(words),
                    autopublish,
                });
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
                                hint="What the articles are written in."
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
                            <DutyHoursField
                                value={dutyHours}
                                onChange={setDutyHours}
                            />
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
                                <Textarea
                                    id="description"
                                    rows={4}
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
                            <Field id="visual" label="Visual language">
                                <Textarea
                                    id="visual"
                                    rows={2}
                                    value={visual}
                                    onChange={(e) => setVisual(e.target.value)}
                                />
                            </Field>
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
                            <Field
                                id="sitemap"
                                label="Sitemap"
                                hint="Used to find pages worth linking to from new articles."
                            >
                                <Input
                                    id="sitemap"
                                    value={sitemap}
                                    onChange={(e) => setSitemap(e.target.value)}
                                    placeholder="https://example.com/sitemap.xml"
                                />
                            </Field>
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

                    {step === 5 && (
                        <>
                            <Field
                                id="endpoint"
                                label="Your site's receiving endpoint"
                                hint="Not a page on your site — a URL that accepts a POST and stores the article. Install the engine-receiver package and use the route it adds. Leave empty to pull articles from our API instead."
                            >
                                <Input
                                    id="endpoint"
                                    value={endpoint}
                                    onChange={(e) =>
                                        setEndpoint(e.target.value)
                                    }
                                    placeholder="https://example.com/api/content-engine"
                                />
                                <p className="text-sm text-muted-foreground">
                                    We send a signed test request when you
                                    finish. If your site answers with anything
                                    other than success, nothing will publish and
                                    the dashboard will say so.
                                </p>
                            </Field>

                            <fieldset className="flex flex-col gap-3">
                                <legend className="text-sm font-medium">
                                    Social channels
                                </legend>
                                <p className="text-sm text-muted-foreground">
                                    A project is one content layer, not just a
                                    blog. Anything published here is also cut
                                    down for these, from the same brief.
                                </p>
                                {channelTypes
                                    .filter((type) => type.is_social)
                                    .map((type) => (
                                        <label
                                            key={type.value}
                                            className="flex items-center gap-3 rounded-xl border bg-background/30 p-3 text-sm transition-colors hover:bg-muted/30"
                                        >
                                            <Checkbox
                                                checked={social.includes(
                                                    type.value,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    setSocial((current) =>
                                                        checked
                                                            ? [
                                                                  ...current,
                                                                  type.value,
                                                              ]
                                                            : current.filter(
                                                                  (v) =>
                                                                      v !==
                                                                      type.value,
                                                              ),
                                                    )
                                                }
                                            />
                                            {type.label}
                                        </label>
                                    ))}
                            </fieldset>
                        </>
                    )}

                    {last && (
                        <>
                            <Field
                                id="weekly"
                                label="Articles per week"
                                hint="The planner fills a month at this rate."
                            >
                                <Select
                                    value={weekly}
                                    onValueChange={setWeekly}
                                >
                                    <SelectTrigger id="weekly">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['1', '2', '3', '5', '7'].map((n) => (
                                            <SelectItem key={n} value={n}>
                                                {n} per week
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field id="words" label="Target length">
                                <Select value={words} onValueChange={setWords}>
                                    <SelectTrigger id="words">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['800', '1400', '2200'].map((n) => (
                                            <SelectItem key={n} value={n}>
                                                {n} words
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <label className="flex items-start gap-3 rounded-xl border bg-background/30 p-3 text-sm">
                                <Checkbox
                                    checked={autopublish}
                                    disabled={draft.is_ymyl}
                                    onCheckedChange={(checked) =>
                                        setAutopublish(checked === true)
                                    }
                                    className="mt-0.5"
                                />
                                <span>
                                    Publish without waiting for approval
                                    {draft.is_ymyl && (
                                        <span className="block text-muted-foreground">
                                            Not available here — this project
                                            was read as money-or-health, and
                                            those get reviewed.
                                        </span>
                                    )}
                                </span>
                            </label>
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
                            {last ? 'Start writing' : 'Continue'}
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
            'The market decides which search volumes we look at, the language is what gets written, and the duty hours decide when a social post is allowed to go out.',
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
        title: 'Where does it go?',
        description:
            'Your site, and the social channels that share the same voice.',
    },
    {
        title: 'How often?',
        description: 'This is changeable later — nothing here is permanent.',
    },
] as const;

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
        <div className="grid gap-2">
            <div className="flex items-center gap-2">
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
            {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
        </div>
    );
}
