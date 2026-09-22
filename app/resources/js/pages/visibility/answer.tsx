import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';

import AccuracyPanel from './accuracy-panel';
import type { AccuracyData } from './accuracy-panel';
import { SamplingAllowancePanel } from './sampling-allowance';
import type { SamplingAllowance } from './sampling-allowance';

type Answer = {
    id: string;
    full_text: string;
    received_at: string;
    resolved_model: string | null;
    sections: { text: string; annotations: { url: string; title: string }[] }[];
    metadata: Record<string, unknown>;
};
type Cell = {
    sampling_run_id: string;
    specification: {
        prompt: { text: string; locale: string; purpose: string };
        platform: { platform: string; model: string };
        market: string;
    };
};
export default function AnswerDetail({
    answer,
    cell,
    accuracy,
    allowance,
}: {
    answer: Answer;
    cell: Cell;
    accuracy: AccuracyData;
    allowance: SamplingAllowance | null;
}) {
    const owner = usePage().props.auth.project?.role === 'owner';
    const form = useForm({ request_key: crypto.randomUUID() });

    return (
        <>
            <Head title="Recorded AI answer" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Recorded AI answer"
                    title={cell.specification.prompt.text}
                    description={`${cell.specification.prompt.locale} · ${cell.specification.prompt.purpose} · ${cell.specification.platform.platform}`}
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link
                                    href={`/visibility/runs/${cell.sampling_run_id}`}
                                >
                                    Sampling run
                                </Link>
                            </Button>
                            {owner && (
                                <Button
                                    disabled={
                                        form.processing ||
                                        (allowance !== null &&
                                            (!allowance.available ||
                                                allowance.remaining < 1))
                                    }
                                    onClick={() =>
                                        form.post(
                                            `/visibility/answers/${answer.id}/recheck`,
                                        )
                                    }
                                >
                                    Recheck this question
                                    {allowance && ' · 1 check'}
                                </Button>
                            )}
                        </>
                    }
                />
                <SamplingAllowancePanel allowance={allowance} />
                <section
                    className={`${workspacePanelClass} p-5 text-sm leading-7`}
                >
                    <p>
                        Recorded:{' '}
                        {new Date(answer.received_at).toLocaleString()}
                    </p>
                    <p>
                        Requested model: {cell.specification.platform.model} ·
                        Returned model:{' '}
                        {answer.resolved_model ?? 'Not reported'}
                    </p>
                    <p>
                        Requested market: {cell.specification.market} · Country
                        sent:{' '}
                        {typeof answer.metadata.sent_country === 'string'
                            ? answer.metadata.sent_country
                            : 'Not sent or not reported'}
                    </p>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Provider completion details:{' '}
                        {typeof answer.metadata.finish_reason === 'string'
                            ? answer.metadata.finish_reason
                            : 'Not reported'}
                        . Reasoning summaries are excluded. A sampled API answer
                        does not reproduce every customer’s experience.
                    </p>
                </section>
                <section className={`${workspacePanelClass} p-5`}>
                    <h2 className="font-semibold">Full returned answer</h2>
                    {answer.sections.length === 0 && (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No final answer text was returned.
                        </p>
                    )}
                    {answer.sections.map((section, index) => (
                        <div key={index} className="mt-5">
                            <p className="text-sm leading-7 whitespace-pre-wrap">
                                {section.text}
                            </p>
                            <div className="mt-3 flex flex-col gap-2 text-xs">
                                {section.annotations.map((citation, i) => (
                                    <a
                                        key={i}
                                        href={citation.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="break-all underline"
                                    >
                                        {citation.title || citation.url}
                                    </a>
                                ))}
                            </div>
                        </div>
                    ))}
                    <p className="mt-5 border-t pt-4 text-xs text-muted-foreground">
                        Citations are supplied by the answer provider. They do
                        not prove where an inaccurate claim originated.
                    </p>
                </section>
                <AccuracyPanel
                    answerId={answer.id}
                    owner={owner}
                    accuracy={accuracy}
                />
            </WorkspacePage>
        </>
    );
}
