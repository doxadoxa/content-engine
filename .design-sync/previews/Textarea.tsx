import { Label, Textarea } from 'avyo';

export function WithLabel() {
    return (
        <div className="grid max-w-md gap-2">
            <Label htmlFor="t-note">Note for the writer</Label>
            <Textarea
                id="t-note"
                rows={4}
                defaultValue="Second half repeats the intro almost verbatim — cut it and go deeper on the pricing comparison instead."
            />
        </div>
    );
}

export function States() {
    return (
        <div className="grid max-w-md gap-4">
            <Textarea placeholder="Say what is wrong with it…" rows={3} />
            <Textarea
                rows={3}
                defaultValue="Locked while the article is being published."
                disabled
            />
            <div className="grid gap-2">
                <Textarea rows={3} defaultValue="" aria-invalid />
                <p className="text-sm text-destructive">
                    A note is required when sending a draft back.
                </p>
            </div>
        </div>
    );
}
