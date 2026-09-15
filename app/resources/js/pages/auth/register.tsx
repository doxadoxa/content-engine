import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { ConsentNote, SocialAuthChoice } from '@/components/social-auth';
import type { SocialProvider } from '@/components/social-auth';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
    trialDays: number;
    socialProviders: SocialProvider[];
    socialError?: string;
};

/**
 * The account, and only the account.
 *
 * No card, no plan, no project. The wizard after this is what makes a project,
 * and the free window starts when the engine does rather than here — somebody
 * who signs up on Friday and finishes setting up on Monday has not had a trial
 * over the weekend.
 *
 * **Two steps, and the choice is the first one.** Google first, then a divider,
 * then a button through to the form. The form is three fields and a password
 * this person now has to remember; the alternative is one click and an address
 * already proved. Showing both at once would be honest and would still bury the
 * shorter path under the longer one, so the shorter one is what the screen
 * opens on.
 *
 * The step lives in component state rather than in the URL: it is which half of
 * one screen is showing, not a page somebody should be able to link to,
 * bookmark, or land on from a back button expecting their typing to still be
 * there.
 *
 * With no provider configured the choice step is one button reading "Sign up
 * with email", which is a pointless click — so the form opens directly. See
 * `FortifyServiceProvider::socialProps()` for where that list comes from.
 */
export default function Register({
    passwordRules,
    trialDays,
    socialProviders,
    socialError,
}: Props) {
    const [withEmail, setWithEmail] = useState(socialProviders.length === 0);

    return (
        <>
            <Head title="Create your account" />

            <div className="flex flex-col gap-6">
                {socialError && <AlertError errors={[socialError]} />}

                {!withEmail ? (
                    <div className="grid gap-6">
                        <SocialAuthChoice
                            providers={socialProviders}
                            verb="Sign up"
                            emailLabel="Sign up with email"
                            onEmail={() => setWithEmail(true)}
                        />

                        <ConsentNote />

                        <div className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()}>Log in</TextLink>
                        </div>
                    </div>
                ) : (
                    <Form
                        {...store.form()}
                        resetOnSuccess={['password', 'password_confirmation']}
                        disableWhileProcessing
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <div className="grid gap-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Your name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        name="name"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="name"
                                        placeholder="Alex Moreira"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        tabIndex={2}
                                        autoComplete="email"
                                        placeholder="email@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        tabIndex={3}
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                        placeholder="Password"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        tabIndex={4}
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                        placeholder="Confirm password"
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>

                                <ConsentNote />

                                <Button
                                    type="submit"
                                    className="w-full"
                                    tabIndex={5}
                                    disabled={processing}
                                    data-test="register-button"
                                >
                                    {processing && <Spinner />}
                                    Create account
                                </Button>

                                {/*
                                 * Precise about the card rather than quiet
                                 * about it. This said "no card needed", which
                                 * was true of the design it was written for and
                                 * false of this one — the card is asked for at
                                 * the end of the wizard, once we have read their
                                 * site. Somebody who finds that out after
                                 * signing up has been misled, and the whole
                                 * reason to ask late rather than never is that
                                 * it is the honest version.
                                 */}
                                <p className="text-center text-sm text-muted-foreground">
                                    {trialDays} days free. We ask for a card
                                    once your site is set up, and charge nothing
                                    until the {trialDays} days are up.
                                </p>

                                {/*
                                 * The way back to the choice. The reference
                                 * design has none, and on a screen whose first
                                 * step is the recommended one that is a trap:
                                 * one misclick and the only route back to the
                                 * Google button is a page reload.
                                 */}
                                {socialProviders.length > 0 && (
                                    <div className="text-center text-sm text-muted-foreground">
                                        <button
                                            type="button"
                                            tabIndex={6}
                                            className="underline decoration-neutral-300 underline-offset-4 transition-colors hover:decoration-current dark:decoration-neutral-500"
                                            onClick={() => setWithEmail(false)}
                                        >
                                            Other ways to sign up
                                        </button>
                                    </div>
                                )}

                                <div className="text-center text-sm text-muted-foreground">
                                    Already have an account?{' '}
                                    <TextLink href={login()} tabIndex={7}>
                                        Log in
                                    </TextLink>
                                </div>
                            </div>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

Register.layout = {
    title: 'Create your account',
    description: 'Tell us who you are, and we will read your site next',
};
