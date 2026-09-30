import { Form } from '@inertiajs/react';
import { Download, Globe, Webhook } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { wordPressApiBase } from '@/components/website/types';
import { workspacePanelClass } from '@/components/workspace-page';
import { cn } from '@/lib/utils';
import { store } from '@/routes/channels';

type Method = 'webhook' | 'wordpress';

const METHODS: {
    value: Method;
    label: string;
    detail: string;
    icon: LucideIcon;
}[] = [
    {
        value: 'webhook',
        label: 'Webhook',
        detail: 'Your site or developer receives articles at an address.',
        icon: Webhook,
    },
    {
        value: 'wordpress',
        label: 'WordPress',
        detail: 'Install the Avyo plugin on your WordPress site.',
        icon: Globe,
    },
];

/**
 * Connecting a website, inline: pick how, give the address, and Avyo tests it.
 *
 * This used to be a dialog behind a button, defaulting to WordPress and
 * listing the pull API beside it, that asked the owner to invent a signing
 * secret and then test from a table cell. Now the secret is Avyo's to make
 * and the test runs on save; the page that comes back says whether it worked.
 */
export function ConnectWebsiteForm({
    onCancel,
}: {
    /** Offered when another website is being added; also called once connected. */
    onCancel?: () => void;
}) {
    const [method, setMethod] = useState<Method>('webhook');
    const headingId = useId();

    return (
        <section
            aria-labelledby={headingId}
            className={`${workspacePanelClass} flex flex-col gap-6 p-5 sm:p-7`}
        >
            <div>
                <h2
                    id={headingId}
                    className="text-xl font-semibold tracking-[-0.03em]"
                >
                    Where should Avyo publish your articles?
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Connect your website once. Avyo tests the connection as soon
                    as you save.
                </p>
            </div>

            <fieldset>
                <legend className="sr-only">How to connect</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    {METHODS.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex cursor-pointer gap-3 rounded-xl border p-4 text-sm transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                                method === option.value
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/40',
                            )}
                        >
                            <input
                                type="radio"
                                name="connect-method"
                                className="sr-only"
                                value={option.value}
                                checked={method === option.value}
                                onChange={() => setMethod(option.value)}
                            />
                            <option.icon
                                className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span>
                                <span className="block font-medium">
                                    {option.label}
                                </span>
                                <span className="mt-0.5 block leading-relaxed text-muted-foreground">
                                    {option.detail}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>

            {method === 'webhook' ? (
                <WebhookFields onCancel={onCancel} />
            ) : (
                <WordPressFields onCancel={onCancel} />
            )}

            <details className="group text-sm">
                <summary className="w-fit cursor-pointer rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    Other ways to connect
                </summary>
                <PullFields onCancel={onCancel} />
            </details>
        </section>
    );
}

function WebhookFields({ onCancel }: { onCancel?: () => void }) {
    return (
        <Form
            action={store().url}
            method="post"
            options={{ preserveScroll: true }}
            onSuccess={onCancel}
            className="flex flex-col gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="type" value="webhook" />
                    <div className="grid gap-2">
                        <Label htmlFor="connect-endpoint">
                            Webhook address
                        </Label>
                        <Input
                            id="connect-endpoint"
                            name="config[endpoint]"
                            type="url"
                            inputMode="url"
                            autoComplete="url"
                            required
                            placeholder="https://example.com/avyo/webhook"
                            aria-describedby="connect-endpoint-hint"
                            aria-invalid={
                                errors['config.endpoint'] ? true : undefined
                            }
                        />
                        <p
                            id="connect-endpoint-hint"
                            className="text-xs text-muted-foreground"
                        >
                            Avyo sends each article here as a signed request.
                            After you connect, you get the secret your developer
                            needs.
                        </p>
                        <InputError message={errors['config.endpoint']} />
                    </div>

                    <details className="text-sm">
                        <summary className="w-fit cursor-pointer rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            More options
                        </summary>
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="connect-name">Name</Label>
                                <Input
                                    id="connect-name"
                                    name="name"
                                    placeholder="The website's domain"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="connect-secret">
                                    Your own secret
                                </Label>
                                <Input
                                    id="connect-secret"
                                    name="secret"
                                    type="password"
                                    autoComplete="new-password"
                                    aria-describedby="connect-secret-hint"
                                />
                                <p
                                    id="connect-secret-hint"
                                    className="text-xs text-muted-foreground"
                                >
                                    Only if your developer already has one.
                                    Leave empty and Avyo creates it.
                                </p>
                                <InputError message={errors.secret} />
                            </div>
                        </div>
                    </details>

                    <div className="-mt-2 flex flex-col gap-2">
                        <PageUpdatesField />
                        <InputError
                            message={errors['config.page_receiver_base']}
                        />
                    </div>

                    <FormActions
                        processing={processing}
                        error={errors.name ?? errors.type}
                        onCancel={onCancel}
                    />
                </>
            )}
        </Form>
    );
}

