import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, link } from '@/routes/password';
import { update } from '@/routes/user-password';

/**
 * Change a password — or, for an account that has never had one, ask for one.
 *
 * Two different screens rather than one form with a field removed. An account
 * created through Google has no current password to prove, and the answer to
 * that is not to stop asking: a session on its own is not evidence of anything
 * durable, and a password minted from a borrowed one outlives the session being
 * revoked. So it asks for the emailed link instead, which proves the inbox —
 * the same proof anybody who forgot their password gives. See
 * `App\Actions\Fortify\UpdateUserPassword` and `PasswordController::sendLink`.
 */
export default function Password({
    hasPassword,
    status,
}: {
    hasPassword: boolean;
    status?: string;
}) {
    if (!hasPassword) {
        return (
            <>
                <Head title="Password settings" />

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Password"
                        description="You sign in with Google, so there is no password on this account"
                    />

                    <div className="max-w-lg space-y-6">
                        <p className="text-sm text-muted-foreground">
                            You can add one, so that your email address and a
                            password work here too. We will email you a link to
                            set it — being signed in is not enough on its own,
                            because a password is the thing that would still
                            work if this session were not yours.
                        </p>

                        {/*
                         * Said before the button, not discovered after it. The
                         * link Fortify sends only opens for a signed-out
                         * browser, so asking for one ends this session — and a
                         * button that logs you out without warning is a worse
                         * surprise than a sentence.
                         */}
                        <p className="text-sm text-muted-foreground">
                            Asking for the link signs you out here. Getting back
                            in is one press of the Google button.
                        </p>

                        <Form {...link.form()}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Email me a link to set a password
                                </Button>
                            )}
                        </Form>

                        {status && (
                            <p className="text-sm text-muted-foreground">
                                {status}
                            </p>
                        )}
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Password settings" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Password"
                    description="Use a long, random password to keep your account secure"
                />

                <Form
                    {...update.form()}
                    // Cleared whichever way it ends. On success there is
                    // nothing left to submit; on failure the fields are wrong
                    // and retyping them is the point.
                    resetOnSuccess={[
                        'current_password',
                        'password',
                        'password_confirmation',
                    ]}
                    resetOnError={[
                        'current_password',
                        'password',
                        'password_confirmation',
                    ]}
                    className="max-w-lg space-y-6"
                >
                    {({ processing, errors, recentlySuccessful }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Current password
                                </Label>
                                <PasswordInput
                                    id="current_password"
                                    name="current_password"
                                    required
                                    autoComplete="current-password"
                                />
                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {hasPassword ? 'New password' : 'Password'}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
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
                                    autoComplete="new-password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Save password
                                </Button>

                                {recentlySuccessful && (
                                    <p className="text-sm text-muted-foreground">
                                        Saved
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Password.layout = {
    breadcrumbs: [{ title: 'Password settings', href: edit() }],
};
