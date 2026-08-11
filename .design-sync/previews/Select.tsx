import {
    Label,
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from 'avyo';

/**
 * Closed is the state a form actually spends its life in, and SelectContent
 * portals out of the card when open — so these cells show the trigger. Sizes
 * and the disabled state are the parts a layout has to plan around.
 */
export function WithLabel() {
    return (
        <div className="grid max-w-xs gap-2">
            <Label htmlFor="s-reason">What is wrong with it</Label>
            <Select defaultValue="off-brief">
                <SelectTrigger id="s-reason" className="w-full">
                    <SelectValue placeholder="Pick a reason" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectLabel>Content</SelectLabel>
                        <SelectItem value="off-brief">
                            Does not answer the brief
                        </SelectItem>
                        <SelectItem value="thin">Too thin to publish</SelectItem>
                    </SelectGroup>
                    <SelectSeparator />
                    <SelectGroup>
                        <SelectLabel>Voice</SelectLabel>
                        <SelectItem value="tone">Wrong tone of voice</SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>
    );
}

export function Placeholder() {
    return (
        <div className="grid max-w-xs gap-2">
            <Label htmlFor="s-locale">Locale</Label>
            <Select>
                <SelectTrigger id="s-locale" className="w-full">
                    <SelectValue placeholder="Choose a locale" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="en">English</SelectItem>
                    <SelectItem value="pt">Português</SelectItem>
                    <SelectItem value="uk">Українська</SelectItem>
                </SelectContent>
            </Select>
        </div>
    );
}

export function SizesAndStates() {
    return (
        <div className="grid max-w-xs gap-4">
            <Select defaultValue="week">
                <SelectTrigger size="sm" className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="week">This week</SelectItem>
                    <SelectItem value="month">This month</SelectItem>
                </SelectContent>
            </Select>
            <Select defaultValue="week">
                <SelectTrigger className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="week">This week</SelectItem>
                    <SelectItem value="month">This month</SelectItem>
                </SelectContent>
            </Select>
            <Select disabled defaultValue="week">
                <SelectTrigger className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="week">This week</SelectItem>
                </SelectContent>
            </Select>
        </div>
    );
}
