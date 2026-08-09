import { AlertTriangle, Check, Minus } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { workspacePanelClass } from '@/components/workspace-page';

export type ScoreCheck = {
    key: string;
    label: string;
    ok: boolean;
    detail: string;
    severity: 'critical' | 'warning' | 'suggestion' | 'note';
};

export type ArticleData = {
    target_query: string | null;
    topic_volume: number | null;
    topic_difficulty: number | null;
    words: number;
    keywords: number;
    images: number;
    internal_links: number;
    external_links: number;
    slug: string;
    meta_description: string | null;
    meta_length: number;
};

/**
 * The article, scored.
 *
 * The number is a summary of the list under it and never a substitute for
 * reading it — which is why the list is open rather than behind the number.
 * Each line says what it found as well as whether it passed: "3 sections" is
 * something a reviewer can act on, a green tick is not.
 */
export function ScorePanel({
    score,
    checks,
    publishable,
    blocking,
}: {
    score: number;
    checks: ScoreCheck[];
    publishable: boolean;
    blocking: string[];
}) {
    return (
        <Card className={workspacePanelClass}>
            <CardHeader className="items-center pb-2">
                <CardDescription className="text-xs tracking-wide uppercase">
                    Article score
                </CardDescription>
                <Dial score={score} />
                {/* The number and the verdict answer different questions. A
                    piece can score 88 and still be unfit to publish, and an
                    operator reading only the dial would never know which. */}
                {!publishable && (
                    <p className="text-center text-xs text-destructive">
                        Not ready: {blocking.join(', ').toLowerCase()}
                    </p>
                )}
            </CardHeader>
            <CardContent className="flex flex-col gap-1.5 border-t pt-4 text-sm">
                {checks.map((check) => (
                    <span key={check.key} className="flex items-start gap-2">
                        {check.ok ? (
                            <Check
                                className="mt-0.5 size-3.5 shrink-0 text-emerald-500"
                                aria-hidden="true"
                            />
                        ) : check.severity === 'critical' ? (
                            /* A failure that stops publication looks different
                               from one that only costs points. */
                            <AlertTriangle
                                className="mt-0.5 size-3.5 shrink-0 text-destructive"
                                aria-hidden="true"
                            />
                        ) : (
                            <Minus
                                className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        )}
                        <span
                            className={
                                check.ok
                                    ? undefined
                                    : check.severity === 'critical'
                                      ? 'text-destructive'
                                      : 'text-muted-foreground'
                            }
                        >
                            {check.label}
                            <span className="block text-xs text-muted-foreground">
                                {check.detail}
                            </span>
                        </span>
                    </span>
                ))}
            </CardContent>
        </Card>
    );
}

/**
 * A half-circle gauge, drawn rather than imported: one arc for the track and
 * one for the score, clipped by stroke-dasharray.
 */
function Dial({ score }: { score: number }) {
    // The arc is π×r long for a semicircle; the visible portion is the score.
    const radius = 52;
    const length = Math.PI * radius;
    const filled = (Math.max(0, Math.min(100, score)) / 100) * length;

    const tone =
        score >= 80
            ? 'text-emerald-500'
            : score >= 55
              ? 'text-amber-500'
              : 'text-destructive';

    return (
        <div className="relative w-32">
            <svg viewBox="0 0 120 66" className="w-full" aria-hidden="true">
                <path
                    d="M 8 60 A 52 52 0 0 1 112 60"
                    fill="none"
                    strokeWidth="9"
                    strokeLinecap="round"
                    className="stroke-muted"
                />
                <path
                    d="M 8 60 A 52 52 0 0 1 112 60"
                    fill="none"
                    strokeWidth="9"
                    strokeLinecap="round"
                    strokeDasharray={`${filled} ${length}`}
                    className={`${tone} stroke-current transition-all`}
                />
            </svg>
            <div className="absolute inset-x-0 bottom-0 text-center">
                <span className="text-3xl font-semibold tabular-nums">
                    {score}
                </span>
                <span className="text-sm text-muted-foreground">/100</span>
            </div>
            <span className="sr-only">{score} out of 100</span>
        </div>
    );
}

/** The numbers a reviewer checks before approving. */
export function ArticleDataPanel({ data }: { data: ArticleData }) {
    return (
        <Card className={workspacePanelClass}>
            <CardHeader>
                <CardTitle className="text-base">Article data</CardTitle>
                <CardDescription>
                    What it targets, and what it is made of.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {data.target_query !== null && (
                    <div className="rounded-lg border p-3">
                        <p className="font-medium">{data.target_query}</p>
                        <p className="text-xs text-muted-foreground">
                            Search volume {data.topic_volume ?? '—'}/mo ·
                            Difficulty {data.topic_difficulty ?? '—'}
                        </p>
                    </div>
                )}

                <dl className="flex flex-col gap-1.5 text-sm">
                    <Figure
                        label="Word count"
                        value={data.words}
                        good={data.words >= 800}
                    />
                    <Figure
                        label="Keywords"
                        value={data.keywords}
                        good={data.keywords > 0}
                    />
                    <Figure
                        label="Images"
                        value={data.images}
                        good={data.images > 0}
                    />
                    <Figure
                        label="Internal links"
                        value={data.internal_links}
                        good={data.internal_links > 0}
                    />
                    <Figure
                        label="External links"
                        value={data.external_links}
                        good={data.external_links > 0}
                    />
                </dl>

                <div>
                    <p className="text-sm font-medium">Slug</p>
                    <p className="mt-1 truncate rounded-md border px-2 py-1.5 font-mono text-xs">
                        {data.slug}
                    </p>
                </div>

                <div>
                    <p className="text-sm font-medium">Meta description</p>
                    <p className="mt-1 rounded-md border p-2 text-xs">
                        {data.meta_description ?? 'None written.'}
                    </p>
                    <p
                        className={`mt-1 text-xs ${
                            data.meta_length > 160
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        }`}
                    >
                        {data.meta_length}/160 characters
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

function Figure({
    label,
    value,
    good,
}: {
    label: string;
    value: number;
    good: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="flex items-center gap-2 tabular-nums">
                {value.toLocaleString()}
                <span
                    className={`size-1.5 rounded-full ${
                        good ? 'bg-emerald-500' : 'bg-muted-foreground/40'
                    }`}
                    aria-hidden="true"
                />
            </dd>
        </div>
    );
}
