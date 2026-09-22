import { Form, Link } from '@inertiajs/react';
import { useId } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { index, store } from '@/routes/assistant';

/** A deliberate question about the page the operator is already reviewing. */
export function ContextualAssistant({
    context,
    label = 'Ask about this work',
}: {
    context: string;
    label?: string;
}) {
    const id = useId();

    return (
        <details className="rounded-2xl border bg-card/60 px-5 py-4">
            <summary className="cursor-pointer text-sm font-medium">
                {label}
            </summary>
            <p className="mt-3 text-xs leading-5 text-muted-foreground">
                {context}
            </p>
            <Form
                action={store()}
                method="post"
                transform={(data) => ({
                    message: `${context}\n\n${String(data.question ?? '')}`,
                })}
                className="mt-4 space-y-3"
            >
                {({ errors, processing }) => (
                    <>
                        <Label htmlFor={id}>Your question</Label>
                        <Textarea
                            id={id}
                            name="question"
                            required
                            minLength={2}
                            maxLength={2500}
                            rows={3}
                            placeholder="What should I check before approving this?"
                        />
                        <InputError message={errors.message} />
                        <div className="flex flex-wrap items-center gap-4">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing && <Spinner className="size-4" />}
                                Ask Avyo
                            </Button>
                            <Link
                                href={index()}
                                className="text-xs text-muted-foreground underline underline-offset-4"
                            >
                                Previous discussions
                            </Link>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}
