import { Link } from '@inertiajs/react';
import { Check, Minus, Search } from 'lucide-react';
import { useState } from 'react';
import { resultDate } from '@/components/manager-results';
import { Input } from '@/components/ui/input';
import { workspacePanelClass } from '@/components/workspace-page';

export type QuestionCheck = {
    platform: string;
    label: string;
    mentioned: boolean | null;
    status: string;
    asked_on: string | null;
    excerpt?: string | null;
    citations?: { url: string; title: string }[];
    answer_url?: string;
};
export type TrackedQuestion = {
    id: string;
    text: string;
    locale: string;
    intent: string;
    purpose?: string;
    checks: QuestionCheck[];
};

const intents: Record<string, string> = {
    buying: 'Finding a provider',
    comparison: 'Comparing options',
    learning: 'Looking for advice',
};
const statuses: Record<string, string> = {
    not_checked: 'Not checked yet',
    saved: 'Saved for future checks',
    no_answer: 'No answer',
    queued: 'Waiting for check',
    running: 'Checking',
    empty: 'No answer',
    unavailable: 'Service unavailable',
    failed: 'Check failed',
    indeterminate: 'No recorded result',
    budget_skipped: 'Not checked · limit reached',
};
const platforms: Record<string, string> = {
    chat_gpt: 'ChatGPT',
    gemini: 'Gemini',
    claude: 'Claude',
    perplexity: 'Perplexity',
};
export const platformLabel = (platform: string) =>
    platforms[platform] ?? platform;
const language = (locale: string) => {
    try {
        return (
            new Intl.DisplayNames(['en'], { type: 'language' }).of(
                locale.replaceAll('_', '-'),
            ) ?? locale
        );
    } catch {
        return locale;
    }
};

