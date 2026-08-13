<?php

declare(strict_types=1);

namespace App\Audit\Contracts;

use App\Enums\AuditCheckGroup;
use App\Pipelines\Contracts\Step;

/**
 * One question the audit asks about a site.
 *
 * A check knows its own name, what it is for and which score it feeds. It does
 * not know what a sweep is, which page it is looking at next, whether it has
 * run before, or that a database exists — the steps handle all of that. Same
 * bargain as {@see Step}, and for the same payoff: a
 * check is testable by calling it with one fixture and nothing else running.
 *
 * Adding one is a class plus a line in `config/audit.php`. The screen's list of
 * "what we check" is generated from the registry, so a new check appears there
 * without anybody editing the frontend — a list of checks maintained in two
 * places is a list that disagrees with itself by the second release.
 */
interface Check
{
    /** Stable across renames of the class: it is stored on every issue row. */
    public static function key(): string;

    /** Short, and in the operator's vocabulary. Shown as the issue's title. */
    public function label(): string;

    /** One sentence: what this check looks at and why it matters. */
    public function description(): string;

    public function group(): AuditCheckGroup;
}
