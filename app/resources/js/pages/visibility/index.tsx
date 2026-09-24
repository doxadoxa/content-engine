import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { resultDate } from '@/components/manager-results';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { SamplingAllowancePanel } from './sampling-allowance';
import type { SamplingAllowance } from './sampling-allowance';
import { platformLabel, TrackedQuestions } from './tracked-questions';
import type { TrackedQuestion } from './tracked-questions';

type Prompt = { text: string; locale: string; intent: string; purpose: string };
type Set = {
    id: string;
    version: number;
    change_reason: string;
    configuration: {
        prompts: Prompt[];
        panel: { platform: string; model: string }[];
        max_answers: number;
    };
};
type Cell = {
    id: string;
    status: string;
    reason: string | null;
    specification: {
        prompt: Prompt;
        platform: { platform: string; model: string };
        market: string;
    };
    answer: {
        id: string;
        received_at: string;
        resolved_model: string | null;
        mentioned_in_text: boolean | null;
        cited_own_site: boolean | null;
        sent_country: string | null;
        web_search_reported: boolean | null;
    } | null;
};
type Report = {
    allowance: SamplingAllowance | null;
    set: Set | null;
    method: string;
    runs: { id: string; created_at: string; status: string }[];
    selected: {
        id: string;
        status: string;
        set_version: number;
        set_id: string;
        expected_cells: number;
        answered_cells: number;
        discovery: { answered: number; mentions: number; citations: number };
        known_cost_micros: number | null;
        unknown_cost_cells: number;
        comparison: { reason: string; previous_run_id: string | null };
        cells: Cell[];
    } | null;
};
const labels: Record<string, string> = {
    queued: 'Queued',
    running: 'Checking answers',
    answered: 'Answer recorded',
    empty: 'No answer returned',
    unavailable: 'Unavailable',
    failed: 'Request refused',
    indeterminate: 'Outcome unknown',
    budget_skipped: 'Outside this run’s allowance',
    complete: 'Check complete',
    partial: 'Check incomplete',
};
const yes = (value: boolean | null) =>
    value === null ? 'Unknown' : value ? 'Yes' : 'No';
