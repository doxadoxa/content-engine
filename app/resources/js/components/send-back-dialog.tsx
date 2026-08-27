import { Form } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { reject } from '@/routes/content';

export type Reason = { value: string; label: string };

/** The little a send-back needs to know about what it is sending back. */
export type SendBackTarget = { id: string; title: string };

/**
 * Send a unit back to whoever will rewrite it.
 *
 * A reason is required and comes from a closed set — §7 makes the rejection the
 * input to phase 9's quality loop, and free text cannot be counted. The note is
 * optional and is for the person, not the count.
 *
 * Shared by the approvals queue and the article workspace because they are the
 * same decision reached from two directions: a draft that should not be
 * approved, and an approved article that should not have been. The second had
 * no screen at all until this moved out of the queue — `approved` has one edge
 * and it points at `published`.
 */
export function SendBackDialog({
    item,
    reasons,
    onClose,
}: {
    item: SendBackTarget | null;
    reasons: Reason[];
    onClose: () => void;
}) {
    return (
        <Dialog
            open={item !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="rounded-[1.5rem]">
                {item !== null && (
                    <Form
                        action={reject(item.id).url}
                        method="post"
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Send back</DialogTitle>
                                    <DialogDescription>
                                        {item.title}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="grid gap-4 py-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="reason">
                                            Reason for sending it back
                                        </Label>
                                        <Select name="reason" required>
                                            <SelectTrigger id="reason">
                                                <SelectValue placeholder="Pick a reason" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {reasons.map((reason) => (
                                                    <SelectItem
                                                        key={reason.value}
                                                        value={reason.value}
                                                    >
                                                        {reason.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground">
                                            Your reason and note are saved with
                                            this item.
                                        </p>
                                        {errors.reason && (
                                            <p className="text-xs text-destructive">
                                                {errors.reason}
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="note">
                                            What would fix it
                                        </Label>
                                        <Textarea
                                            id="note"
                                            name="note"
                                            rows={3}
                                        />
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={onClose}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        Send back
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
