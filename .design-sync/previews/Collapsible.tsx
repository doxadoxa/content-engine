import { ChevronsUpDown } from 'lucide-react';
import {
    Button,
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from 'avyo';

export function Open() {
    return (
        <Collapsible defaultOpen className="grid w-full max-w-sm gap-2">
            <div className="flex items-center justify-between gap-4">
                <h4 className="text-sm font-semibold">
                    3 locales queued for Monday
                </h4>
                <CollapsibleTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label="Toggle">
                        <ChevronsUpDown />
                    </Button>
                </CollapsibleTrigger>
            </div>
            <CollapsibleContent className="grid gap-2">
                {['English — 6 drafts', 'Português — 4 drafts', 'Українська — 2 drafts'].map(
                    (row) => (
                        <div
                            key={row}
                            className="rounded-md border px-4 py-2 font-mono text-sm"
                        >
                            {row}
                        </div>
                    ),
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

export function Closed() {
    return (
        <Collapsible className="grid w-full max-w-sm gap-2">
            <div className="flex items-center justify-between gap-4">
                <h4 className="text-sm font-semibold">
                    3 locales queued for Monday
                </h4>
                <CollapsibleTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label="Toggle">
                        <ChevronsUpDown />
                    </Button>
                </CollapsibleTrigger>
            </div>
            <CollapsibleContent className="grid gap-2">
                <div className="rounded-md border px-4 py-2 font-mono text-sm">
                    Hidden until opened
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