export default function Visibility({
    sampling,
    legacy,
    prompts = [],
}: {
    sampling: Report;
    prompts?: TrackedQuestion[];
    legacy?: {
        answers: number;
        mentions: number;
        score: number | null;
        last_asked_on: string | null;
        note: string;
        providers: {
            platform: string;
            label: string;
            score: number | null;
            answered: number;
            mentions: number;
            last_asked_on: string | null;
        }[];
    } | null;
}) {
    const owner = usePage().props.auth.project?.role === 'owner';
    const sample = useForm({
        request_key: crypto.randomUUID(),
        set_id: sampling.set?.id ?? '',
    });
    const run = sampling.selected;
    const [editorRequest, setEditorRequest] = useState(0);
    const [editorOpen, setEditorOpen] = useState(
        !sampling.set && prompts.length === 0,
    );
    const needed =
        (sampling.set?.configuration.prompts.length ?? 0) *
        (sampling.set?.configuration.panel.length ?? 0);
    usePoll(15000, { only: ['sampling', 'prompts', 'legacy'] });

    return (
        <>
            <Head title="AI visibility" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="AI visibility"
                    title="Your business in AI answers"
                    description="See which AI services mention your business when potential customers ask for help."
                    actions={
                        owner && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setEditorOpen(true);
                                        setEditorRequest(
                                            (request) => request + 1,
                                        );
                                    }}
                                >
                                    Manage future questions
                                </Button>
                                {sampling.set && (
                                    <Button
                                        disabled={
                                            sample.processing ||
                                            (sampling.allowance !== null &&
                                                (!sampling.allowance
                                                    .available ||
                                                    sampling.allowance
                                                        .remaining < needed ||
                                                    (sampling.set?.configuration
                                                        .prompts.length ?? 0) >
                                                        sampling.allowance
                                                            .questions))
                                        }
                                        onClick={() => {
                                            sample.transform((data) => ({
                                                ...data,
                                                set_id: sampling.set!.id,
                                            }));
                                            sample.post('/visibility/sample', {
                                                onSuccess: () =>
                                                    sample.setData(
                                                        'request_key',
                                                        crypto.randomUUID(),
                                                    ),
                                            });
                                        }}
                                    >
                                        Check AI visibility
                                        {sampling.allowance &&
                                            ` · ${needed} checks`}
                                    </Button>
                                )}
                            </>
                        )
                    }
                />
                {legacy && legacy.answers > 0 && (
                    <section
                        id="earlier-checks"
                        className={`${workspacePanelClass} scroll-mt-6 p-6`}
                    >
                        <header className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    Earlier AI results
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {legacy.last_asked_on &&
                                        `Measured ${resultDate(legacy.last_asked_on)} · `}
                                    {legacy.mentions} mentions in{' '}
                                    {legacy.answers} recorded answers
                                </p>
                            </div>
                            <p className="text-4xl font-semibold tabular-nums">
                                {legacy.score === null
                                    ? '—'
                                    : `${legacy.score}%`}
                            </p>
                        </header>
                        <div className="mt-5 divide-y rounded-xl border">
                            {legacy.providers.map((provider) => (
                                <div
                                    key={provider.platform}
                                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 p-3 text-sm sm:px-4"
                                >
                                    <h3 className="font-medium">
                                        {provider.label}
                                    </h3>
                                    <p className="font-semibold tabular-nums">
                                        {provider.score === null
                                            ? '—'
                                            : `${provider.score}%`}
                                    </p>
                                    <p className="w-full text-xs text-muted-foreground">
                                        {provider.mentions} mentions /{' '}
                                        {provider.answered} answers
                                        {provider.last_asked_on &&
                                            ` · ${resultDate(provider.last_asked_on)}`}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <details className="mt-4 text-xs leading-5 text-muted-foreground">
                            <summary className="cursor-pointer font-medium text-foreground">
                                How these results were measured
                            </summary>
                            <p className="mt-2">
                                {legacy.note} Visibility is the share of
                                recorded answers that named your business.
                            </p>
                        </details>
                    </section>
                )}
                <SamplingAllowancePanel allowance={sampling.allowance} />
                {Object.values(sample.errors).map((error) => (
                    <p key={error} className="text-sm text-destructive">
                        {error}
                    </p>
                ))}
                {owner && (
                    <Questions
                        key={sampling.set?.id ?? 'new'}
                        set={sampling.set}
                        maxQuestions={sampling.allowance?.questions ?? 60}
                        hasEarlierQuestions={prompts.length > 0}
                        openRequest={editorRequest}
                        open={editorOpen}
                        onOpenChange={setEditorOpen}
                    />
                )}
                {prompts.length > 0 && (
                    <TrackedQuestions
                        id="tracked-questions"
                        title="Questions behind the earlier results"
                        description="The exact questions used for the earlier AI checks above. Each service’s result shows whether it named your business."
                        questions={prompts}
                    />
                )}
                {run && (
                    <TrackedQuestions
                        id="checked-questions"
                        title={`Questions in this check · version ${run.set_version}`}
                        description="These exact questions produced the selected check’s results. Open an answer to see what the AI said."
                        questions={questionsInRun(run.cells)}
                    />
                )}
                {sampling.set && sampling.set.id !== run?.set_id && (
                    <TrackedQuestions
                        id="saved-questions"
                        title={`Questions saved for future checks · version ${sampling.set.version}`}
                        description="Your saved question list. Results from earlier versions remain separate."
                        questions={sampling.set.configuration.prompts.map(
                            (prompt, index) => ({
                                ...prompt,
                                id: `${sampling.set!.id}-${index}`,
                                checks: sampling.set!.configuration.panel.map(
                                    ({ platform }) => ({
                                        platform,
                                        label: platformLabel(platform),
                                        mentioned: null,
                                        status: 'saved',
                                        asked_on: null,
                                    }),
                                ),
                            }),
                        )}
                    />
                )}
                <p className="text-sm text-muted-foreground">
                    Branded accuracy questions check what is said. They are
                    excluded from discovery visibility counts.
                </p>
                <nav
                    className="flex flex-wrap gap-3"
                    aria-label="Sampling history"
                >
                    {sampling.runs.map((item) => (
                        <Link
                            className="rounded border px-3 py-2 text-xs"
                            href={`/visibility/runs/${item.id}`}
                            key={item.id}
                        >
                            {new Date(item.created_at).toLocaleString()} ·{' '}
                            {labels[item.status] ?? item.status}
                        </Link>
                    ))}
                </nav>
                {run ? (
                    <>
                        <section className={`${workspacePanelClass} p-5`}>
                            <h2 className="font-semibold">
                                Question set {run.set_version} ·{' '}
                                {labels[run.status] ?? run.status}
                            </h2>
                            <p className="mt-3 text-sm">
                                {run.answered_cells} answers returned from{' '}
                                {run.expected_cells} planned cells
                            </p>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <p>
                                    Named in text:{' '}
                                    {run.discovery.answered
                                        ? `${run.discovery.mentions} / ${run.discovery.answered} discovery answers`
                                        : 'Not observed'}
                                </p>
                                <p>
                                    Website cited:{' '}
                                    {run.discovery.answered
                                        ? `${run.discovery.citations} / ${run.discovery.answered} discovery answers`
                                        : 'Not observed'}
                                </p>
                            </div>
                            <p className="mt-4 text-xs leading-6 text-muted-foreground">
                                Missing answers are not negative mentions.{' '}
                                {run.comparison.reason}
                            </p>
                            {run.comparison.previous_run_id && (
                                <Link
                                    className="text-xs underline"
                                    href={`/visibility/runs/${run.comparison.previous_run_id}`}
                                >
                                    Previous run
                                </Link>
                            )}
                            <p className="mt-3 text-xs text-muted-foreground">
                                Known provider cost:{' '}
                                {run.known_cost_micros === null
                                    ? 'Not reported'
                                    : `$${(run.known_cost_micros / 1_000_000).toFixed(4)} USD`}{' '}
                                · {run.unknown_cost_cells} attempted cells with
                                unknown cost
                            </p>
                        </section>
                        <section
                            className={`${workspacePanelClass} overflow-x-auto`}
                        >
                            <table className="w-full min-w-[650px] text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="p-4">Exact question</th>
                                        <th className="p-4">
                                            Model and context
                                        </th>
                                        <th className="p-4">Outcome</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {run.cells.map((cell) => (
                                        <tr key={cell.id} className="border-b">
                                            <td className="max-w-lg p-4 align-top">
                                                {cell.specification.prompt.text}
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    {
                                                        cell.specification
                                                            .prompt.locale
                                                    }{' '}
                                                    ·{' '}
                                                    {
                                                        cell.specification
                                                            .prompt.purpose
                                                    }
                                                </p>
                                            </td>
                                            <td className="p-4 align-top text-xs leading-6">
                                                {
                                                    cell.specification.platform
                                                        .platform
                                                }
                                                <br />
                                                Requested:{' '}
                                                {
                                                    cell.specification.platform
                                                        .model
                                                }
                                                <br />
                                                Returned:{' '}
                                                {cell.answer?.resolved_model ??
                                                    'Not reported'}
                                                <br />
                                                Market requested:{' '}
                                                {cell.specification.market}
                                                <br />
                                                Country sent:{' '}
                                                {cell.answer?.sent_country ??
                                                    'Not sent or not reported'}
                                                <br />
                                                Web search reported:{' '}
                                                {yes(
                                                    cell.answer
                                                        ?.web_search_reported ??
                                                        null,
                                                )}
                                            </td>
                                            <td className="max-w-sm p-4 align-top">
                                                <p>
                                                    {labels[cell.status] ??
                                                        cell.status}
                                                </p>
                                                {cell.reason && (
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        {cell.reason}
                                                    </p>
                                                )}
                                                {owner &&
                                                    !cell.answer &&
                                                    ![
                                                        'queued',
                                                        'running',
                                                    ].includes(cell.status) && (
                                                        <CellRecheck
                                                            id={cell.id}
                                                        />
                                                    )}
                                                {cell.answer && (
                                                    <>
                                                        <p className="mt-3 text-xs">
                                                            Mention:{' '}
                                                            {yes(
                                                                cell.answer
                                                                    .mentioned_in_text,
                                                            )}{' '}
                                                            · Citation:{' '}
                                                            {yes(
                                                                cell.answer
                                                                    .cited_own_site,
                                                            )}
                                                        </p>
                                                        <Link
                                                            className="mt-3 inline-block underline"
                                                            href={`/visibility/answers/${cell.answer.id}`}
                                                        >
                                                            Inspect full
                                                            returned answer
                                                        </Link>
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    </>
                ) : (
                    <section className={`${workspacePanelClass} p-6`}>
                        <h2 className="font-semibold">
                            No current full-answer results yet
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Save questions for future checks, then run a manual
                            check when your allowance is available. Earlier
                            results remain available above.
                        </p>
                    </section>
                )}
            </WorkspacePage>
        </>
    );
}
function questionsInRun(cells: Cell[]): TrackedQuestion[] {
    const questions = new Map<string, TrackedQuestion>();

    for (const cell of cells) {
        const prompt = cell.specification.prompt;
        const key = JSON.stringify([
            prompt.text,
            prompt.locale,
            prompt.purpose,
        ]);
        const question = questions.get(key) ?? {
            ...prompt,
            id: key,
            checks: [],
        };
        const platform = cell.specification.platform.platform;
        question.checks.push({
            platform,
            label: platformLabel(platform),
            mentioned: cell.answer?.mentioned_in_text ?? null,
            status:
                cell.status === 'answered' && cell.answer === null
                    ? 'no_answer'
                    : cell.status,
            asked_on: cell.answer?.received_at ?? null,
            answer_url: cell.answer
                ? `/visibility/answers/${cell.answer.id}`
                : undefined,
        });
        questions.set(key, question);
    }

    return [...questions.values()];
}

function Questions({
    set,
    maxQuestions,
    hasEarlierQuestions,
    openRequest,
    open,
    onOpenChange,
}: {
    set: Set | null;
    maxQuestions: number;
    hasEarlierQuestions: boolean;
    openRequest: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const editor = useRef<HTMLDetailsElement>(null);
    const heading = useRef<HTMLSpanElement>(null);
    const form = useForm({
        expected_set_id: set?.id ?? null,
        reason: '',
        prompts: set?.configuration.prompts.map(
            ({ text, locale, intent, purpose }) => ({
                text,
                locale,
                intent,
                purpose,
            }),
        ) ?? [
            { text: '', locale: 'en', intent: 'buying', purpose: 'discovery' },
        ],
    });
    const update = (index: number, key: keyof Prompt, value: string) =>
        form.setData(
            'prompts',
            form.data.prompts.map((prompt, i) =>
                i === index ? { ...prompt, [key]: value } : prompt,
            ),
        );

    useEffect(() => {
        if (openRequest === 0) {
            return;
        }

        requestAnimationFrame(() => {
            editor.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
            heading.current?.focus();
        });
    }, [openRequest]);

    return (
        <details
            ref={editor}
            className={`${workspacePanelClass} p-5`}
            open={open}
            onToggle={(event) => onOpenChange(event.currentTarget.open)}
        >
            <summary className="cursor-pointer font-medium">
                <span ref={heading} tabIndex={-1}>
                    Manage future questions {set && `· version ${set.version}`}
                </span>
            </summary>
            <form
                className="mt-4 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/visibility/sets');
                }}
            >
                {hasEarlierQuestions && (
                    <p className="text-sm text-muted-foreground">
                        The questions above produced your earlier results. This
                        editor saves the questions for future checks.
                    </p>
                )}
                <p className="text-xs leading-6 text-muted-foreground">
                    These questions are used for future checks. Saving changes
                    creates a new version; earlier measurements keep the
                    questions used at the time.
                    {maxQuestions < 60 &&
                        ` Your plan includes up to ${maxQuestions} questions. Saving questions does not purchase a manual recheck; the next scheduled check uses the saved version.`}
                </p>
                {set && (
                    <p className="text-xs text-muted-foreground">
                        {set.change_reason} {set.configuration.max_answers}{' '}
                        answer requests per run maximum. Models:{' '}
                        {set.configuration.panel
                            .map((item) => `${item.platform}: ${item.model}`)
                            .join(' · ')}
                        .
                    </p>
                )}
                {form.data.prompts.map((prompt, index) => (
                    <div
                        key={index}
                        className="grid gap-3 rounded border p-3 sm:grid-cols-4"
                    >
                        <label className="text-xs sm:col-span-4">
                            Question
                            <Textarea
                                className="mt-1"
                                maxLength={500}
                                value={prompt.text}
                                onChange={(event) =>
                                    update(index, 'text', event.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="text-xs">
                            Language
                            <Input
                                className="mt-1"
                                value={prompt.locale}
                                onChange={(event) =>
                                    update(index, 'locale', event.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="text-xs">
                            Purpose
                            <select
                                className="mt-1 w-full rounded border bg-background p-2"
                                value={prompt.purpose}
                                onChange={(event) =>
                                    update(index, 'purpose', event.target.value)
                                }
                            >
                                <option value="discovery">Discovery</option>
                                <option value="accuracy">
                                    Factual accuracy
                                </option>
                            </select>
                        </label>
                        <label className="text-xs">
                            Intent
                            <select
                                className="mt-1 w-full rounded border bg-background p-2"
                                value={prompt.intent}
                                onChange={(event) =>
                                    update(index, 'intent', event.target.value)
                                }
                            >
                                <option value="buying">Buying</option>
                                <option value="comparison">Comparison</option>
                                <option value="learning">Learning</option>
                            </select>
                        </label>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={form.data.prompts.length === 1}
                            onClick={() =>
                                form.setData(
                                    'prompts',
                                    form.data.prompts.filter(
                                        (_, i) => i !== index,
                                    ),
                                )
                            }
                        >
                            Remove
                        </Button>
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    disabled={form.data.prompts.length >= maxQuestions}
                    onClick={() =>
                        form.setData('prompts', [
                            ...form.data.prompts,
                            {
                                text: '',
                                locale: 'en',
                                intent: 'buying',
                                purpose: 'discovery',
                            },
                        ])
                    }
                >
                    Add question
                </Button>
                <label className="block text-sm">
                    Reason for this version
                    <Input
                        className="mt-1"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        required
                    />
                </label>
                {Object.entries(form.errors).map(([key, error]) => (
                    <p key={key} className="text-sm text-destructive">
                        {error}
                    </p>
                ))}
                <Button disabled={form.processing}>
                    Save question version
                </Button>
            </form>
        </details>
    );
}

function CellRecheck({ id }: { id: string }) {
    const form = useForm({ request_key: crypto.randomUUID() });
    const { billing } = usePage().props;
    const limited = Boolean(billing?.plan);

    return (
        <Button
            className="mt-3"
            variant="outline"
            disabled={
                form.processing ||
                (limited &&
                    (!billing?.may_generate ||
                        billing.usage.ai_answers?.remaining === 0))
            }
            onClick={() => form.post(`/visibility/cells/${id}/recheck`)}
        >
            Recheck this question{limited && ' · 1 check'}
        </Button>
    );
}
