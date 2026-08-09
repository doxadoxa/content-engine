import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Inbox,
    MessageCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Pagination } from '@/components/pagination';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index } from '@/routes/approvals';
import { approve, reject, show } from '@/routes/content';
import type { Paginated } from '@/types';

type Draft = {
    id: string;
    title: string;
    slug: string;
    locale: string;
    type_label: string;
    target_query: string | null;
    scheduled_for: string | null;
    needs_original_data: boolean;
    locales: string[];
    derivatives: number;
    factcheck_passed: boolean;
    factcheck_findings: number;
    entity_coverage: number | null;
    was_rejected: boolean;
    publishable: boolean;
    blocking: string[];
    is_social: boolean;
    social_band: string | null;
    social_band_label: string | null;
    segments: number;
    excerpt: string | null;
    slot_at: string | null;
    expires_at: string | null;
};

type Reason = { value: string; label: string };

type Props = {
    drafts: Paginated<Draft>;
    reasons: Reason[];
};

/**
 * The queue an operator opens every morning.
 *
 * The whole design goal is that approving is one click and *not* approving is
 * an informed choice — so the two things that would make somebody hesitate,
 * a failed fact-check and thin entity coverage, are on the row rather than one
 * click inside it.
 */
export default function Approvals({ drafts, reasons }: Props) {
    const [rejecting, setRejecting] = useState<Draft | null>(null);

    return (
        <>
            <Head title="Approvals" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Editorial queue"
                    context={`${drafts.total} ${drafts.total === 1 ? 'decision' : 'decisions'}`}
                    title="Approvals"
                    description="Drafts waiting on a decision. Oldest scheduled date first."
                />

                {drafts.data.length > 0 && (
                    <QueueSummary drafts={drafts.data} total={drafts.total} />
                )}

                {drafts.data.length === 0 ? (
                    <EmptyQueue />
                ) : (
                    <div className="flex flex-col gap-3">
                        {drafts.data.map((draft) => (
                            <DraftRow
                                key={draft.id}
                                draft={draft}
                                onReject={() => setRejecting(draft)}
                            />
                        ))}
                    </div>
                )}

                <Pagination page={drafts} />
            </WorkspacePage>

            <RejectDialog
                draft={rejecting}
                reasons={reasons}
                onClose={() => setRejecting(null)}
            />
        </>
    );
}

/**
 * A slot or an expiry as a person reads it — a day and a time, in the reader's
 * own zone. §4.3's presence window is about being at a phone at a particular
 * hour, so the hour is the part that has to be legible.
 */
