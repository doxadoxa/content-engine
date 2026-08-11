import {
    Button,
    Input,
    Label,
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from 'avyo';

/**
 * The side panel, open. Like Dialog, SheetContent portals to the body and
 * pins itself to an edge, so the card is sized to hold the whole panel and
 * the trigger keeps the card's own root non-empty.
 */
export function ProjectSettings() {
    return (
        <Sheet defaultOpen>
            <SheetTrigger asChild>
                <Button variant="outline">Edit project</Button>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>Edit project</SheetTitle>
                    <SheetDescription>
                        Changes apply to every locale from the next run onwards.
                    </SheetDescription>
                </SheetHeader>

                <div className="grid gap-4 px-4">
                    <div className="grid gap-2">
                        <Label htmlFor="sheet-name">Project name</Label>
                        <Input id="sheet-name" defaultValue="Avyo Blog" />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sheet-feed">Feed URL</Label>
                        <Input
                            id="sheet-feed"
                            defaultValue="https://avyo.io/feed.xml"
                        />
                    </div>
                </div>

                <SheetFooter>
                    <Button>Save changes</Button>
                    <SheetClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
