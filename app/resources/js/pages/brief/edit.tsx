import { Form, Head, router, usePage } from '@inertiajs/react';
import { BookOpen, ChevronDown, History } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { edit, palette, update } from '@/routes/brief';

type BriefContent = {
    id: string;
    positioning: string;
    audience: string;
    tone: string;
    visual_language: string;
    brand_colour: string;
    brand_ink: string;
    /** Empty where the brand has no accent, which means "use the ink". */
    brand_accent: string;
    overlay_position: string;
    overlay_case: string;
    forbidden_topics: string[];
    examples_liked: string[];
    examples_disliked: string[];
    competitors: string[];
};

type BriefVersion = BriefContent & {
    version: number;
    is_active: boolean;
    change_note: string | null;
    created_at: string | null;
    /** How many content units were written from this version. */
    publications: number;
};

type Props = {
    brief: BriefContent | null;
    /** Newest first. */
    versions: BriefVersion[];
    /** Colours read off the site during analysis. Null before there was a browser. */
    palette: Palette | null;
};

/** Declaration order is the order the compiled prompt uses. */
const FIELDS = [
    { key: 'positioning', label: 'Positioning' },
    { key: 'audience', label: 'Audience' },
    { key: 'tone', label: 'Tone of voice' },
    { key: 'visual_language', label: 'Visual language' },
    { key: 'forbidden_topics', label: 'Topics to avoid' },
    { key: 'examples_liked', label: 'Good examples' },
    { key: 'examples_disliked', label: 'Bad examples' },
    { key: 'competitors', label: 'Competitors' },
] as const satisfies ReadonlyArray<{ key: keyof BriefContent; label: string }>;

const LIST_FIELDS = new Set<keyof BriefContent>([
    'forbidden_topics',
    'examples_liked',
    'examples_disliked',
    'competitors',
]);

