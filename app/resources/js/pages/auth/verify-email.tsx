import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

type Props = {
    status?: string;
};

/**
 * The wait between signing up and being allowed to spend our money.
 *
 * Not a nag screen: it is the cheapest of the three checks that keep a
 * card-free trial affordable, and it is here because every trial costs real
 * model and image calls at a provider. Two buttons, because there are exactly
 * two things somebody in this state wants — send it again, or leave.
 */
export default function VerifyEmail({ status }: Props) {
    return (
        <>
            <Head title="Confirm your email" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    A new link is on its way to the address you signed up with.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Send the link again
                        </Button>

                        <div>
                            <Button asChild variant="ghost" size="sm">
                                <a href={logout().url}>Log out</a>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Confirm your email',
    description:
        'We have sent a link to the address you signed up with. Open it and the engine is yours to point at a site.',
};
