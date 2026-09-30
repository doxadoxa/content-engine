import { Head, usePage, usePoll } from '@inertiajs/react';
import { Globe, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConnectWebsiteForm } from '@/components/website/connect-website-form';
import type { WebsiteConnection } from '@/components/website/types';
import { WebsiteConnectionCard } from '@/components/website/website-connection-card';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index } from '@/routes/channels';

type Props = {
    channels: WebsiteConnection[];
    can_connect_another: boolean;
};

/**
 * Your website: connect it, see whether it works, hand the developer what
 * they need.
 *
 * Still `/channels` underneath. The page used to open on a metric strip and
 * a table built for many connections; nearly every project has exactly one,
 * so it now opens on that one and says, in a sentence, whether it works.
 */
export default function WebsitePage({
    channels,
    can_connect_another: canConnectAnother,
}: Props) {
    const { auth } = usePage().props;
    const isOwner = auth.project?.role === 'owner';
    const [addingAnother, setAddingAnother] = useState(false);

    // A test answers within seconds now, so poll while one is running and
    // not otherwise.
    const polling = usePoll(
        3000,
        { only: ['channels'] },
        { autoStart: false, mode: 'rest' },
    );
    const testing = channels.some(
        (channel) => channel.health.state === 'testing',
    );

    useEffect(() => {
        if (testing) {
            polling.start();
        } else {
            polling.stop();
        }

        return polling.stop;
    }, [testing, polling]);

    const connected = channels.some(
        (channel) => channel.health.state === 'connected',
    );

    return (
        <>
            <Head title="Your website" />

            <WorkspacePage width="reading" className="max-w-4xl">
                <WorkspaceHeader
                    eyebrow="Publishing"
                    title="Your website"
                    description={
                        channels.length === 0
                            ? 'Connect the website where Avyo publishes your articles.'
                            : connected
                              ? 'Avyo publishes your articles here.'
                              : 'Avyo publishes your articles here once the connection works.'
                    }
                    actions={
                        isOwner &&
                        channels.length > 0 &&
                        canConnectAnother &&
                        !addingAnother ? (
                            <Button
                                variant="outline"
                                className="rounded-full"
                                onClick={() => setAddingAnother(true)}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                Connect another website
                            </Button>
                        ) : undefined
                    }
                />

                {channels.length === 0 &&
                    (isOwner ? (
                        <ConnectWebsiteForm />
                    ) : (
                        <section
                            className={`${workspacePanelClass} flex flex-col items-center gap-2 px-6 py-12 text-center`}
                        >
                            <Globe
                                className="size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <h2 className="font-semibold">
                                No website connected yet
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Ask a project owner to connect the website where
                                articles are published.
                            </p>
                        </section>
                    ))}

                {channels.map((channel) => (
                    <WebsiteConnectionCard
                        key={channel.id}
                        connection={channel}
                        isOwner={isOwner}
                    />
                ))}

                {isOwner && channels.length > 0 && addingAnother && (
                    <ConnectWebsiteForm
                        onCancel={() => setAddingAnother(false)}
                    />
                )}

                {!isOwner && channels.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Only a project owner can change or test the website
                        connection.
                    </p>
                )}
            </WorkspacePage>
        </>
    );
}

WebsitePage.layout = {
    breadcrumbs: [{ title: 'Your website', href: index() }],
};
