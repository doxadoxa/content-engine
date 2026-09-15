import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { SocialAuthChoice } from '@/components/social-auth';
import type { SocialProvider } from '@/components/social-auth';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    socialProviders: SocialProvider[];
    socialError?: string;
};

/**
 * The same two steps as sign-up, and for a stronger reason.
 *
 * Somebody who created their account with Google has no password, so a screen
 * that opens on an email form opens on a form they cannot complete — and the
 * failure is silent: their address exists, the password is wrong, and the
 * message says so. The choice step is what stops that, which is why it is here
 * and not only on the screen where it looks like a growth tactic.
 *
 * A failed return from a provider lands here too, whichever screen it started
 * on — see `SocialLoginController` — so this is the one place the message has
 * to be shown.
 */
export default function Login({
    status,
    canResetPassword,
    socialProviders,
    socialError,
}: Props) {
    const [withEmail, setWithEmail] = useState(socialProviders.length === 0);

    return (
        <>
            <Head title="Log in" />

            <div className="flex flex-col gap-6">
                {socialError && <AlertError errors={[socialError]} />}

                {status && (
                    <div className="text-center text-sm font-medium text-green-600">
                        {status}
                    </div>
                )}

                {!withEmail ? (
                    <div className="grid gap-6">
                        <SocialAuthChoice
                            providers={socialProviders}
                            verb="Log in"
                            emailLabel="Log in with email"
                            onEmail={() => setWithEmail(true)}
                        />

                        {/*
                         * The way out of here, which did not exist while
                         * accounts were made by hand. A public sign-up that the
                         * sign-in screen never mentions is a sign-up only
                         * people who already know the URL can find.
                         */}
                        <div className="text-center text-sm text-muted-foreground">
                            New here?{' '}
                            <TextLink href={register()}>
                                Create an account
                            </TextLink>
                        </div>
                    </div>
                ) : (
                    <Form
                        {...store.form()}
                        resetOnSuccess={['password']}
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <div className="grid gap-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="email"
                                        placeholder="email@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center">
                                        <Label htmlFor="password">
                                            Password
                                        </Label>
                                        {canResetPassword && (
                                            <TextLink
                                                href={request()}
                                                className="ml-auto text-sm"
                                                tabIndex={5}
                                            >
                                                Forgot your password?
                                            </TextLink>
                                        )}
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        tabIndex={2}
                                        autoComplete="current-password"
                                        placeholder="Password"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="flex items-center space-x-3">
                                    <Checkbox
                                        id="remember"
                                        name="remember"
                                        tabIndex={3}
                                    />
                                    <Label htmlFor="remember">
                                        Remember me
                                    </Label>
                                </div>

                                <Button
                                    type="submit"
                                    className="mt-2 w-full"
                                    tabIndex={4}
                                    disabled={processing}
                                    data-test="login-button"
                                >
                                    {processing && <Spinner />}
                                    Log in
                                </Button>

                                {socialProviders.length > 0 && (
                                    <div className="text-center text-sm text-muted-foreground">
                                        <button
                                            type="button"
                                            tabIndex={6}
                                            className="underline decoration-neutral-300 underline-offset-4 transition-colors hover:decoration-current dark:decoration-neutral-500"
                                            onClick={() => setWithEmail(false)}
                                        >
                                            Other ways to log in
                                        </button>
                                    </div>
                                )}

                                <div className="text-center text-sm text-muted-foreground">
                                    New here?{' '}
                                    <TextLink href={register()} tabIndex={7}>
                                        Create an account
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

/*
 * The description no longer names the email form. It said "Enter your email and
 * password below to log in", which is now false on the first thing this screen
 * shows — and a heading that describes the step after the one you are looking
 * at is worse than a vaguer one that is true of both.
 */
Login.layout = {
    title: 'Log in to your account',
    description: 'Welcome back — pick up where you left off',
};
