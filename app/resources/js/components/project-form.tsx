import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { DutyHoursField } from '@/components/duty-hours-field';
import type { DutyHoursValue } from '@/components/duty-hours-field';
import { FeedUrlsField } from '@/components/feed-urls-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { workspacePanelClass } from '@/components/workspace-page';
import { Chips } from '@/pages/onboarding/chips';
import type { ProjectStatus } from '@/types';

export type ProjectFormValues = {
    id: string;
    name: string;
    slug: string;
    timezone: string;
    duty_hours: DutyHoursValue;
    feed_urls: string[];
    default_locale: string;
    locales: string[];
    status: ProjectStatus;
    market: string;
    weekly_target: number;
    research_seeds: string[];
    minimum_volume: number | null;
};

type Props = {
    action: { url: string; method: 'post' | 'patch' };
    timezones: string[];
    project?: ProjectFormValues;
    submitLabel: string;
};

/**
 * The one form behind both create and edit.
 *
 * Split into "identity" and "publishing" rather than one long column: the first
 * group is decided once and the second is what an operator comes back to
 * change, and mixing them makes the second harder to find.
 */
export function ProjectForm({
    action,
    timezones,
    project,
    submitLabel,
}: Props) {
    const isEdit = project !== undefined;

    const [name, setName] = useState(project?.name ?? '');
    const [slug, setSlug] = useState(project?.slug ?? '');
    const [slugTouched, setSlugTouched] = useState(isEdit);

    const [market, setMarket] = useState(project?.market ?? 'us');
    const [weeklyTarget, setWeeklyTarget] = useState(
        String(project?.weekly_target ?? 3),
    );
    const [seeds, setSeeds] = useState<string[]>(project?.research_seeds ?? []);
    const [minimumVolume, setMinimumVolume] = useState(
        project?.minimum_volume === null ||
            project?.minimum_volume === undefined
            ? ''
            : String(project.minimum_volume),
    );

    // Additional locales only. The default is always published, so listing it
    // here too would invite an operator to remove it from a set it cannot
    // leave — the server puts it back either way.
    const [extraLocales, setExtraLocales] = useState(
        (project?.locales ?? [])
            .filter((locale) => locale !== project?.default_locale)
            .join(', '),
    );
    const [defaultLocale, setDefaultLocale] = useState(
        project?.default_locale ?? 'en',
    );

    const [timezone, setTimezone] = useState(project?.timezone ?? 'UTC');
    const [dutyHours, setDutyHours] = useState<DutyHoursValue>(
        project?.duty_hours ?? {},
    );
    const [feedUrls, setFeedUrls] = useState<string[]>(
        project?.feed_urls ?? [],
    );

    return (
        <Form
            action={action.url}
            method={action.method}
            transform={(data) => ({
                ...data,
                duty_hours: dutyHours,
                feed_urls: feedUrls,
                locales: extraLocales
                    .split(',')
                    .map((locale) => locale.trim())
                    .filter(Boolean),
            })}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle>Identity</CardTitle>
                            <CardDescription>
                                What this project is called and the identifier
                                Avyo uses when publishing.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    value={name}
                                    autoFocus
                                    required
                                    onChange={(event) => {
                                        setName(event.target.value);

                                        if (!slugTouched) {
                                            setSlug(
                                                slugify(event.target.value),
                                            );
                                        }
                                    }}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    name="slug"
                                    value={slug}
                                    required
                                    readOnly={isEdit}
                                    aria-describedby="slug-help"
                                    onChange={(event) => {
                                        setSlugTouched(true);
                                        setSlug(event.target.value);
                                    }}
                                />
                                <p
                                    id="slug-help"
                                    className="text-xs text-muted-foreground"
                                >
                                    {isEdit
                                        ? 'Fixed once the project exists — it identifies this project to every receiver it has published to.'
                                        : 'Lowercase letters, numbers and hyphens.'}
                                </p>
                                <InputError message={errors.slug} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle>Publishing</CardTitle>
                            <CardDescription>
                                The schedule for automated work and the
                                languages this project publishes in.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="timezone">Time zone</Label>
                                <Select
                                    name="timezone"
                                    value={timezone}
                                    onValueChange={setTimezone}
                                >
                                    <SelectTrigger id="timezone">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent className="max-h-72">
                                        {timezones.map((zone) => (
                                            <SelectItem key={zone} value={zone}>
                                                {zone}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.timezone} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="status">Status</Label>
                                <Select
                                    name="status"
                                    defaultValue={project?.status ?? 'active'}
                                >
                                    <SelectTrigger id="status">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="paused">
                                            Paused
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    Paused projects keep their data but are
                                    skipped by scheduled work.
                                </p>
                                <InputError message={errors.status} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="default_locale">
                                    Default locale
                                </Label>
                                <Input
                                    id="default_locale"
                                    name="default_locale"
                                    value={defaultLocale}
                                    required
                                    onChange={(event) =>
                                        setDefaultLocale(event.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    BCP 47 tag, for example en or pt-PT.
                                </p>
                                <InputError message={errors.default_locale} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="locales">
                                    Additional locales
                                </Label>
                                <Input
                                    id="locales"
                                    value={extraLocales}
                                    placeholder="pt-PT, es"
                                    onChange={(event) =>
                                        setExtraLocales(event.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Comma separated. Avyo creates and measures
                                    each language separately in AI visibility,
                                    rather than treating it as a translation.
                                    Add every language you serve so an omitted
                                    one does not look like zero visibility.
                                </p>
                                <InputError message={errors['locales.0']} />
                            </div>

                            <div className="sm:col-span-2">
                                <DutyHoursField
                                    value={dutyHours}
                                    onChange={setDutyHours}
                                    timezone={timezone}
                                    // Any depth: the shape rules live on
                                    // `duty_hours.*.*.*`, so a bad pair
                                    // reports against a key nobody can point
                                    // an input at.
                                    error={
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith('duty_hours'),
                                        )?.[1]
                                    }
                                />
                            </div>

                            <div className="sm:col-span-2">
                                <FeedUrlsField
                                    value={feedUrls}
                                    onChange={setFeedUrls}
                                    // As above: the rules live on
                                    // `feed_urls.*`, so a bad address reports
                                    // against an index, not against the field.
                                    error={
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith('feed_urls'),
                                        )?.[1]
                                    }
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle>Research</CardTitle>
                            <CardDescription>
                                What Avyo researches and how much content it
                                plans. Check these settings when a project comes
                                back with too few ideas.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="market">Market</Label>
                                    <Input
                                        id="market"
                                        name="market"
                                        value={market}
                                        required
                                        onChange={(event) =>
                                            setMarket(event.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Country code the search volumes are read
                                        for, for example pt or us.
                                    </p>
                                    <InputError message={errors.market} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="weekly_target">
                                        Articles per week
                                    </Label>
                                    <Input
                                        id="weekly_target"
                                        name="weekly_target"
                                        type="number"
                                        min={1}
                                        max={14}
                                        value={weeklyTarget}
                                        required
                                        onChange={(event) =>
                                            setWeeklyTarget(event.target.value)
                                        }
                                    />
                                    <InputError
                                        message={errors.weekly_target}
                                    />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="minimum_volume">
                                        Smallest search worth writing for
                                    </Label>
                                    <Input
                                        id="minimum_volume"
                                        name="minimum_volume"
                                        type="number"
                                        min={0}
                                        placeholder="50 (installation default)"
                                        value={minimumVolume}
                                        onChange={(event) =>
                                            setMinimumVolume(event.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Monthly searches. The default suits a
                                        national market; a business serving one
                                        city usually needs 10–20, because its
                                        best keyword may only be searched 70
                                        times a month and everything real sits
                                        below that.
                                    </p>
                                    <InputError
                                        message={errors.minimum_volume}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="seed">Research seeds</Label>
                                <Chips
                                    id="seed"
                                    values={seeds}
                                    onChange={setSeeds}
                                    placeholder="cleaning lisbon"
                                />
                                {seeds.map((seed, index) => (
                                    <input
                                        key={seed}
                                        type="hidden"
                                        name={`research_seeds[${index}]`}
                                        value={seed}
                                    />
                                ))}
                                <p className="text-xs text-muted-foreground">
                                    Short search terms, two or three words —
                                    <strong> cleaning lisbon</strong>, not
                                    <strong>
                                        {' '}
                                        premium home cleaning services in Lisbon
                                    </strong>
                                    . The keyword source matches by containment,
                                    so a long phrase finds nothing and a short
                                    one finds the whole long tail around it.
                                </p>
                                <InputError
                                    message={errors['research_seeds.0']}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end border-t pt-5">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="rounded-full px-6"
                        >
                            {submitLabel}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
