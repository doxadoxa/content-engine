import { useEffect } from 'react';
import { toast } from 'sonner';
import { Toaster } from 'avyo';

/**
 * Toaster is only the container — it renders nothing until something calls
 * `toast()`. In the app that caller is `useFlashToast`, which turns an Inertia
 * flash message into a toast; here the effect below stands in for it so the
 * card has something to show.
 *
 * The toasts are pinned open (`duration: Infinity`) because a preview is a
 * single screenshot and the default four seconds is a race against it.
 */
function Demo({ kind }: { kind: 'success' | 'error' | 'info' }) {
    useEffect(() => {
        const opts = { duration: Infinity } as const;

        if (kind === 'success') {
            toast.success('Article published', {
                ...opts,
                description: 'Ten keyword clusters worth targeting — live on avyo.io',
            });
        } else if (kind === 'error') {
            toast.error('Publishing failed', {
                ...opts,
                description: 'The Threads token expired three days ago.',
            });
        } else {
            toast('12 drafts queued', {
                ...opts,
                description: 'Ready for review before Monday.',
            });
        }

        return () => toast.dismiss();
    }, [kind]);

    return (
        <div className="grid h-64 place-items-center text-sm text-muted-foreground">
            Toasts render bottom-right, over the page.
            <Toaster position="bottom-right" />
        </div>
    );
}

export function Success() {
    return <Demo kind="success" />;
}

export function Error() {
    return <Demo kind="error" />;
}

export function Neutral() {
    return <Demo kind="info" />;
}
