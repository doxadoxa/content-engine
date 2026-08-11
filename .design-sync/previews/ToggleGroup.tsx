import { AlignCenter, AlignLeft, AlignRight } from 'lucide-react';
import { ToggleGroup, ToggleGroupItem } from 'avyo';

export function SingleSelect() {
    return (
        <ToggleGroup type="single" defaultValue="week" variant="outline">
            <ToggleGroupItem value="day">Day</ToggleGroupItem>
            <ToggleGroupItem value="week">Week</ToggleGroupItem>
            <ToggleGroupItem value="month">Month</ToggleGroupItem>
        </ToggleGroup>
    );
}

export function MultiSelect() {
    return (
        <ToggleGroup type="multiple" defaultValue={['en', 'pt']}>
            <ToggleGroupItem value="en">EN</ToggleGroupItem>
            <ToggleGroupItem value="pt">PT</ToggleGroupItem>
            <ToggleGroupItem value="uk">UK</ToggleGroupItem>
            <ToggleGroupItem value="ru">RU</ToggleGroupItem>
        </ToggleGroup>
    );
}

export function WithIcons() {
    return (
        <ToggleGroup type="single" defaultValue="left" variant="outline">
            <ToggleGroupItem value="left" aria-label="Align left">
                <AlignLeft />
            </ToggleGroupItem>
            <ToggleGroupItem value="center" aria-label="Align centre">
                <AlignCenter />
            </ToggleGroupItem>
            <ToggleGroupItem value="right" aria-label="Align right">
                <AlignRight />
            </ToggleGroupItem>
        </ToggleGroup>
    );
}
