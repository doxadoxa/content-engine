import { Checkbox, Input, Label, Textarea } from 'avyo';

export function WithControls() {
    return (
        <div className="grid max-w-sm gap-5">
            <div className="grid gap-2">
                <Label htmlFor="l-input">Project name</Label>
                <Input id="l-input" defaultValue="Avyo Blog" />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="l-textarea">Brief</Label>
                <Textarea
                    id="l-textarea"
                    rows={3}
                    defaultValue="Practical, specific posts for people running content operations."
                />
            </div>
            <div className="flex items-center gap-3">
                <Checkbox id="l-checkbox" defaultChecked />
                <Label htmlFor="l-checkbox">Publish automatically</Label>
            </div>
        </div>
    );
}

/**
 * Label carries `peer-disabled` and `group-data-[disabled]` rules, so it dims
 * itself when the control it points at is disabled.
 */
export function Disabled() {
    return (
        <div className="grid max-w-sm gap-2">
            <Label htmlFor="l-off">Feed URL</Label>
            <Input
                id="l-off"
                className="peer"
                defaultValue="https://avyo.io/feed.xml"
                disabled
            />
            <Label htmlFor="l-off" className="peer-disabled:opacity-50">
                Locked while the feed is syncing
            </Label>
        </div>
    );
}
