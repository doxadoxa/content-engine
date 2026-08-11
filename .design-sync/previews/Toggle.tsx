import { Bold, Italic, Underline } from 'lucide-react';
import { Toggle } from 'avyo';

export function Variants() {
    return (
        <div className="flex items-center gap-3">
            <Toggle aria-label="Bold">
                <Bold />
            </Toggle>
            <Toggle variant="outline" aria-label="Italic">
                <Italic />
            </Toggle>
            <Toggle defaultPressed aria-label="Underline">
                <Underline />
            </Toggle>
            <Toggle variant="outline" defaultPressed aria-label="Bold">
                <Bold />
            </Toggle>
        </div>
    );
}

export function Sizes() {
    return (
        <div className="flex items-center gap-3">
            <Toggle size="sm" variant="outline" aria-label="Small">
                <Bold />
            </Toggle>
            <Toggle size="default" variant="outline" aria-label="Default">
                <Bold />
            </Toggle>
            <Toggle size="lg" variant="outline" aria-label="Large">
                <Bold />
            </Toggle>
        </div>
    );
}

export function WithText() {
    return (
        <div className="flex items-center gap-3">
            <Toggle variant="outline" defaultPressed>
                Only mine
            </Toggle>
            <Toggle variant="outline">Needs review</Toggle>
            <Toggle variant="outline" disabled>
                Archived
            </Toggle>
        </div>
    );
}
