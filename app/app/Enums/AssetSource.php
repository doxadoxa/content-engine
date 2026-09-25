<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a picture came from.
 *
 * Every picture the engine stores today is drawn by an image provider, so this
 * has one case. It stays a column rather than an assumption because it is the
 * one thing about an image that no later step can work out for itself: a file
 * on a disk does not say whether a machine made it or a person took it, and a
 * second source added later without this would have no way to tell its rows
 * apart from the ones already stored.
 */
enum AssetSource: string
{
    /** Drawn by an image provider from a prompt this engine wrote. */
    case Generated = 'generated';
}