export default function BrandBriefEdit({ brief, versions, palette }: Props) {
    const { auth } = usePage().props;
    const isOwner = auth.project?.role === 'owner';
    const nextVersion = (versions[0]?.version ?? 0) + 1;

    if (auth.project === null) {
        return (
            <>
                <Head title="Brand brief" />

                <WorkspacePage width="reading">
                    <WorkspaceHeader
                        eyebrow="Brand system"
                        title="Brand brief"
                        description="How this project sounds, and what it will not say."
                    />
                    <Card className={`${workspacePanelClass} py-10`}>
                        <CardHeader>
                            <CardTitle>No project selected</CardTitle>
                            <CardDescription>
                                A brief belongs to one project. Pick one from
                                the switcher to read or edit its brief.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                </WorkspacePage>
            </>
        );
    }

    return (
        <>
            <Head title="Brand brief" />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="Brand system"
                    context={
                        versions[0] === undefined
                            ? 'No saved version'
                            : `Version ${versions[0].version} live`
                    }
                    title="Brand brief"
                    description="How this project sounds, and what it will not say. Every pipeline compiles this into its prompts."
                    actions={
                        <Badge
                            variant={isOwner ? 'default' : 'secondary'}
                            className="h-9 rounded-full px-3"
                        >
                            {isOwner ? 'Editable' : 'Read only'}
                        </Badge>
                    }
                />

                <section
                    className={`${workspacePanelClass} flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6`}
                    aria-label="Brief overview"
                >
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                            <BookOpen className="size-4" aria-hidden="true" />
                        </span>
                        <div>
                            <p className="font-semibold tracking-tight">
                                Editorial source of truth
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {versions.length}{' '}
                                {versions.length === 1 ? 'version' : 'versions'}{' '}
                                preserved
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                        <span>Voice</span>
                        <span aria-hidden="true">·</span>
                        <span>Guardrails</span>
                        <span aria-hidden="true">·</span>
                        <span>Examples</span>
                        <span aria-hidden="true">·</span>
                        <span>Competitors</span>
                    </div>
                </section>

                {!isOwner && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Read-only brief
                            </CardTitle>
                            <CardDescription>
                                Operators can use and review this brief. A
                                project owner must create a new version.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <Form
                    action={update().url}
                    method="put"
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card className={workspacePanelClass}>
                                <CardHeader>
                                    <CardTitle>Voice</CardTitle>
                                    <CardDescription>
                                        The part that decides whether the output
                                        sounds like you.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-4">
                                    {FIELDS.filter(
                                        (field) => !LIST_FIELDS.has(field.key),
                                    ).map((field) => (
                                        <div
                                            key={field.key}
                                            className="grid gap-2"
                                        >
                                            <Label htmlFor={field.key}>
                                                {field.label}
                                            </Label>
                                            <Textarea
                                                id={field.key}
                                                name={field.key}
                                                rows={3}
                                                defaultValue={
                                                    (brief?.[
                                                        field.key
                                                    ] as string) ?? ''
                                                }
                                                readOnly={!isOwner}
                                            />
                                            <InputError
                                                message={errors[field.key]}
                                            />
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            <Card className={workspacePanelClass}>
                                <CardHeader>
                                    <CardTitle>Look</CardTitle>
                                    <CardDescription>
                                        The part something draws with rather
                                        than reads. Visual language above tells
                                        an image model what to make; these tell
                                        the engine what colour to fill, what to
                                        emphasise with, and where to put the
                                        words when it lays out a panel itself.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-4 sm:grid-cols-2">
                                    <Colours
                                        brief={brief}
                                        palette={palette}
                                        disabled={!isOwner}
                                        errors={errors}
                                    />

                                    <div className="grid gap-2">
                                        <Label htmlFor="overlay_position">
                                            Where words sit
                                        </Label>
                                        <select
                                            id="overlay_position"
                                            name="overlay_position"
                                            defaultValue={
                                                brief?.overlay_position ??
                                                'bottom'
                                            }
                                            disabled={!isOwner}
                                            className="h-9 w-full rounded-md border bg-background px-2 text-sm"
                                        >
                                            <option value="top">Top</option>
                                            <option value="centre">
                                                Centre
                                            </option>
                                            <option value="bottom">
                                                Bottom
                                            </option>
                                        </select>
                                        <InputError
                                            message={errors.overlay_position}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="overlay_case">
                                            How they are cased
                                        </Label>
                                        <select
                                            id="overlay_case"
                                            name="overlay_case"
                                            defaultValue={
                                                brief?.overlay_case ??
                                                'sentence'
                                            }
                                            disabled={!isOwner}
                                            className="h-9 w-full rounded-md border bg-background px-2 text-sm"
                                        >
                                            <option value="sentence">
                                                Sentence case
                                            </option>
                                            <option value="upper">
                                                UPPERCASE
                                            </option>
                                        </select>
                                        <InputError
                                            message={errors.overlay_case}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card className={workspacePanelClass}>
                                <CardHeader>
                                    <CardTitle>
                                        Guardrails and examples
                                    </CardTitle>
                                    <CardDescription>
                                        One entry per line. Examples do more
                                        work than adjectives — a sentence you
                                        would publish teaches more than
                                        &ldquo;friendly&rdquo;.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-4">
                                    {FIELDS.filter((field) =>
                                        LIST_FIELDS.has(field.key),
                                    ).map((field) => (
                                        <div
                                            key={field.key}
                                            className="grid gap-2"
                                        >
                                            <Label htmlFor={field.key}>
                                                {field.label}
                                            </Label>
                                            <Textarea
                                                id={field.key}
                                                name={field.key}
                                                rows={4}
                                                defaultValue={(
                                                    (brief?.[
                                                        field.key
                                                    ] as string[]) ?? []
                                                ).join('\n')}
                                                readOnly={!isOwner}
                                            />
                                            <InputError
                                                message={
                                                    errors[field.key] ??
                                                    errors[`${field.key}.0`]
                                                }
                                            />
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            {isOwner && (
                                <>
                                    <Card className={workspacePanelClass}>
                                        <CardHeader>
                                            <CardTitle>
                                                Save as version {nextVersion}
                                            </CardTitle>
                                            <CardDescription>
                                                Saving never overwrites. The
                                                version that is live now stays
                                                readable, and anything already
                                                published keeps pointing at the
                                                version it was written from.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="grid gap-2">
                                            <Label htmlFor="change_note">
                                                Why the change
                                            </Label>
                                            <Input
                                                id="change_note"
                                                name="change_note"
                                                placeholder="Customers found the old voice waffly."
                                            />
                                            <InputError
                                                message={errors.change_note}
                                            />
                                        </CardContent>
                                    </Card>

                                    <div className="flex justify-end border-t pt-5">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-full px-6"
                                        >
                                            Save version {nextVersion}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </>
                    )}
                </Form>

                <VersionHistory versions={versions} />
            </WorkspacePage>
        </>
    );
}

/**
 * What changed, and what it produced.
 *
 * A version on its own is not useful — an operator opening this is asking "what
 * did I change in March, and did anything go out on it". So each entry shows
 * only the fields that differ from the version before it, and how many units
 * were written while it was live.
 */
function VersionHistory({ versions }: { versions: BriefVersion[] }) {
    if (versions.length === 0) {
        return (
            <Card className={workspacePanelClass}>
                <CardHeader>
                    <CardTitle className="text-base">No versions yet</CardTitle>
                    <CardDescription>
                        The first save becomes version 1, and every save after
                        it appears here.
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    return (
        <section
            className="flex flex-col gap-3"
            aria-labelledby="history-title"
        >
            <div>
                <h2
                    id="history-title"
                    className="flex items-center gap-2 font-semibold tracking-tight"
                >
                    <span className="flex size-8 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                        <History className="size-4" aria-hidden="true" />
                    </span>
                    Version history
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Every save remains traceable to the content written from it.
                </p>
            </div>

            {versions.map((version, index) => (
                <VersionEntry
                    key={version.id}
                    version={version}
                    // The list is newest first, so the entry after this one is
                    // the version this one replaced.
                    previous={versions[index + 1]}
                />
            ))}
        </section>
    );
}

function VersionEntry({
    version,
    previous,
}: {
    version: BriefVersion;
    previous?: BriefVersion;
}) {
    const changes = diff(version, previous);

    return (
        <Card className={workspacePanelClass}>
            <Collapsible>
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <CardTitle className="text-base">
                                Version {version.version}
                            </CardTitle>
                            {version.is_active ? (
                                <Badge>Live</Badge>
                            ) : (
                                <Badge variant="secondary">Superseded</Badge>
                            )}
                            {version.publications > 0 && (
                                <Badge variant="outline">
                                    {version.publications}{' '}
                                    {version.publications === 1
                                        ? 'unit'
                                        : 'units'}{' '}
                                    written on it
                                </Badge>
                            )}
                        </div>

                        <CollapsibleTrigger asChild>
                            <Button variant="ghost" size="sm">
                                <ChevronDown
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {previous === undefined
                                    ? 'Show brief'
                                    : `${changes.length} ${changes.length === 1 ? 'field' : 'fields'} changed`}
                            </Button>
                        </CollapsibleTrigger>
                    </div>

                    <CardDescription>
                        {version.change_note ?? 'No reason recorded.'}
                        {version.created_at !== null && (
                            <>
                                {' · '}
                                <time dateTime={version.created_at}>
                                    {new Date(
                                        version.created_at,
                                    ).toLocaleDateString()}
                                </time>
                            </>
                        )}
                    </CardDescription>
                </CardHeader>

                <CollapsibleContent>
                    <CardContent className="flex flex-col gap-4">
                        {changes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing changed from the previous version.
                            </p>
                        ) : (
                            changes.map((change) => (
                                <div
                                    key={change.key}
                                    className="flex flex-col gap-1"
                                >
                                    <p className="text-xs font-medium text-muted-foreground">
                                        {change.label}
                                    </p>
                                    {change.before !== null && (
                                        <p className="border-l-2 border-destructive/40 pl-3 text-sm whitespace-pre-line text-muted-foreground line-through">
                                            {change.before}
                                        </p>
                                    )}
                                    <p className="border-l-2 border-primary/50 pl-3 text-sm whitespace-pre-line">
                                        {change.after === ''
                                            ? '—'
                                            : change.after}
                                    </p>
                                </div>
                            ))
                        )}
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

/**
 * The three colours a renderer draws with, and the site's own suggestion.
 *
 * Controlled rather than `defaultValue`, which is the whole reason this is a
 * component: an uncontrolled input cannot be filled in from outside, and the
 * point of counting the colours off a screenshot is that one click puts them in
 * the fields.
 *
 * **Suggested, never applied.** The palette arrives from site analysis as three
 * values nobody has agreed to, and a wrong fill is not a visible error — it
 * quietly becomes every carousel for a month. So it sits beside the fields as
 * something to click, the same way the assistant's goal is approved rather than
 * written straight into the month.
 */
function Colours({
    brief,
    palette,
    disabled,
    errors,
}: {
    brief: BriefContent | null;
    palette: Palette | null;
    disabled: boolean;
    errors: Record<string, string>;
}) {
    const [colour, setColour] = useState(brief?.brand_colour ?? '#1a1a2e');
    const [ink, setInk] = useState(brief?.brand_ink ?? '#ffffff');
    const [accent, setAccent] = useState(brief?.brand_accent ?? '');

    const differs =
        palette !== null &&
        (palette.fill !== colour ||
            palette.ink !== ink ||
            (palette.accent ?? '') !== accent);

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="brand_colour">Brand colour</Label>
                <input
                    id="brand_colour"
                    name="brand_colour"
                    type="color"
                    value={colour}
                    onChange={(event) => setColour(event.target.value)}
                    disabled={disabled}
                    className="h-9 w-full rounded-md border bg-background px-1"
                />
                <InputError message={errors.brand_colour} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="brand_ink">Text on that colour</Label>
                <input
                    id="brand_ink"
                    name="brand_ink"
                    type="color"
                    value={ink}
                    onChange={(event) => setInk(event.target.value)}
                    disabled={disabled}
                    className="h-9 w-full rounded-md border bg-background px-1"
                />
                <InputError message={errors.brand_ink} />
            </div>

            <AccentField
                value={accent}
                ink={ink}
                disabled={disabled}
                error={errors.brand_accent}
                onChange={setAccent}
            />

            <div className="rounded-2xl border border-dashed p-4 sm:col-span-2">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        {palette !== null && (
                            <span className="flex shrink-0 overflow-hidden rounded-lg border">
                                <Swatch colour={palette.fill} />
                                <Swatch colour={palette.ink} />
                                {palette.accent !== null && (
                                    <Swatch colour={palette.accent} />
                                )}
                            </span>
                        )}
                        <p className="min-w-0 text-xs text-muted-foreground">
                            {palette === null
                                ? 'The engine can photograph your site and count the colours it uses.'
                                : palette.accent === null
                                  ? 'Counted off a picture of your site — it uses one colour, so there is no accent to suggest.'
                                  : 'Counted off a picture of your site.'}
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {palette !== null && differs && !disabled && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setColour(palette.fill);
                                    setInk(palette.ink);
                                    setAccent(palette.accent ?? '');
                                }}
                            >
                                Use these
                            </Button>
                        )}
                        {!disabled && (
                            <ReadSite hasPalette={palette !== null} />
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

/**
 * Photograph the site again and re-count its colours.
 *
 * `router.post` rather than a `<Form>`, because this sits inside the brief's own
 * form and a nested form is invalid markup that browsers resolve by dropping
 * one of them — usually not the one you meant.
 *
 * It reloads the page rather than patching state, which is the honest thing: the
 * server decides whether anything was found, and the outcome arrives as a toast
 * that can say "nothing came back from that page" as easily as it can say it
 * worked.
 */
function ReadSite({ hasPalette }: { hasPalette: boolean }) {
    const [reading, setReading] = useState(false);

    return (
        <Button
            type="button"
            variant={hasPalette ? 'ghost' : 'outline'}
            size="sm"
            disabled={reading}
            onClick={() => {
                setReading(true);
                router.post(
                    palette().url,
                    {},
                    {
                        preserveScroll: true,
                        onFinish: () => setReading(false),
                    },
                );
            }}
        >
            {reading
                ? 'Reading your site…'
                : hasPalette
                  ? 'Read it again'
                  : 'Read my site'}
        </Button>
    );
}

function Swatch({ colour }: { colour: string }) {
    return (
        <span
            className="block size-7"
            style={{ backgroundColor: colour }}
            title={colour}
        />
    );
}

/**
 * The accent, and the one thing a colour input cannot say.
 *
 * `<input type="color">` always has a value, so it has no way to express "this
 * brand has not got an accent" — and that state has to be expressible, because
 * it is the one every brief starts in and the one that keeps carousels looking
 * exactly as they did before the field existed. A swatch alone would force every
 * brand to have a third colour, and the ones that picked whatever the input
 * happened to open on would be the worst off: a wrong accent is not a visible
 * error, it just quietly ships on every carousel.
 *
 * So the checkbox owns the decision and the swatch owns the value, and a hidden
 * field posts the empty string the server reads as "use the ink". The swatch
 * keeps its colour while the box is ticked rather than resetting, so changing
 * your mind twice does not lose the colour you chose.
 */
function AccentField({
    value,
    ink,
    disabled,
    error,
    onChange,
}: {
    value: string;
    ink: string;
    disabled: boolean;
    error?: string;
    onChange: (next: string) => void;
}) {
    const none = value === '';

    // What the swatch falls back to while the accent is switched off, so
    // ticking the box and changing your mind does not lose the colour you
    // picked. It is *only* the fallback — the swatch shows `value` whenever
    // there is one.
    //
    // It used to be the swatch's own state, seeded once, and that was a second
    // source of truth for the same field: pressing "Use these" moved the parent
    // value and left the swatch on the old colour, so the form displayed
    // terracotta and would have saved teal. A control that lies about what it
    // is about to write is worse than one that looks stale.
    const [remembered, setRemembered] = useState(none ? ink : value);

    // Kept in step when the value changes from outside — the suggestion being
    // applied, a version being loaded. React's documented way to adjust state
    // from props: set it during render, and it re-renders before anything is
    // painted rather than flashing the old colour first.
    if (!none && value !== remembered) {
        setRemembered(value);
    }

    const shown = none ? remembered : value;

    return (
        <div className="grid gap-2">
            <Label htmlFor="brand_accent">Accent</Label>
            <input type="hidden" name="brand_accent" value={value} />
            <input
                id="brand_accent"
                type="color"
                value={shown}
                onChange={(event) => {
                    setRemembered(event.target.value);
                    onChange(event.target.value);
                }}
                disabled={disabled || none}
                className="h-9 w-full rounded-md border bg-background px-1 disabled:opacity-45"
            />
            <label className="flex items-center gap-2 text-xs text-muted-foreground">
                <input
                    type="checkbox"
                    checked={none}
                    onChange={(event) =>
                        onChange(event.target.checked ? '' : remembered)
                    }
                    disabled={disabled}
                    className="size-3.5"
                />
                Same as the text colour
            </label>
            <p className="text-xs text-muted-foreground">
                What a carousel emphasises with — the figure on a statistic, the
                half of a comparison that matters.
            </p>
            <InputError message={error} />
        </div>
    );
}

/** Colours counted off a screenshot of the site. Suggestions, never applied. */
type Palette = {
    fill: string;
    ink: string;
    /** Null where the site uses a single colour and there is no second one. */
    accent: string | null;
};

type Change = {
    key: string;
    label: string;
    before: string | null;
    after: string;
};

/**
 * Field-level, not word-level.
 *
 * A character diff of a tone-of-voice paragraph is noise: the question an
 * operator has is which part of the brief moved, and the answer is a field
 * name plus the old and new text side by side. With no previous version every
 * non-empty field counts as new, which is what a first version is.
 */
function diff(version: BriefVersion, previous?: BriefVersion): Change[] {
    return FIELDS.flatMap(({ key, label }) => {
        const after = asText(version[key]);
        const before = previous === undefined ? null : asText(previous[key]);

        if (before === after) {
            return [];
        }

        if (previous === undefined && after === '') {
            return [];
        }

        return [{ key, label, before, after }];
    });
}

function asText(value: string | string[]): string {
    return Array.isArray(value) ? value.join('\n') : value;
}

BrandBriefEdit.layout = {
    breadcrumbs: [{ title: 'Brand brief', href: edit() }],
};
