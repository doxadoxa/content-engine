import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

/**
 * The mark and the application's name, as a lockup.
 *
 * No plate behind the icon any more. Aperture is a tile in its own right, so
 * the rounded `bg-sidebar-primary` box this used to sit in was a second
 * container around a container — two competing radii, and a fill that fought
 * the mark's own forest.
 */
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon className="size-8 shrink-0" />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    {name}
                </span>
            </div>
        </>
    );
}
