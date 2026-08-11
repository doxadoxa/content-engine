import { ArrowUpRight, Check, Plus, Trash2 } from 'lucide-react';
import { Button, Spinner } from 'avyo';

export function Variants() {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <Button>Publish draft</Button>
            <Button variant="secondary">Save for later</Button>
            <Button variant="outline">Preview</Button>
            <Button variant="ghost">Discard</Button>
            <Button variant="destructive">Delete article</Button>
            <Button variant="link">View changelog</Button>
        </div>
    );
}

export function Sizes() {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <Button size="sm">Small</Button>
            <Button size="default">Default</Button>
            <Button size="lg">Large</Button>
            <Button size="icon" aria-label="Add project">
                <Plus />
            </Button>
        </div>
    );
}

export function WithIcons() {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <Button>
                <Plus />
                New project
            </Button>
            <Button variant="outline">
                Open in Ahrefs
                <ArrowUpRight />
            </Button>
            <Button variant="secondary">
                <Check />
                Approve
            </Button>
            <Button variant="destructive">
                <Trash2 />
                Remove
            </Button>
        </div>
    );
}

export function States() {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <Button>Enabled</Button>
            <Button disabled>Disabled</Button>
            <Button disabled>
                <Spinner />
                Publishing…
            </Button>
            <Button variant="outline" disabled>
                Unavailable
            </Button>
        </div>
    );
}
