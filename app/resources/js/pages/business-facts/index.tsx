import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index, store, update } from '@/routes/business-facts';
import { index as pagesIndex } from '@/routes/pages';

type Version = {
    id: string;
    version: number;
    statement: string;
    source_url: string | null;
    source_note: string;
    status: string;
    usable: boolean;
    confirmed_at: string | null;
    confirmed_by: number | null;
    confirmed_by_name: string | null;
    review_due_at: string | null;
};
type Fact = {
    id: string;
    name: string;
    current_version_id: string | null;
    current: Version | null;
    versions: Version[];
};
export default function FactsIndex({ facts }: { facts: Fact[] }) {
    const owner = usePage().props.auth.project?.role === 'owner';
    const [editing, setEditing] = useState<Fact | 'new' | null>(null);

    return (
        <>
            <Head title="Business facts" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Content evidence"
                    title="Business facts"
                    description="Record the services, prices, coverage, and promises you can stand behind. Every change keeps its source and confirmation history."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href="/fact-maintenance">
                                    Check page statements
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={pagesIndex()}>Website pages</Link>
                            </Button>
                            {owner && (
                                <Button onClick={() => setEditing('new')}>
                                    Record a fact
                                </Button>
                            )}
                        </>
                    }
                />
                <p className="text-sm leading-6 text-muted-foreground">
                    Only an owner can confirm a fact. Draft, retracted, and
                    overdue facts are excluded from usable evidence. Editing a
                    confirmed statement requires a new confirmation; earlier
                    versions remain intact.
                </p>
                {facts.length === 0 ? (
                    <section
                        className={`${workspacePanelClass} p-8 text-center`}
                    >
                        <h2 className="font-semibold">
                            No confirmed business knowledge yet
                        </h2>
                        <p className="mt-3 text-sm leading-6 text-muted-foreground">
                            Start with a concrete fact such as your service
                            area, a current guide price, or what is included in
                            a service. Add the source and a review date.
                        </p>
                    </section>
                ) : (
                    <div className="grid gap-4">
                        {facts.map((fact) => (
                            <section
                                key={fact.id}
                                className={`${workspacePanelClass} p-5 sm:p-6`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold">
                                            {fact.name}
                                        </h2>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <Badge
                                                variant={
                                                    fact.current?.usable
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {fact.current?.usable
                                                    ? 'Confirmed · current'
                                                    : fact.current?.status ===
                                                        'confirmed'
                                                      ? 'Needs owner review'
                                                      : (fact.current?.status ??
                                                        'No version')}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                Version{' '}
                                                {fact.current?.version ?? '—'}
                                                {fact.current?.review_due_at &&
                                                    ` · Review by ${fact.current.review_due_at}`}
                                            </span>
                                        </div>
                                    </div>
                                    {owner && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(fact)}
                                        >
                                            Review or revise
                                        </Button>
                                    )}
                                </div>
                                {fact.current && (
                                    <FactEvidence version={fact.current} />
                                )}
                                <details className="mt-5 border-t pt-4">
                                    <summary className="cursor-pointer text-xs font-medium">
                                        Version history · {fact.versions.length}
                                    </summary>
                                    <div className="mt-3 space-y-4">
                                        {fact.versions.map((version) => (
                                            <div
                                                key={version.id}
                                                className="rounded-xl border p-4"
                                            >
                                                <p className="text-xs font-medium">
                                                    Version {version.version} ·{' '}
                                                    {version.status}
                                                </p>
                                                <FactEvidence
                                                    version={version}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            </section>
                        ))}
                    </div>
                )}
            </WorkspacePage>
            {editing !== null && (
                <FactEditor
                    fact={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
function FactEvidence({ version }: { version: Version }) {
    return (
        <>
            <p className="mt-4 text-sm leading-7 whitespace-pre-wrap">
                {version.statement}
            </p>
            <p className="mt-3 text-xs leading-6 whitespace-pre-wrap text-muted-foreground">
                Source: {version.source_note}
            </p>
            {version.source_url && (
                <a
                    href={version.source_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="mt-1 block text-xs break-all underline underline-offset-4"
                >
                    {version.source_url}
                </a>
            )}
            {version.confirmed_at && (
                <p className="mt-2 text-xs text-muted-foreground">
                    Confirmed by{' '}
                    {version.confirmed_by_name ?? 'the project owner'} on{' '}
                    {new Date(version.confirmed_at).toLocaleDateString()}
                </p>
            )}
        </>
    );
}
function FactEditor({
    fact,
    onClose,
}: {
    fact: Fact | null;
    onClose: () => void;
}) {
    const [status, setStatus] = useState('draft');
    const [reviewDate] = useState(() =>
        new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
    );
    const current = fact?.current;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {fact
                            ? `Review ${fact.name}`
                            : 'Record a business fact'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    action={fact ? update(fact.id).url : store().url}
                    method={fact ? 'patch' : 'post'}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {fact && (
                                <input
                                    type="hidden"
                                    name="expected_version_id"
                                    value={fact.current_version_id ?? ''}
                                />
                            )}
                            <InputError message={errors.expected_version_id} />
                            <div className="grid gap-2">
                                <Label htmlFor="fact-name">Fact name</Label>
                                <Input
                                    id="fact-name"
                                    name="name"
                                    required
                                    maxLength={200}
                                    defaultValue={fact?.name ?? ''}
                                    placeholder="Deep cleaning guide price"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fact-statement">
                                    Exact factual statement
                                </Label>
                                <Textarea
                                    id="fact-statement"
                                    name="statement"
                                    required
                                    maxLength={4000}
                                    rows={3}
                                    defaultValue={current?.statement ?? ''}
                                />
                                <InputError message={errors.statement} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fact-source">
                                    Source URL (optional)
                                </Label>
                                <Input
                                    id="fact-source"
                                    name="source_url"
                                    type="url"
                                    maxLength={2000}
                                    defaultValue={current?.source_url ?? ''}
                                />
                                <InputError message={errors.source_url} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fact-note">
                                    Source and basis for confirmation
                                </Label>
                                <Textarea
                                    id="fact-note"
                                    name="source_note"
                                    required
                                    maxLength={2000}
                                    rows={2}
                                    defaultValue={current?.source_note ?? ''}
                                    placeholder="Current approved service catalogue, reviewed with the owner on…"
                                />
                                <InputError message={errors.source_note} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fact-status">Save as</Label>
                                <select
                                    id="fact-status"
                                    name="status"
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(event.target.value)
                                    }
                                    className="h-10 rounded-xl border bg-background px-3 text-sm"
                                >
                                    <option value="draft">
                                        Draft · not evidence yet
                                    </option>
                                    <option value="confirmed">
                                        Owner-confirmed fact
                                    </option>
                                    {fact && (
                                        <option value="retracted">
                                            Retracted · no longer valid
                                        </option>
                                    )}
                                </select>
                                <InputError message={errors.status} />
                            </div>
                            {status === 'confirmed' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="fact-review">
                                            Review again by
                                        </Label>
                                        <Input
                                            id="fact-review"
                                            name="review_due_at"
                                            type="date"
                                            required
                                            defaultValue={reviewDate}
                                        />
                                        <InputError
                                            message={errors.review_due_at}
                                        />
                                    </div>
                                    <label className="flex items-start gap-3 rounded-xl border p-3 text-sm leading-6">
                                        <input
                                            name="confirm"
                                            type="checkbox"
                                            value="1"
                                            required
                                            className="mt-1.5"
                                        />
                                        <span>
                                            I have checked this statement
                                            against the source and confirm it is
                                            accurate for this business.
                                        </span>
                                    </label>
                                    <InputError message={errors.confirm} />
                                </>
                            )}
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Save {fact ? 'new version' : 'fact'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
FactsIndex.layout = {
    breadcrumbs: [{ title: 'Business facts', href: index() }],
};
