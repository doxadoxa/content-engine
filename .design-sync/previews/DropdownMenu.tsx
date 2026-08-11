import { ChevronDown } from 'lucide-react';
import {
    Button,
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from 'avyo';

/**
 * Shown open: the menu is the design question, and `defaultOpen` is the only
 * way a card that gets one render and no clicks can show it. The content
 * portals to the body and is positioned against the trigger, so the trigger
 * stays in the composition.
 */
export function Open() {
    return (
        <DropdownMenu defaultOpen>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    Article actions
                    <ChevronDown />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel>Ten keyword clusters</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                    <DropdownMenuItem>
                        Open in editor
                        <DropdownMenuShortcut>⌘E</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem>
                        Preview
                        <DropdownMenuShortcut>⌘P</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem>Duplicate</DropdownMenuItem>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuCheckboxItem checked>
                    Publish automatically
                </DropdownMenuCheckboxItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive">
                    Send back
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
