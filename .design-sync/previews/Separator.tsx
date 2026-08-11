import { Separator } from 'avyo';

export function Horizontal() {
    return (
        <div className="max-w-sm">
            <div className="space-y-1">
                <h4 className="text-sm leading-none font-medium">
                    Avyo Content
                </h4>
                <p className="text-sm text-muted-foreground">
                    One editorial pipeline, four locales.
                </p>
            </div>
            <Separator className="my-4" />
            <div className="flex h-5 items-center gap-4 text-sm">
                <span>Drafts</span>
                <Separator orientation="vertical" />
                <span>Approvals</span>
                <Separator orientation="vertical" />
                <span>Published</span>
            </div>
        </div>
    );
}

export function Vertical() {
    return (
        <div className="flex h-24 items-center gap-6">
            <div className="text-center">
                <p className="text-2xl font-semibold">128</p>
                <p className="text-xs text-muted-foreground">Published</p>
            </div>
            <Separator orientation="vertical" />
            <div className="text-center">
                <p className="text-2xl font-semibold">7</p>
                <p className="text-xs text-muted-foreground">In review</p>
            </div>
            <Separator orientation="vertical" />
            <div className="text-center">
                <p className="text-2xl font-semibold">4</p>
                <p className="text-xs text-muted-foreground">Locales</p>
            </div>
        </div>
    );
}
