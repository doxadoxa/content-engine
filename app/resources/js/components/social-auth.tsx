import GoogleLogo from '@/components/google-logo';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { privacy, terms } from '@/routes/legal';
import { redirect } from '@/routes/oauth';

export type SocialProvider = {
    key: string;
    label: string;
};

/**
 * The provider buttons, the divider, and the way through to the email form.
 *
 * Shaped after the pattern the sign-up screens people already use are built
 * from: the one-click way first, a divider, then email — rather than a form
 * with a Google button bolted underneath it. The order is the point. Somebody
 * who has a Google account never reads the form, and somebody who does not is
 * one click from it; a form shown first makes the fast path the thing you
 * scroll past.
 *
 * Anchors rather than Inertia links, because the destination is Google. An
 * Inertia visit would ask for a page and be handed a cross-origin redirect it
 * cannot follow.
 *
 * On the product's own palette rather than the reference's: the soft fill is
 * `accent` and the email button is `primary`, which inside `.product-shell` are
 * the tomato tint and the forest this product is actually made of. Copying
 * another product's colours onto this screen would reintroduce exactly the
 * fault the split layout's note describes — a sign-in that looks like a
 * different company from the thing behind it.
 *
 * **Semantic tokens, not the `--brand-*` ones**, and the first version of this
 * got it wrong in a way worth recording. `bg-[var(--brand-violet-wash)]` with
 * `text-foreground` looks right in light mode and is unreadable in dark: the
 * wash is a fixed pale tomato defined once, while `--foreground` flips to
 * cream, so the label went light-on-light. `accent`/`accent-foreground` is the
 * same pair of colours *and* is redefined under `.dark .product-shell`, which
 * is the whole reason the semantic layer exists.
 */
export function SocialAuthChoice({
    providers,
    verb,
    emailLabel,
    onEmail,
}: {
    providers: SocialProvider[];
    /** "Sign up" or "Log in" — the buttons say the same word as the heading. */
    verb: string;
    emailLabel: string;
    onEmail: () => void;
}) {
    return (
        <div className="grid gap-5">
            {providers.map((provider) => (
                <a
                    key={provider.key}
                    href={redirect({ provider: provider.key }).url}
                    className={cn(
                        'inline-flex h-11 w-full items-center justify-center gap-3 rounded-md',
                        'border border-border bg-accent text-accent-foreground',
                        'text-sm font-medium shadow-xs transition-colors hover:bg-accent/70',
                        'outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    )}
                    data-test={`oauth-${provider.key}`}
                >
                    <GoogleLogo className="size-[18px]" />
                    {verb} with {provider.label}
                </a>
            ))}

            {providers.length > 0 && (
                /*
                 * Decorative: the two buttons either side already say what they
                 * do, and a screen reader announcing "or" between them adds a
                 * word to read and no information.
                 */
                <div aria-hidden className="flex items-center gap-4">
                    <span className="h-px flex-1 bg-border" />
                    <span className="text-sm text-muted-foreground">or</span>
                    <span className="h-px flex-1 bg-border" />
                </div>
            )}

            <Button type="button" className="w-full" onClick={onEmail}>
                {emailLabel}
            </Button>
        </div>
    );
}

/**
 * What signing up commits somebody to, next to the button that does it.
 *
 * Here rather than only in the footer because this is the moment of consent,
 * and because the two documents are already routes on this application — see
 * `LegalController`. Rendered on the choice step *and* on the email form, since
 * either one of them can be the last thing read before an account exists.
 */
export function ConsentNote() {
    return (
        <p className="text-center text-sm text-muted-foreground">
            By creating an account you accept our{' '}
            <TextLink href={terms()}>Terms</TextLink> and{' '}
            <TextLink href={privacy()}>Privacy Policy</TextLink>.
        </p>
    );
}
