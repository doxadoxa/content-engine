import { Form, Link, usePage } from '@inertiajs/react';
import { CalendarPlus, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

export type ArticleWorkflow = {
    mode: 'automatic' | 'review_first';
    opted_in: boolean;
    ready: boolean;
    timezone: string;
    message: string;
    action: string;
    action_label: string;
};

/** The same two real article actions wherever a manager starts work. */
export function ContentActions({
    month,
    planning = false,
    primary = 'auto',
}: {
    month?: string;
    planning?: boolean;
    /** Let a page keep its main action in step with the publishing workflow. */
    primary?: 'auto' | 'plan' | 'create' | 'setup' | 'none';
}) {
    const { billing, article_workflow: workflow } = usePage<{
        article_workflow?: ArticleWorkflow;
    }>().props;
    const [open, setOpen] = useState(false);
    const allowed = billing?.may_generate === true;
    const articlesAvailable = billing?.usage.articles?.remaining !== 0;
    const plansAvailable = billing?.usage.content_plans?.remaining !== 0;
    const past =
        month !== undefined &&
        month.slice(0, 7) < new Date().toLocaleDateString('en-CA').slice(0, 7);
    const primaryAction =
        primary === 'auto'
            ? workflow && !workflow.ready
                ? 'setup'
                : workflow?.ready
                  ? 'plan'
                  : 'create'
            : primary;
    const showSetup =
        primaryAction === 'setup' && workflow !== undefined && !workflow.ready;

    return (
        <div className="flex flex-wrap items-center gap-2">
            {showSetup && (
                <Button asChild>
                    <Link href={workflow.action}>{workflow.action_label}</Link>
                </Button>
            )}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button
                        variant={
                            primaryAction === 'create' ? 'default' : 'outline'
                        }
                        disabled={!allowed || !articlesAvailable}
                    >
                        <Plus className="size-4" aria-hidden="true" /> Create
                        content
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            What would help your customers?
                        </DialogTitle>
                        <DialogDescription>
                            Give Avyo a topic or a question customers ask. It
                            will research and write an article using your
                            business brief.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        action="/content/articles"
                        method="post"
                        onSuccess={() => setOpen(false)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor="article-topic">
                                    Topic or customer question
                                </Label>
                                <Textarea
                                    id="article-topic"
                                    name="prompt"
                                    minLength={3}
                                    maxLength={255}
                                    required
                                    rows={4}
                                    placeholder="How should a family prepare for a deep clean?"
                                    autoFocus
                                />
                                <InputError message={errors.prompt} />
                                <InputError message={errors.generation} />
                                <p className="text-sm leading-6 text-muted-foreground">
                                    {workflow?.opted_in
                                        ? `Your article will be scheduled for tomorrow at 09:00 (${workflow.timezone}), ${workflow.mode === 'automatic' ? 'with automatic publishing after checks' : 'with your approval required first'}. ${workflow.ready ? '' : 'Website publishing setup must be ready before it can go live. '}You can change its date or pause publication in Calendar.`
                                        : 'Your article will appear in Content for review. Choose a publication date and website when you are ready.'}
                                </p>
                                <Button type="submit" disabled={processing}>
                                    {processing && (
                                        <Spinner className="size-4" />
                                    )}{' '}
                                    Write this article
                                </Button>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
            <Form
                action="/content/plan"
                method="post"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <div className="space-y-1">
                        {month && (
                            <input type="hidden" name="month" value={month} />
                        )}
                        <Button
                            variant={
                                primaryAction === 'plan' ? 'default' : 'outline'
                            }
                            disabled={
                                !allowed ||
                                !articlesAvailable ||
                                !plansAvailable ||
                                planning ||
                                past ||
                                processing
                            }
                        >
                            {planning || processing ? (
                                <Spinner className="size-4" />
                            ) : (
                                <CalendarPlus
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            )}
                            {planning
                                ? 'Preparing your plan…'
                                : 'Plan my content'}
                        </Button>
                        <InputError message={errors.month ?? errors.planning} />
                        {past && (
                            <p className="text-xs text-muted-foreground">
                                Choose this month or a future month to plan new
                                content.
                            </p>
                        )}
                    </div>
                )}
            </Form>
            {(!allowed || !articlesAvailable || !plansAvailable) && (
                <Link
                    href="/billing"
                    className="text-xs text-muted-foreground underline underline-offset-4"
                >
                    {!allowed
                        ? 'Activate content creation'
                        : 'View available allowance'}
                </Link>
            )}
        </div>
    );
}
