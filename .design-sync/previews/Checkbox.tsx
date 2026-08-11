import { Checkbox, Label } from 'avyo';

export function States() {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <Checkbox id="c-unchecked" />
                <Label htmlFor="c-unchecked">Unchecked</Label>
            </div>
            <div className="flex items-center gap-3">
                <Checkbox id="c-checked" defaultChecked />
                <Label htmlFor="c-checked">Checked</Label>
            </div>
            <div className="flex items-center gap-3">
                <Checkbox id="c-disabled" disabled />
                <Label htmlFor="c-disabled">Disabled</Label>
            </div>
            <div className="flex items-center gap-3">
                <Checkbox id="c-disabled-checked" defaultChecked disabled />
                <Label htmlFor="c-disabled-checked">Disabled, checked</Label>
            </div>
        </div>
    );
}

/** The locale picker from the project settings form. */
export function LocaleList() {
    const locales = [
        { id: 'en', label: 'English', on: true },
        { id: 'pt', label: 'Português', on: true },
        { id: 'uk', label: 'Українська', on: false },
        { id: 'ru', label: 'Русский', on: false },
    ];

    return (
        <fieldset className="flex max-w-sm flex-col gap-3">
            <legend className="mb-2 text-sm font-medium">
                Publish this project in
            </legend>
            {locales.map(({ id, label, on }) => (
                <div key={id} className="flex items-center gap-3">
                    <Checkbox id={`locale-${id}`} defaultChecked={on} />
                    <Label htmlFor={`locale-${id}`}>{label}</Label>
                </div>
            ))}
        </fieldset>
    );
}
