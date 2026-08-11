import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    Label,
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
    Textarea,
} from 'avyo';

/**
 * The send-back dialog from the approvals queue, shown open.
 *
 * `defaultOpen` rather than a click, because a preview card gets one render
 * and no interaction — and a modal's whole design question is what it looks
 * like open. DialogContent portals to the body and positions itself fixed, so
 * the trigger is what keeps the card's own root non-empty.
 */
export function SendBack() {
    return (
        <Dialog defaultOpen>
            <DialogTrigger asChild>
                <Button variant="outline">Send back</Button>
            </DialogTrigger>
            <DialogContent className="rounded-[1.5rem]">
                <DialogHeader>
                    <DialogTitle>Send back</DialogTitle>
                    <DialogDescription>
                        Ten keyword clusters worth targeting this quarter
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 py-2">
                    <div className="grid gap-2">
                        <Label htmlFor="reason">What is wrong with it</Label>
                        <Select defaultValue="off-brief">
                            <SelectTrigger id="reason">
                                <SelectValue placeholder="Pick a reason" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="off-brief">
                                    Does not answer the brief
                                </SelectItem>
                                <SelectItem value="thin">
                                    Too thin to publish
                                </SelectItem>
                                <SelectItem value="tone">
                                    Wrong tone of voice
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="note">Note for the writer</Label>
                        <Textarea
                            id="note"
                            rows={3}
                            defaultValue="Second half repeats the intro almost verbatim — cut it and go deeper on the pricing comparison instead."
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="ghost">Cancel</Button>
                    <Button>Send back</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
