<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a project is between "somebody typed a URL" and "the engine is running".
 *
 * A project exists from the first step, because the analysis and the wizard's
 * answers have to live somewhere and a half-filled form in a browser tab is not
 * somewhere. What the status decides is whether the rest of the application
 * treats it as real.
 */
enum OnboardingStatus: string
{
    /** Created, wizard in progress. Not yet a project anything runs against. */
    case Draft = 'draft';

    /** Reading the site. The wizard waits on this and shows it happening. */
    case Analysing = 'analysing';

    /** The wizard finished. Pipelines are starting. */
    case Launching = 'launching';

    /** Running normally. */
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Setting up',
            self::Analysing => 'Reading your site',
            self::Launching => 'Starting up',
            self::Active => 'Active',
        };
    }

    /** Whether the engine should be doing work for this project. */
    public function isLive(): bool
    {
        return $this === self::Launching || $this === self::Active;
    }
}
