import { X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * A list of short strings — competitors, audiences, keywords.
 *
 * A textarea would be less code, but the wizard fills these in from the site
 * analysis and the operator's job is to remove the two that are wrong rather
 * than to retype the eight that are right. Removing needs an X per item.
 */
export function Chips({
    values,
    onChange,
    placeholder,
    id,
}: {
    values: string[];
    onChange: (next: string[]) => void;
    placeholder: string;
    id?: string;
}) {
    const [entry, setEntry] = useState('');

    const add = () => {
        const value = entry.trim();

        if (value === '' || values.includes(value)) {
            setEntry('');

            return;
        }

        onChange([...values, value]);
        setEntry('');
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex gap-2">
                <Input
                    id={id}
                    value={entry}
                    placeholder={placeholder}
                    onChange={(event) => setEntry(event.target.value)}
                    onKeyDown={(event) => {
                        // Enter adds an item; it must not also submit the step,
                        // which is the default for a lone input in a form.
                        if (event.key === 'Enter' || event.key === ',') {
                            event.preventDefault();
                            add();
                        }
                    }}
                />
                <Button type="button" variant="secondary" onClick={add}>
                    Add
                </Button>
            </div>

            {values.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {values.map((value) => (
                        <Badge
                            key={value}
                            variant="secondary"
                            className="gap-1 pr-1"
                        >
                            {value}
                            <button
                                type="button"
                                aria-label={`Remove ${value}`}
                                className="inline-flex size-6 items-center justify-center rounded-full opacity-70 transition-opacity hover:opacity-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                onClick={() =>
                                    onChange(values.filter((v) => v !== value))
                                }
                            >
                                <X className="size-3" aria-hidden="true" />
                            </button>
                        </Badge>
                    ))}
                </div>
            )}
        </div>
    );
}
