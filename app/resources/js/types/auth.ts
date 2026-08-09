export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type ProjectStatus = 'active' | 'paused';

export type ProjectRole = 'owner' | 'operator';

/**
 * The project being worked in.
 *
 * Every list on every page is scoped to it, which is why it is shared on all
 * pages rather than fetched where it happens to be needed: a page that renders
 * before the switcher knows which project it is in shows the wrong rows first
 * and the right ones after.
 */
export type CurrentProject = {
    id: string;
    name: string;
    slug: string;
    status: ProjectStatus;
    timezone: string;
    default_locale: string;
    locales: string[];
    role: ProjectRole | null;
};

export type ProjectOption = {
    id: string;
    name: string;
    slug: string;
    status: ProjectStatus;
};

export type Auth = {
    user: User | null;
    /** Null when nobody is signed in, or before the operator picks a project. */
    project: CurrentProject | null;
    /** Every project the viewer may switch to. Empty for a guest. */
    projects: ProjectOption[];
};