function formatSlot(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function QueueSummary({ drafts, total }: { drafts: Draft[]; total: number }) {
    const ready = drafts.filter((draft) => draft.publishable).length;
    const blocked = drafts.length - ready;
    const posts = drafts.filter((draft) => draft.is_social).length;

    return (
        <section
            className={`${workspacePanelClass} flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6`}
            aria-label="Approval queue overview"
        >
            <div className="flex items-center gap-3">
                <span className="flex size-10 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                    <Inbox className="size-4" aria-hidden="true" />
                </span>
                <div>
                    <p className="font-semibold tracking-tight">
                        {total} waiting
                    </p>
                    <p className="text-xs text-muted-foreground">
                        This page shows {drafts.length} in scheduled order
                    </p>
                </div>
            </div>
            <div className="flex gap-2">
                <Badge className="rounded-full">{ready} ready</Badge>
                {posts > 0 && (
                    <Badge variant="outline" className="rounded-full">
                        {posts} post{posts === 1 ? '' : 's'}
                    </Badge>
                )}
                {blocked > 0 && (
                    <Badge variant="destructive" className="rounded-full">
                        {blocked} blocked
                    </Badge>
                )}
            </div>
        </section>
    );
}

function DraftRow({ draft, onReject }: { draft: Draft; onReject: () => void }) {
    return (
        <Card
            className={`${workspacePanelClass} gap-4 border-l-2 ${
                draft.publishable
                    ? 'border-l-emerald-500/70'
                    : 'border-l-destructive/70'
            }`}
        >
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-base leading-snug break-words">
                            <Link
                                href={show(draft.id)}
                                className="hover:underline"
                            >
                                {draft.title}
                            </Link>
                        </CardTitle>
                        <CardDescription className="mt-1 break-words">
                            {draft.type_label}
                            {draft.is_social
                                ? draft.social_band_label !== null && (
                                      <> · {draft.social_band_label} band</>
                                  )
                                : draft.target_query !== null && (
                                      <> · {draft.target_query}</>
                                  )}
                            {draft.slot_at !== null ? (
                                <> · slot {formatSlot(draft.slot_at)}</>
                            ) : (
                                draft.scheduled_for !== null && (
                                    <> · due {draft.scheduled_for}</>
                                )
                            )}
                        </CardDescription>

                        {/* A post is short enough to read in the list, and its
                            title is a label the planner wrote rather than
                            anything that gets published. So the decision — is
                            this what we want to say in public — is made on the
                            same row as the button. */}
                        {draft.excerpt !== null && (
                            <p className="mt-3 max-w-prose text-sm whitespace-pre-line text-foreground/80">
                                {draft.excerpt}
                            </p>
                        )}
                    </div>

                    <div className="flex w-full items-center gap-2 sm:w-auto sm:shrink-0">
                        <Button
                            variant="outline"
                            className="flex-1 rounded-full sm:flex-none"
                            onClick={onReject}
                        >
                            Send back
                        </Button>
                        <Form
                            action={approve(draft.id).url}
                            method="post"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="flex flex-col items-end gap-1">
                                    <Button
                                        type="submit"
                                        className="rounded-full"
                                        disabled={
                                            processing || !draft.publishable
                                        }
                                        title={
                                            draft.publishable
                                                ? undefined
                                                : `Blocked: ${draft.blocking.join(', ')}`
                                        }
                                    >
                                        <CheckCircle2
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Approve
                                    </Button>
                                    {errors.approval && (
                                        <p className="max-w-xs text-right text-xs text-destructive">
                                            {errors.approval}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Form>
                    </div>
                </div>
            </CardHeader>

            <CardContent className="flex flex-wrap items-center gap-2 border-t pt-4">
                {draft.is_social ? (
                    <>
                        <Badge variant="outline">
                            <MessageCircle
                                className="size-3"
                                aria-hidden="true"
                            />
                            Post
                        </Badge>
                        {draft.segments > 1 && (
                            <Badge variant="outline">
                                {draft.segments} segments
                            </Badge>
                        )}
                        {draft.expires_at !== null && (
                            <Badge variant="secondary">
                                expires {formatSlot(draft.expires_at)}
                            </Badge>
                        )}
                    </>
                ) : (
                    <>
                        {draft.locales.map((locale) => (
                            <Badge key={locale} variant="outline">
                                {locale}
                            </Badge>
                        ))}

                        {draft.derivatives > 0 && (
                            <Badge variant="outline">
                                {draft.derivatives} derived
                            </Badge>
                        )}
                    </>
                )}

                {/* The reasons not to approve, in front of the operator rather
                    than one click away. */}
                {!draft.factcheck_passed && (
                    <Badge variant="destructive">
                        <AlertTriangle className="size-3" aria-hidden="true" />
                        {draft.factcheck_findings} fact-check finding
                        {draft.factcheck_findings === 1 ? '' : 's'}
                    </Badge>
                )}

                {draft.entity_coverage !== null &&
                    draft.entity_coverage < 1 && (
                        <Badge variant="secondary">
                            {Math.round(draft.entity_coverage * 100)}% entities
                            covered
                        </Badge>
                    )}

                {draft.needs_original_data && (
                    <Badge variant="secondary">needs real data</Badge>
                )}

                {draft.was_rejected && (
                    <Badge variant="secondary">sent back before</Badge>
                )}

                {!draft.publishable && (
                    <Badge variant="destructive">
                        Blocked: {draft.blocking.join(', ')}
                    </Badge>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * A reason is required and comes from a closed set — §7 makes the rejection the
 * input to phase 9's quality loop, and free text cannot be counted.
 */
function RejectDialog({
    draft,
    reasons,
    onClose,
}: {
    draft: Draft | null;
    reasons: Reason[];
    onClose: () => void;
}) {
    return (
        <Dialog
            open={draft !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="rounded-[1.5rem]">
                {draft !== null && (
                    <Form
                        action={reject(draft.id).url}
                        method="post"
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Send back</DialogTitle>
                                    <DialogDescription>
                                        {draft.title}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="grid gap-4 py-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="reason">
                                            What is wrong with it
                                        </Label>
                                        <Select name="reason" required>
                                            <SelectTrigger id="reason">
                                                <SelectValue placeholder="Pick a reason" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {reasons.map((reason) => (
                                                    <SelectItem
                                                        key={reason.value}
                                                        value={reason.value}
                                                    >
                                                        {reason.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground">
                                            Counted across the project, so the
                                            same complaint forty times becomes a
                                            signal about the brief.
                                        </p>
                                        {errors.reason && (
                                            <p className="text-xs text-destructive">
                                                {errors.reason}
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="note">
                                            What would fix it
                                        </Label>
                                        <Textarea
                                            id="note"
                                            name="note"
                                            rows={3}
                                        />
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={onClose}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        Send back
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function EmptyQueue() {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <Inbox
                    className="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <CardTitle>Nothing waiting</CardTitle>
                <CardDescription>
                    Every draft has been decided on. New ones appear here as the
                    daily generation finishes them.
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

Approvals.layout = {
    breadcrumbs: [{ title: 'Approvals', href: index() }],
};