function WordPressFields({ onCancel }: { onCancel?: () => void }) {
    const [apiBase, setApiBase] = useState('');

    return (
        <Form
            action={store().url}
            method="post"
            options={{ preserveScroll: true }}
            onSuccess={onCancel}
            className="flex flex-col gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="type" value="wordpress" />
                    <ol className="grid gap-1.5 text-sm text-muted-foreground">
                        <li>
                            1.{' '}
                            <a
                                href="/integrations/wordpress/receiver.zip"
                                className="inline-flex items-center gap-1 font-medium text-foreground underline underline-offset-4"
                            >
                                <Download
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                Download the Avyo plugin
                            </a>
                            , then in WordPress open Plugins → Add New → Upload
                            Plugin, and activate it.
                        </li>
                        <li>
                            2. In WordPress, open your user profile and create
                            an application password.
                        </li>
                        <li>3. Enter the details below.</li>
                    </ol>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="connect-wp-site">
                                WordPress site address
                            </Label>
                            <Input
                                id="connect-wp-site"
                                type="url"
                                inputMode="url"
                                required
                                placeholder="https://example.com"
                                onChange={(event) =>
                                    setApiBase(
                                        wordPressApiBase(event.target.value),
                                    )
                                }
                            />
                            <InputError
                                message={errors['config.page_receiver_base']}
                            />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="connect-wp-username">
                                WordPress username
                            </Label>
                            <Input
                                id="connect-wp-username"
                                name="config[username]"
                                autoComplete="off"
                                required
                            />
                            <InputError message={errors['config.username']} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="connect-wp-password">
                                Application password
                            </Label>
                            <Input
                                id="connect-wp-password"
                                name="secret"
                                type="password"
                                autoComplete="new-password"
                                required
                            />
                            <InputError message={errors.secret} />
                        </div>
                    </div>

                    <details className="text-sm">
                        <summary className="w-fit cursor-pointer rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            More options
                        </summary>
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="connect-wp-name">Name</Label>
                                <Input
                                    id="connect-wp-name"
                                    name="name"
                                    placeholder="The website's domain"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="connect-wp-api">
                                    Plugin address
                                </Label>
                                <Input
                                    id="connect-wp-api"
                                    name="config[page_receiver_base]"
                                    type="url"
                                    value={apiBase}
                                    onChange={(event) =>
                                        setApiBase(event.target.value)
                                    }
                                    aria-describedby="connect-wp-api-hint"
                                />
                                <p
                                    id="connect-wp-api-hint"
                                    className="text-xs text-muted-foreground"
                                >
                                    Filled in from the site address. Change it
                                    only if WordPress lives somewhere else.
                                </p>
                            </div>
                        </div>
                    </details>

                    <FormActions
                        processing={processing}
                        error={errors.name ?? errors.type}
                        onCancel={onCancel}
                    />
                </>
            )}
        </Form>
    );
}

/** For static sites that fetch articles themselves. Rare, so tucked away. */
function PullFields({ onCancel }: { onCancel?: () => void }) {
    return (
        <Form
            action={store().url}
            method="post"
            options={{ preserveScroll: true }}
            onSuccess={onCancel}
            className="mt-3 flex flex-col gap-4 rounded-xl border border-dashed p-4"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="type" value="pull_api" />
                    <p className="text-muted-foreground">
                        Pull API: your site fetches new articles from Avyo on
                        its own schedule, sending a token you choose.
                    </p>
                    <div className="grid gap-2 sm:max-w-sm">
                        <Label htmlFor="connect-pull-token">Token</Label>
                        <Input
                            id="connect-pull-token"
                            name="secret"
                            type="password"
                            autoComplete="new-password"
                            required
                        />
                        <InputError message={errors.secret ?? errors.name} />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                        className="w-fit rounded-full"
                    >
                        Connect with Pull API
                    </Button>
                </>
            )}
        </Form>
    );
}

/**
 * The address a developer sets up for updating pages already on the site.
 * Named plainly and kept out of the way: almost nobody needs it.
 */
export function PageUpdatesField({
    defaultValue,
    className,
    idPrefix = 'connect',
}: {
    defaultValue?: string;
    className?: string;
    idPrefix?: string;
}) {
    const id = `${idPrefix}-page-updates`;

    return (
        <details className={cn('text-sm', className)}>
            <summary className="w-fit cursor-pointer rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                Advanced
            </summary>
            <div className="mt-3 grid gap-2 sm:max-w-xl">
                <Label htmlFor={id}>Page updates address</Label>
                <Input
                    id={id}
                    name="config[page_receiver_base]"
                    type="url"
                    defaultValue={defaultValue}
                    placeholder="https://example.com/api/avyo/pages/v1"
                    aria-describedby={`${id}-hint`}
                />
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    Only if your developer set up a way for Avyo to update pages
                    already on your site. Leave empty otherwise.
                </p>
            </div>
        </details>
    );
}

function FormActions({
    processing,
    error,
    onCancel,
}: {
    processing: boolean;
    error?: string;
    onCancel?: () => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <InputError message={error} role="alert" />
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="submit"
                    disabled={processing}
                    className="rounded-full px-5"
                >
                    {processing ? 'Connecting…' : 'Connect and test'}
                </Button>
                {onCancel && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="rounded-full"
                        onClick={onCancel}
                    >
                        Cancel
                    </Button>
                )}
            </div>
        </div>
    );
}