export function TrackedQuestions({
    id,
    title,
    description,
    questions,
}: {
    id: string;
    title: string;
    description: string;
    questions: TrackedQuestion[];
}) {
    const [query, setQuery] = useState('');
    const [locale, setLocale] = useState('all');
    const [shownFor, setShownFor] = useState<{
        query: string;
        locale: string;
        count: number;
    }>({ query: '', locale: 'all', count: 5 });
    const locales = [
        ...new Set(questions.map((question) => question.locale)),
    ].sort();
    const activeLocale =
        locale === 'all' || locales.includes(locale) ? locale : 'all';
    const filtered = questions.filter(
        (question) =>
            (activeLocale === 'all' || question.locale === activeLocale) &&
            question.text
                .toLocaleLowerCase()
                .includes(query.trim().toLocaleLowerCase()),
    );
    const visible =
        shownFor.query === query && shownFor.locale === activeLocale
            ? Math.min(shownFor.count, Math.max(5, filtered.length))
            : 5;
    const shown = filtered.slice(0, visible);

    return (
        <section
            id={id}
            className={`${workspacePanelClass} scroll-mt-6 overflow-hidden`}
            aria-labelledby={`${id}-title`}
        >
            <header className="space-y-4 border-b p-5 sm:p-6">
                <div>
                    <h2 id={`${id}-title`} className="text-lg font-semibold">
                        {title}{' '}
                        <span className="ml-2 rounded-full bg-muted px-2.5 py-1 text-xs font-medium tabular-nums">
                            {questions.length}
                        </span>
                    </h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                        {description}
                    </p>
                </div>
                {questions.length > 1 && (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label className="min-w-0 flex-1 text-xs font-medium">
                            Find a question
                            <div className="relative mt-1.5">
                                <Search
                                    className="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <Input
                                    type="search"
                                    className="pl-9"
                                    placeholder="Search the exact wording…"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                            </div>
                        </label>
                        {locales.length > 1 && (
                            <label className="text-xs font-medium">
                                Language
                                <select
                                    className="mt-1.5 block h-10 w-full rounded-xl border bg-background px-3 sm:w-44"
                                    value={activeLocale}
                                    onChange={(event) =>
                                        setLocale(event.target.value)
                                    }
                                >
                                    <option value="all">All languages</option>
                                    {locales.map((item) => (
                                        <option key={item} value={item}>
                                            {language(item)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <p
                            className="pb-2 text-xs text-muted-foreground"
                            role="status"
                        >
                            {filtered.length === 0
                                ? `Showing 0 of ${questions.length} questions`
                                : `Showing 1–${shown.length} of ${filtered.length} matching questions`}
                        </p>
                    </div>
                )}
            </header>
            <ul className="divide-y" aria-label={title}>
                {shown.map((question) => (
                    <li
                        key={question.id}
                        className="grid gap-4 p-5 sm:p-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:gap-8"
                    >
                        <div className="min-w-0">
                            <h3 className="text-sm leading-6 font-medium break-words">
                                {question.text}
                            </h3>
                            <p className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span className="rounded-md bg-muted px-2 py-1">
                                    {language(question.locale)}
                                </span>
                                <span>
                                    {intents[question.intent] ??
                                        question.intent}
                                </span>
                                {question.purpose === 'accuracy' && (
                                    <span>
                                        Business fact check · excluded from
                                        visibility score
                                    </span>
                                )}
                            </p>
                        </div>
                        <ul
                            className="grid grid-cols-2 gap-3"
                            aria-label="AI service results"
                        >
                            {question.checks.map((check) => (
                                <li
                                    key={check.platform}
                                    className="min-w-0 text-xs"
                                >
                                    <p className="font-medium">{check.label}</p>
                                    <p
                                        className={`mt-1 flex items-start gap-1.5 leading-5 ${check.status === 'answered' && check.mentioned === true ? 'text-emerald-800 dark:text-emerald-300' : 'text-muted-foreground'}`}
                                    >
                                        {check.status === 'answered' &&
                                        check.mentioned === true ? (
                                            <Check
                                                className="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Minus
                                                className="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {check.status === 'answered'
                                            ? check.mentioned === null
                                                ? 'Mention not assessed'
                                                : check.mentioned
                                                  ? 'Mentioned'
                                                  : 'Not mentioned'
                                            : (statuses[check.status] ??
                                              check.status)}
                                    </p>
                                    {check.asked_on && (
                                        <p className="mt-1 text-muted-foreground">
                                            {resultDate(check.asked_on)}
                                        </p>
                                    )}
                                    {check.answer_url && (
                                        <Link
                                            href={check.answer_url}
                                            className="mt-1 inline-block underline underline-offset-4"
                                        >
                                            View answer
                                            <span className="sr-only">
                                                {' '}
                                                from {check.label} for{' '}
                                                {question.text}
                                            </span>
                                        </Link>
                                    )}
                                    {check.status !== 'not_checked' &&
                                        check.status !== 'saved' &&
                                        (check.excerpt !== undefined ||
                                            check.citations !== undefined) && (
                                            <CheckEvidence check={check} />
                                        )}
                                </li>
                            ))}
                        </ul>
                    </li>
                ))}
            </ul>
            {filtered.length === 0 && (
                <p className="p-6 text-sm text-muted-foreground">
                    {questions.length === 0
                        ? 'No questions saved yet. Add the questions your customers ask to start tracking.'
                        : 'No questions match these filters.'}
                </p>
            )}
            {shown.length < filtered.length && (
                <div className="border-t p-5 sm:p-6">
                    <button
                        type="button"
                        className="text-sm font-medium underline underline-offset-4"
                        onClick={() =>
                            setShownFor({
                                query,
                                locale: activeLocale,
                                count: visible + 5,
                            })
                        }
                    >
                        Show {Math.min(5, filtered.length - shown.length)} more
                        questions
                    </button>
                </div>
            )}
        </section>
    );
}

function CheckEvidence({ check }: { check: QuestionCheck }) {
    const citations = (check.citations ?? []).filter((citation) =>
        safeHttpUrl(citation.url),
    );

    return (
        <details className="mt-2 text-muted-foreground">
            <summary className="cursor-pointer underline underline-offset-4">
                {check.excerpt ? 'View saved excerpt' : 'Answer details'}
            </summary>
            {check.excerpt ? (
                <p className="mt-2 [overflow-wrap:anywhere] break-words whitespace-pre-wrap">
                    {check.excerpt}
                </p>
            ) : (
                <p className="mt-2">
                    No saved excerpt is available for this result.
                </p>
            )}
            {citations.length > 0 && (
                <ul className="mt-2 space-y-1">
                    {citations.map((citation) => (
                        <li key={citation.url}>
                            <a
                                href={citation.url}
                                target="_blank"
                                rel="noreferrer"
                                className="break-all underline underline-offset-4"
                            >
                                {citation.title || citation.url}
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </details>
    );
}

function safeHttpUrl(url: string) {
    try {
        const parsed = new URL(url);

        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
}
