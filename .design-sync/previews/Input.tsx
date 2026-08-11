import { Input, Label } from 'avyo';

export function WithLabel() {
    return (
        <div className="grid max-w-sm gap-2">
            <Label htmlFor="project-name">Project name</Label>
            <Input id="project-name" defaultValue="Avyo Blog" />
        </div>
    );
}

export function Types() {
    return (
        <div className="grid max-w-sm gap-4">
            <div className="grid gap-2">
                <Label htmlFor="i-text">Slug</Label>
                <Input id="i-text" placeholder="avyo-blog" />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="i-url">Feed URL</Label>
                <Input
                    id="i-url"
                    type="url"
                    defaultValue="https://avyo.io/feed.xml"
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="i-number">Posts per week</Label>
                <Input id="i-number" type="number" defaultValue={12} />
            </div>
        </div>
    );
}

export function States() {
    return (
        <div className="grid max-w-sm gap-4">
            <Input placeholder="Empty" />
            <Input defaultValue="Filled" />
            <Input defaultValue="Disabled" disabled />
            <div className="grid gap-2">
                <Input defaultValue="not-a-url" aria-invalid />
                <p className="text-sm text-destructive">
                    Enter a full URL, including https://
                </p>
            </div>
        </div>
    );
}
