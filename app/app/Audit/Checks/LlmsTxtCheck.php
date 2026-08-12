<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\SiteCheck;
use App\Audit\SiteSignals;
use App\Enums\AuditCheckGroup;

/**
 * llms.txt: the file that tells an assistant what this site is and which pages
 * are worth reading.
 *
 * §1 of the spec names llms.txt compatibility as part of what this product is
 * differentiated on, and until now nothing in the engine could say whether a
 * project had one. Medium rather than high: a site without it is not broken,
 * it is merely leaving the introduction to be guessed at.
 *
 * A file that exists and says nothing is worse than none — it answers 200 to
 * whatever fetches it and hands back an empty introduction — so it is called
 * out separately rather than counted as present.
 */
class LlmsTxtCheck implements SiteCheck
{
    /**
     * Below this it is a placeholder rather than a document. A real llms.txt
     * has at least a title line and a sentence.
     */
    private const int MINIMUM_USEFUL_LENGTH = 40;

    public static function key(): string
    {
        return 'llms_txt';
    }

    public function label(): string
    {
        return 'LLMs.txt';
    }

    public function description(): string
    {
        return 'Checks whether llms.txt is present and actually introduces the site to AI assistants.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Geo;
    }

    /** @return list<CheckFinding> */
    public function run(SiteSignals $signals): array
    {
        if (! $signals->hasLlms()) {
            return [CheckFinding::medium(
                'There is no llms.txt, so assistants have no summary of the site to work from.',
                ['status' => $signals->llmsStatus],
            )];
        }

        $body = trim((string) $signals->llmsBody);

        if (mb_strlen($body) < self::MINIMUM_USEFUL_LENGTH) {
            return [CheckFinding::medium(
                'llms.txt is present but nearly empty.',
                ['length' => mb_strlen($body)],
            )];
        }

        // The convention is a markdown document that opens with the site's
        // name. One without a heading still parses, but nothing reading it can
        // tell which site it belongs to.
        if (preg_match('/^\s*#\s+\S/m', $body) !== 1) {
            return [CheckFinding::low('llms.txt has no heading naming the site.')];
        }

        return [];
    }
}
