import { Form, Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Clipboard,
    ExternalLink,
    MessagesSquare,
    PenLine,
    Send,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { useClipboard } from '@/hooks/use-clipboard';
import { index, send, sent, skip } from '@/routes/engage';
import type { Paginated } from '@/types';

type Finding = {
    code: string;
    detail: string;
    blocking: boolean;
    /** Whether §4.2's human can clear this by saying they have looked. */
    acknowledgeable: boolean;
};

type Conversation = {
    id: string;
    author: string;
    handle: string | null;
    text: string;
    permalink: string | null;
    received_at: string;
    /** The number §4.2 is judged on, measured on the server's clock. */
    waited_seconds: number;
    state: string;
    state_label: string;
    draft: string | null;
    drafted_at: string | null;
    findings: Finding[];
    sendable: boolean;
    /** §11.1: whether the engine may put this into the thread itself. */
    route: 'api' | 'by_hand';
    route_label: string;
};

type Reason = { value: string; label: string };

type Props = {
    conversations: Paginated<Conversation>;
    reasons: Reason[];
    text_limit: number;
    foreign_replies_allowed: boolean;
    answered_today: number;
};

/** Whether the operator is sending through the engine or posting by hand. */
type Mode = 'api' | 'hand';

/**
 * The duty screen (§7).
 *
 * Mobile by default, and not as a courtesy: §7 says the point of this screen is
 * "подтверждение ответа за минуты с телефона, а не за час с ноутбука", so the
 * card is the real layout and the table is what the same rows become when there
 * is width to spare. Both are driven by one piece of state per conversation, so
 * a draft edited at one size is the same draft at the other.
 *
 * Four things are deliberate about the row.
 *
 * **The wait is the biggest thing on it, and it moves.** §4.2 is judged on the
 * delay between a person writing to us and us answering, so it is derived from
 * `received_at` on an interval rather than printed once: a phone left open on
 * this queue for twenty minutes must not still say 3m in green.
 *
 * **Nothing is said by leaving something out.** §7's last requirement is the
 * one that is easy to skip: "чего движок делать не стал и почему". A
 * conversation with no draft says the engine has not written one; a
 * conversation the engine may not answer says so on the row rather than in an
 * aggregate line at the top of the page; a blocked draft is shown with its
 * reason and never hidden.
 *
 * **A block is cleared by a person, not by a diff.** §10's fact-check on a YMYL
 * project and §9's unconfirmed send are acknowledgeable: one tap, recorded on
 * the server, and not defeatable by adding a full stop.
 *
 * **A conversation the engine may not answer is still workable.** With §11.1's
 * flag off, a reply on somebody else's post is copy, open the thread, post,
 * mark it sent — three taps and no error message, because §4.2 says the contour
 * survives as human-assisting rather than stopping. Which is also why the edit
 * survives leaving the page: see {@link useKeptEdits}.
 */
export default function Engage({
    conversations,
    reasons,
    text_limit,
    foreign_replies_allowed,
    answered_today,
}: Props) {
    const rows = conversations.data;

    // Lifted out of the row so the card and the table are the same editor.
    const [edits, setEdit, forgetEdit] = useKeptEdits();
    const [modes, setModes] = useState<Record<string, Mode>>({});
    const [acknowledged, setAcknowledged] = useState<Record<string, string[]>>(
        {},
    );
    const [skipping, setSkipping] = useState<Conversation | null>(null);

    const now = useTickingClock();

    const textOf = (row: Conversation) => edits[row.id] ?? row.draft ?? '';
    const modeOf = (row: Conversation): Mode =>
        row.route === 'by_hand' ? 'hand' : (modes[row.id] ?? 'api');

    const editor = (row: Conversation) => ({
        conversation: row,
        text: textOf(row),
        mode: modeOf(row),
        limit: text_limit,
        waited: waitedSeconds(row, now),
        acknowledged: acknowledged[row.id] ?? [],
        foreignRepliesAllowed: foreign_replies_allowed,
        onText: (value: string) => setEdit(row.id, value),
        onMode: (mode: Mode) =>
            setModes((current) => ({ ...current, [row.id]: mode })),
        onAcknowledge: (code: string, on: boolean) =>
            setAcknowledged((current) => {
                const codes = current[row.id] ?? [];

                return {
                    ...current,
                    [row.id]: on
                        ? [...codes.filter((it) => it !== code), code]
                        : codes.filter((it) => it !== code),
                };
            }),
        onSent: () => forgetEdit(row.id),
        onSkip: () => setSkipping(row),
    });

    return (
        <>
            <Head title="Conversations" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Conversation queue"
                    context={`${conversations.total} ${conversations.total === 1 ? 'reply' : 'replies'} owed`}
                    title="Conversations"
                    description="Replies and mentions waiting for you, oldest first. Responding within the first hour has the most impact."
                />

                {rows.length > 0 && (
                    <QueueSummary
                        rows={rows}
                        now={now}
                        total={conversations.total}
                        answeredToday={answered_today}
                        foreignRepliesAllowed={foreign_replies_allowed}
                    />
                )}

                {rows.length === 0 ? (
                    <EmptyQueue answeredToday={answered_today} />
                ) : (
                    <>
                        <div className="flex flex-col gap-3 lg:hidden">
                            {rows.map((row) => (
                                <MobileCard key={row.id} {...editor(row)} />
                            ))}
                        </div>

                        <Card
                            className={`${workspacePanelClass} hidden max-w-full overflow-hidden p-0 lg:block`}
                        >
                            <Table className="min-w-[960px]">
                                <TableHeader className="bg-muted/20 text-xs tracking-wide uppercase">
                                    <TableRow>
                                        <TableHead className="w-32">
                                            Waiting
                                        </TableHead>
                                        <TableHead className="w-[28rem]">
                                            Conversation
                                        </TableHead>
                                        <TableHead>Reply</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <DesktopRow
                                            key={row.id}
                                            {...editor(row)}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    </>
                )}

                <Pagination page={conversations} />
            </WorkspacePage>

            <SkipDialog
                conversation={skipping}
                reasons={reasons}
                onClose={() => setSkipping(null)}
            />
        </>
    );
}

type EditorProps = {
    conversation: Conversation;
    text: string;
    mode: Mode;
    limit: number;
    waited: number;
    acknowledged: string[];
    foreignRepliesAllowed: boolean;
    onText: (value: string) => void;
    onMode: (mode: Mode) => void;
    onAcknowledge: (code: string, on: boolean) => void;
    onSent: () => void;
    onSkip: () => void;
};

function MobileCard(props: EditorProps) {
    const { conversation, waited, onSkip } = props;

    return (
        <Card className={`${workspacePanelClass} gap-4 ${accent(waited)}`}>
            <CardHeader>
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-base leading-snug break-words">
                            {conversation.handle ?? conversation.author}
                        </CardTitle>
                        <CardDescription className="mt-1 break-words">
                            {conversation.text}
                        </CardDescription>
                    </div>
                    <Waiting seconds={waited} />
                </div>
                <ThreadLink conversation={conversation} />
            </CardHeader>

            <CardContent className="flex flex-col gap-3 border-t pt-4">
                <RowNotes {...props} />
                <Composer {...props} scope="card" />
                <Button
                    type="button"
                    variant="ghost"
                    className="rounded-full text-muted-foreground"
                    onClick={onSkip}
                >
                    Skip with a reason
                </Button>
            </CardContent>
        </Card>
    );
}

function DesktopRow(props: EditorProps) {
    const { conversation, waited, onSkip } = props;

    return (
        <TableRow className={accent(waited)}>
            <TableCell className="align-top">
                <Waiting seconds={waited} />
            </TableCell>

            <TableCell className="max-w-[28rem] align-top">
                <p className="font-medium break-words">
                    {conversation.handle ?? conversation.author}
                </p>
                <p className="mt-1 text-sm break-words text-muted-foreground">
                    {conversation.text}
                </p>
                <ThreadLink conversation={conversation} />
            </TableCell>

            <TableCell className="align-top">
                <div className="flex flex-col gap-3">
                    <RowNotes {...props} />
                    <Composer {...props} scope="row" />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="self-start rounded-full text-muted-foreground"
                        onClick={onSkip}
                    >
                        Skip with a reason
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * The reply box and whatever the one tap is for this conversation.
 *
 * One `<Form>` and one textarea, with the action chosen by the mode rather than
 * a second form: the text the operator is looking at has to be the text that is
 * submitted, whichever way it leaves the building.
 *
 * Send is never `disabled`. A disabled button is unfocusable, its `title` is
 * never announced and renders nothing at all on a touch screen, so the reason
 * the operator most needs is the one they cannot reach — on the screen §7
 * designs for a phone. It carries `aria-disabled` and a visible, associated
 * reason instead, and the server refusal is the enforcement it always was.
 */
function Composer({
    conversation,
    text,
    mode,
    limit,
    acknowledged,
    onText,
    onMode,
    onAcknowledge,
    onSent,
    scope,
}: EditorProps & { scope: string }) {
    const [copied, copy] = useClipboard();
    const [copyFailed, setCopyFailed] = useState(false);
    const field = `reply-${scope}-${conversation.id}`;
    const reasonId = `${field}-reason`;
    const byHand = mode === 'hand';
    const untouched = text.trim() === (conversation.draft ?? '').trim();

    // What still stands between this text and the thread. Findings about the
    // words themselves stop applying once the operator has rewritten them —
    // the guard runs again on the send over whatever was actually typed — and
    // the two that are not about the words survive any edit, which is exactly
    // why they are cleared with a tick instead.
    const outstanding = byHand
        ? []
        : conversation.findings.filter(
              (finding) =>
                  finding.blocking &&
                  !acknowledged.includes(finding.code) &&
                  (finding.acknowledgeable || untouched),
          );

    const blocked = outstanding.length > 0 || text.trim() === '';

    const onCopy = async () => {
        const ok = await copy(text);

        setCopyFailed(!ok);
    };

    return (
        <Form
            action={
                byHand ? sent(conversation.id).url : send(conversation.id).url
            }
            method="post"
            options={{ preserveScroll: true }}
            onSuccess={onSent}
        >
            {({ processing, errors }) => (
                <div className="flex flex-col gap-2">
                    <Label htmlFor={field} className="sr-only">
                        Reply to {conversation.handle ?? conversation.author}
                    </Label>
                    <Textarea
                        id={field}
                        name="text"
                        rows={3}
                        maxLength={limit}
                        value={text}
                        onChange={(event) => onText(event.target.value)}
                        placeholder={
                            conversation.draft === null
                                ? 'No draft yet — write the reply yourself.'
                                : undefined
                        }
                    />

                    <Acknowledgements
                        findings={conversation.findings}
                        acknowledged={acknowledged}
                        scope={field}
                        onAcknowledge={onAcknowledge}
                    />

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {text.length}/{limit}
                        </span>
                        {conversation.route === 'api' && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                /*
                                 * Full touch height on a phone, compact on a
                                 * pointer. This control switches what the form
                                 * does — engine sends, or the operator did —
                                 * and §7 puts this screen on a phone, so a
                                 * 36px target for a destination-changing
                                 * action is the wrong trade.
                                 */
                                className="min-h-11 rounded-full text-muted-foreground sm:min-h-9"
                                onClick={() => onMode(byHand ? 'api' : 'hand')}
                            >
                                <PenLine className="size-4" aria-hidden />
                                {byHand
                                    ? 'Let Avyo send it'
                                    : 'I posted it myself'}
                            </Button>
                        )}
                    </div>

                    {byHand && (
                        <Input
                            name="reference"
                            type="url"
                            inputMode="url"
                            placeholder="Link to your reply (optional)"
                            aria-label="Link to the reply you posted"
                        />
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        {byHand && (
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-full"
                                onClick={() => void onCopy()}
                            >
                                {copied === text && text !== '' ? (
                                    <Check className="size-4" aria-hidden />
                                ) : (
                                    <Clipboard className="size-4" aria-hidden />
                                )}
                                {copied === text && text !== ''
                                    ? 'Copied'
                                    : 'Copy'}
                            </Button>
                        )}

                        <Button
                            type="submit"
                            className="flex-1 rounded-full sm:flex-none"
                            disabled={processing}
                            // Not `disabled`: the reason has to be reachable.
                            // A blocked draft stays visible and stays
                            // unsendable — until the operator rewrites it or
                            // acknowledges the check, at which point the guard
                            // judges it again on the send.
                            aria-disabled={blocked || undefined}
                            aria-describedby={blocked ? reasonId : undefined}
                        >
                            <Send className="size-4" aria-hidden />
                            {byHand ? 'Mark as sent' : 'Send'}
                        </Button>
                    </div>

                    {blocked && (
                        <p
                            id={reasonId}
                            className="text-xs text-muted-foreground"
                        >
                            {text.trim() === ''
                                ? 'Write the reply first, or skip the conversation.'
                                : outstanding[0]?.acknowledgeable
                                  ? 'Confirm the check above before this can go out.'
                                  : 'Edit the reply to clear the block above, or skip the conversation.'}
                        </p>
                    )}

                    <p aria-live="polite" className="sr-only">
                        {copied === text && text !== ''
                            ? 'Reply copied to the clipboard.'
                            : ''}
                    </p>

                    {copyFailed && (
                        <p className="text-xs text-destructive">
                            Copying failed — this browser did not allow it.
                            Select the reply above and copy it by hand.
                        </p>
                    )}

                    {errors.reply && (
                        <p className="text-xs text-destructive">
                            {errors.reply}
                        </p>
                    )}
                    {errors.text && (
                        <p className="text-xs text-destructive">
                            {errors.text}
                        </p>
                    )}
                </div>
            )}
        </Form>
    );
}

/**
 * The checks only a person can clear (§9, §10).
 *
 * A hidden input rather than the checkbox's own form value, so what is
 * submitted is exactly what is ticked and nothing depends on how the widget
 * serialises itself.
 */
function Acknowledgements({
    findings,
    acknowledged,
    scope,
    onAcknowledge,
}: {
    findings: Finding[];
    acknowledged: string[];
    scope: string;
    onAcknowledge: (code: string, on: boolean) => void;
}) {
    const asks = findings.filter(
        (finding) => finding.blocking && finding.acknowledgeable,
    );

    if (asks.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3">
            {asks.map((finding) => {
                const id = `${scope}-ack-${finding.code}`;
                const on = acknowledged.includes(finding.code);

                return (
                    <div key={finding.code} className="flex items-start gap-3">
                        <Checkbox
                            id={id}
                            checked={on}
                            className="mt-0.5 size-5"
                            onCheckedChange={(next) =>
                                onAcknowledge(finding.code, next === true)
                            }
                        />
                        {on && (
                            <input
                                type="hidden"
                                name="acknowledged[]"
                                value={finding.code}
                            />
                        )}
                        <Label
                            htmlFor={id}
                            className="text-xs leading-relaxed font-normal break-words text-muted-foreground"
                        >
                            I checked this. {finding.detail}
                        </Label>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * How long this person has been waiting.
 *
 * The loudest thing on the row on purpose. The colour is the first hour of
 * §4.2: the algorithm weighs the speed of replies inside it, so an hour is the
 * point at which the answer stops being worth much.
 */
function Waiting({ seconds }: { seconds: number }) {
    const tone =
        seconds < 900
            ? 'text-emerald-600 dark:text-emerald-400'
            : seconds < 3600
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-destructive';

    return (
        <div className="shrink-0 text-right">
            <p
                className={`text-2xl leading-none font-semibold tracking-[-0.03em] tabular-nums ${tone}`}
            >
                {waited(seconds)}
            </p>
            <p className="mt-1 text-[0.7rem] tracking-wide text-muted-foreground uppercase">
                waiting
            </p>
        </div>
    );
}

/**
 * Everything the engine has to say about this row before the operator types.
 *
 * §7: "чего движок делать не стал и почему". Three silences used to be
 * unexplained here. A conversation still in `new` — the drafting run failed, or
 * has not landed — showed an empty box and nothing else, which is exactly the
 * machine §7 calls indistinguishable from a broken one. A conversation the
 * engine may not answer explained itself only in an aggregate line at the top
 * of the page, while the row carried a button label. And a guard finding was
 * printed without saying whether one tap or an edit clears it.
 */
function RowNotes({
    conversation,
    foreignRepliesAllowed,
}: {
    conversation: Conversation;
    foreignRepliesAllowed: boolean;
}) {
    const undrafted =
        conversation.draft === null || conversation.draft.trim() === '';

    return (
        <div className="flex flex-col gap-2">
            {undrafted && (
                <p className="text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">
                        No draft.
                    </span>{' '}
                    {conversation.state === 'new'
                        ? 'Avyo has not prepared a draft yet. Write the reply yourself, or skip it with a reason.'
                        : 'The draft was thrown away. Write the reply yourself, or skip it with a reason.'}
                </p>
            )}

            {conversation.route === 'by_hand' && (
                <p className="text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {conversation.route_label}.
                    </span>{' '}
                    {foreignRepliesAllowed
                        ? 'This thread is not ours to answer through the API. Copy the reply, post it in the thread, then mark it as sent.'
                        : 'Threads does not document automatic replies to posts you did not publish. Copy Avyo’s draft, post it in the thread, then mark it as sent.'}
                </p>
            )}

            <Findings conversation={conversation} />
        </div>
    );
}

/**
 * Everything the guard has to say, printed rather than acted on.
 *
 * §7: "чего движок делать не стал и почему". A row that vanished because its
 * draft was too long is a row the operator cannot fix.
 *
 * Keyed by position and not by code: the guard emits one finding per forbidden
 * topic and they all carry `forbidden_topic`, so two topics used to be two
 * children with one key.
 */
function Findings({ conversation }: { conversation: Conversation }) {
    if (conversation.findings.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-1.5">
            {conversation.findings.map((finding, position) => (
                <li
                    key={`${finding.code}-${position}`}
                    className="flex items-start gap-2"
                >
                    <Badge
                        variant={finding.blocking ? 'destructive' : 'secondary'}
                        className="mt-0.5 shrink-0"
                    >
                        <AlertTriangle className="size-3" aria-hidden />
                        {finding.blocking ? 'Blocked' : 'Check'}
                    </Badge>
                    <span className="text-xs break-words text-muted-foreground">
                        {finding.detail}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/**
 * The conversation itself, one tap away — §11.1's path needs it to work.
 *
 * A real target rather than an 18px inline link: on the by-hand route, opening
 * the thread is a required step of answering, on a phone.
 */
function ThreadLink({ conversation }: { conversation: Conversation }) {
    if (conversation.permalink === null) {
        return null;
    }

    return (
        <Button
            asChild
            variant="outline"
            size="sm"
            /* A required step of the by-hand flow, so it gets a real target. */
            className="mt-2 min-h-11 w-fit rounded-full sm:min-h-9"
        >
            <a
                href={conversation.permalink}
                target="_blank"
                rel="noreferrer noopener"
            >
                <ExternalLink className="size-4" aria-hidden />
                Open the thread
            </a>
        </Button>
    );
}

function QueueSummary({
    rows,
    now,
    total,
    answeredToday,
    foreignRepliesAllowed,
}: {
    rows: Conversation[];
    now: number;
    total: number;
    answeredToday: number;
    foreignRepliesAllowed: boolean;
}) {
    const longest = Math.max(...rows.map((row) => waitedSeconds(row, now)));
    const byHand = rows.filter((row) => row.route === 'by_hand').length;

    return (
        <section
            className={`${workspacePanelClass} flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6`}
            aria-label="Conversation queue overview"
        >
            <div className="flex items-center gap-3">
                <span className="flex size-10 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                    <MessagesSquare className="size-4" aria-hidden />
                </span>
                <div>
                    <p className="font-semibold tracking-tight">
                        {total} waiting
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Longest wait {waited(longest)} · {answeredToday}{' '}
                        answered today
                    </p>
                </div>
            </div>

            {byHand > 0 && (
                <p className="max-w-md text-xs text-muted-foreground">
                    <Badge variant="secondary" className="mr-2 rounded-full">
                        {byHand} by hand
                    </Badge>
                    {foreignRepliesAllowed
                        ? 'These threads are not ours to answer through the API.'
                        : 'Threads does not document automatic replies to posts you did not publish, so Avyo drafts the response and you post it manually.'}
                </p>
            )}
        </section>
    );
}

/**
 * A reason is required, and it is one of a closed set.
 *
 * §7 makes the explanation mandatory, and the enum makes it countable: "not
 * about us" forty times in a week is a fact about the listening contour's
 * filtering, where forty sentences are forty sentences.
 */
function SkipDialog({
    conversation,
    reasons,
    onClose,
}: {
    conversation: Conversation | null;
    reasons: Reason[];
    onClose: () => void;
}) {
    return (
        <Dialog
            open={conversation !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="rounded-[1.5rem]">
                {conversation !== null && (
                    <Form
                        action={skip(conversation.id).url}
                        method="post"
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        Leave this one alone
                                    </DialogTitle>
                                    <DialogDescription className="break-words">
                                        {conversation.handle ??
                                            conversation.author}
                                        : {conversation.text}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="grid gap-2 py-2">
                                    <Label htmlFor="reason">Why</Label>
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
                                        A conversation that disappears with no
                                        reason is indistinguishable from one
                                        that was lost.
                                    </p>
                                    {errors.reason && (
                                        <p className="text-xs text-destructive">
                                            {errors.reason}
                                        </p>
                                    )}
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
                                        Skip
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

function EmptyQueue({ answeredToday }: { answeredToday: number }) {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <MessagesSquare
                    className="size-8 text-muted-foreground"
                    aria-hidden
                />
                <CardTitle>Nobody is waiting</CardTitle>
                <CardDescription>
                    {answeredToday > 0
                        ? `${answeredToday} answered today. New replies and mentions appear here within seconds of arriving.`
                        : 'New replies and mentions appear here within seconds of arriving.'}
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

/** Where an unsent edit waits while the operator is in the Threads app. */
const EDITS_KEY = 'engage.unsent-edits.v1';

/** A week. Long enough for a queue somebody left, short enough to forget. */
const EDITS_TTL = 7 * 86_400_000;

type KeptEdit = { text: string; savedAt: number };

/**
 * Edits kept on the device, keyed by conversation.
 *
 * There is deliberately no endpoint that saves a draft, and that invariant is
 * right: `interactions.draft_reply` means *what the engine wrote*, and a column
 * that sometimes holds the operator's words instead makes every stored guard
 * finding ambiguous about which sentence it judged.
 *
 * The conclusion drawn from it was wrong, though. The loss landed on the
 * default §11.1 flow, which *requires* leaving the page — edit, open the
 * thread, post, come back — and the box had reverted to the engine's draft, on
 * the one path where the operator's own words are the whole point.
 *
 * `localStorage` over a second nullable column because the invariant is about
 * the server and this never reaches it: no migration, no endpoint, no second
 * thing the fact-check has to be told to ignore, and nothing new that could
 * ever be mistaken for what the engine wrote. What it costs is that the edit
 * does not follow the operator to another device — which is the wrong thing to
 * optimise for on a screen whose whole premise is one phone and five minutes,
 * and which a server-side column would have paid for with a column two
 * different meanings could leak into.
 */
function useKeptEdits(): [
    Record<string, string>,
    (id: string, text: string) => void,
    (id: string) => void,
] {
    const [edits, setEdits] = useState<Record<string, string>>({});
    const loaded = useRef(false);

    useEffect(() => {
        if (loaded.current) {
            return;
        }

        loaded.current = true;

        const kept = read();
        const fresh: Record<string, string> = {};

        for (const [id, entry] of Object.entries(kept)) {
            if (Date.now() - entry.savedAt < EDITS_TTL) {
                fresh[id] = entry.text;
            }
        }

        setEdits(fresh);
    }, []);

    const setEdit = useCallback((id: string, text: string) => {
        setEdits((current) => ({ ...current, [id]: text }));
        write((kept) => ({ ...kept, [id]: { text, savedAt: Date.now() } }));
    }, []);

    const forgetEdit = useCallback((id: string) => {
        setEdits((current) => {
            const next = { ...current };

            delete next[id];

            return next;
        });
        write((kept) => {
            const next = { ...kept };

            delete next[id];

            return next;
        });
    }, []);

    return [edits, setEdit, forgetEdit];
}

function read(): Record<string, KeptEdit> {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const raw = window.localStorage.getItem(EDITS_KEY);

        return raw === null
            ? {}
            : (JSON.parse(raw) as Record<string, KeptEdit>);
    } catch {
        // A quota error, private mode, or somebody else's JSON under our key.
        // Losing the edit is the old behaviour; losing the screen is not.
        return {};
    }
}

function write(
    change: (kept: Record<string, KeptEdit>) => Record<string, KeptEdit>,
): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(EDITS_KEY, JSON.stringify(change(read())));
    } catch {
        // See read(). The in-memory copy still works for this visit.
    }
}

/**
 * A clock, ticking.
 *
 * The wait used to be a static integer rendered once, on the screen whose whole
 * thesis is the first hour: a phone left open for twenty minutes still showed
 * "3m" in green, which is the one number on this page that must not be wrong.
 * `received_at` was already in the payload and unused, so the wait is derived
 * from it every second instead.
 *
 * `waited_seconds` stays in the payload and stays authoritative for anything
 * the server says about a wait — the toast after a send is measured against the
 * clock every other latency in this engine is — and is the fallback here for a
 * timestamp this browser cannot parse.
 */
function useTickingClock(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const tick = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(tick);
    }, []);

    return now;
}

function waitedSeconds(conversation: Conversation, now: number): number {
    const received = Date.parse(conversation.received_at);

    if (Number.isNaN(received)) {
        return conversation.waited_seconds;
    }

    return Math.max(0, Math.floor((now - received) / 1000));
}

/** The left edge of the row: how close this is to being too late. */
function accent(seconds: number): string {
    if (seconds >= 3600) {
        return 'border-l-2 border-l-destructive/70';
    }

    return seconds >= 900
        ? 'border-l-2 border-l-amber-500/70'
        : 'border-l-2 border-l-emerald-500/70';
}

/**
 * The wait in the words a person would use.
 *
 * The thresholds match `InteractionController::waited()` to the second. They
 * did not — 60s here against 90s there — so a conversation answered after
 * seventy seconds was "1m" on the row and "70s" in the toast about it.
 */
function waited(seconds: number): string {
    if (seconds < 60) {
        return `${Math.max(0, seconds)}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h`;
    }

    return `${Math.floor(seconds / 86400)}d`;
}

Engage.layout = {
    breadcrumbs: [{ title: 'Conversations', href: index() }],
};
