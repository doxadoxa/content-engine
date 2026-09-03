import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/password';
import { update } from '@/routes/user-password';

/**
 * Set a password, or change one.
 *
 * Which of the two it is depends on whether this account has ever had one: an
 * account created through Google has not, and the current-password field would
 * be an unanswerable question rather than a check — see
 * `App\Actions\Fortify\UpdateUserPassword`, which drops the rule for exactly
 * these accounts.
 */
export default function Password({ hasPassword }: { hasPassword: boolean }) {
    return (
        <>
            <Head title="Password settings" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Password"
                    description={
                        hasPassword
                            ? 'Use a long, random password to keep your account secure'
                            : 'You sign in with Google. Set a password to be able to sign in with your email address as well'
                    }
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
                            {hasPassword && (
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
                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>
                            )}

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
                                    {hasPassword
                                        ? 'Save password'
                                        : 'Set password'}
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
