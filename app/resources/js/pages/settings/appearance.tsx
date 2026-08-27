import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Appearance"
                    description="Choose how Avyo looks on this device."
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance',
            href: editAppearance(),
        },
    ],
};
