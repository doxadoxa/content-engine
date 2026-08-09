<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Console\Commands\EngineTickCommand;
use App\Media\HeroImage;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Repurpose\HeroPayload;
use App\Support\Content\SafeMarkdown;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The article's pictures (§5, §8.3).
 *
 * A hero, and one inside the body after each of the first few sections. One
 * picture over two thousand words reads as a wall however good the prose is;
 * a comparable published article carries four, and that is the number a reader
 * scrolls past without noticing the length.
 *
 * In generation and not only in repurpose, because an article without an image
 * is not a finished article — it was reaching the approvals queue as a wall of
 * text, and the only way to get a picture was to run a second pipeline over it
 * by hand.
 *
 * Runs after the draft is saved and edits the body afterwards: the images have
 * to go under headings that exist, and the headings are only settled once the
 * draft is written.
 *
 * Skipped rather than failed when no provider is configured: a project with no
 * image key should still get its writing.
 *
 * A locale that is not the first of its unit to be written pays nothing here.
 * {@see HeroImage} borrows the hero and each section picture from the sibling
 * that ran first, so the Portuguese, English and Russian articles about one
 * subject illustrate as one set rather than as three. What makes that reachable
 * rather than theoretical is {@see EngineTickCommand::leadLocales()},
 * which stopped the three runs starting in the same second.
 */
class IllustrateDraft extends AbstractStep
{
    use ResolvesUnit;

    /** How many inline pictures were placed, and why it stopped if it stopped early. */
    private int $placed = 0;

    private int $wanted = 0;

    private ?string $shortfall = null;

    public function __construct(
        private readonly HeroImage $hero,
        private readonly SafeMarkdown $markdown,
    ) {}

    public static function key(): string
    {
        return 'illustrate_draft';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        // After the draft is written, because the prompt is built from the
        // title and summary that step settles.
        return [FinaliseDraft::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $this->hero->isConfigured()) {
            return StepResult::skip('No image provider is configured for this project.');
        }

        $unit = $this->unit($context);

        try {
            $made = $this->hero->for($unit, $unit->title, $unit->summary);
        } catch (TerminalStepFailure $e) {
            // A provider that is configured and will not draw is the same
            // situation as one that was never configured, and this step already
            // says what to do about that: the writing still ships. An empty
            // image balance failing the run leaves a finished 2,200-word
            // article — scored, publishable — marooned behind a red run because
            // nobody topped up a picture account.
            //
            // Only terminal failures. A timeout or a 500 is worth the retry the
            // engine would otherwise have spent.
            $context->remember('media.stopped_because', $e->getMessage());

            return StepResult::skip("The image provider refused: {$e->getMessage()}");
        }

        if ($made === null) {
            return StepResult::skip('No image provider is configured for this project.');
        }

        $cost = $made['cost'] + $this->illustrateSections($unit, $made['asset']->url());

        // An article that came out with a hero and nothing else is not a
        // slightly worse article, it is the visible symptom of a provider that
        // has stopped drawing — and until now the only trace was a log line on
        // a container's stderr. Every article after this one has the same
        // problem, so the run says so where the operator already looks.
        //
        // Only when it *stopped*, not whenever the count is short. A four-part
        // article has three places to put a picture and one of them is under
        // the hero; reporting that as a shortfall would put a note on almost
        // every run, and a field that is always there is a field nobody reads.
        if ($this->shortfall !== null) {
            $context->remember('media.inline_placed', $this->placed);
            $context->remember('media.inline_wanted', $this->wanted);
            $context->remember('media.inline_stopped_because', $this->shortfall);
        }

        return StepResult::success(new HeroPayload($made['asset']->getKey(), $cost));
    }

    /**
     * A picture under each of the first few section headings.
     *
     * The first section is skipped: the hero already sits directly above it,
     * and two images with a paragraph between them is worse than one.
     */
    private function illustrateSections(ContentItem $unit, string $heroUrl): int
    {
        $wanted = (int) config('media.inline.count', 3);

        if ($wanted < 1) {
            return 0;
        }

        $headings = $this->headings((string) $unit->body_markdown);

        if ($headings === []) {
            return 0;
        }

        $markdown = (string) $unit->body_markdown;
        $cost = 0;
        $placed = 0;

        // `array_slice` reindexes, so the key is which illustrated section this
        // is — counted from the second heading, since the first never gets one.
        foreach (array_slice($headings, 1) as $position => $heading) {
            if ($placed >= $wanted) {
                break;
            }

            // A picture that will not draw must not lose an article that is
            // already written and paid for. The hero is the one that matters;
            // these are decoration, and the step reports what it managed.
            try {
                $made = $this->hero->inline(
                    $unit,
                    $heading,
                    (string) ($unit->target_query ?? $unit->title),
                    // The reference is only useful if the provider can fetch it.
                    // On a developer's machine the disk URL is localhost, and
                    // passing it makes every generation fail on a connection the
                    // vendor cannot open — so the set loses its visual consistency
                    // there rather than losing its images.
                    references: $this->isPubliclyFetchable($heroUrl) ? [$heroUrl] : [],
                    // Which illustrated section this is, so another locale of
                    // the same unit can hand back the picture it put here
                    // rather than paying to draw the subject again. Counted
                    // over the headings that get pictures, not over all of
                    // them, so it lines up with the sibling's set even when the
                    // two articles opened differently.
                    position: $position,
                );

                if ($made === null) {
                    break;
                }

                $markdown = $this->insertAfterHeading(
                    $markdown,
                    $heading,
                    '!['.$heading.']('.$made['asset']->url().')',
                );

                $cost += $made['cost'];
                $placed++;
            } catch (Throwable $e) {
                Log::warning('An inline illustration could not be made', [
                    'unit' => $unit->slug,
                    'heading' => $heading,
                    'reason' => $e->getMessage(),
                ]);

                $this->shortfall = $e->getMessage();

                break;
            }
        }

        if ($placed > 0) {
            $unit->forceFill([
                'body_markdown' => $markdown,
                'body_html' => $this->markdown->render($markdown),
            ])->save();
        }

        $this->placed = $placed;
        $this->wanted = $wanted;

        return $cost;
    }

    /**
     * Whether an outside service could actually fetch this URL.
     *
     * Loopback and private ranges cannot be reached from anybody else's
     * network, and handing one to an image provider fails the whole step on a
     * connection refused.
     */
    private function isPubliclyFetchable(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            // A name that does not resolve here may still resolve out there.
            return true;
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }

    /**
     * The `##` headings, in the order they appear.
     *
     * Read off the body rather than off the outline: the writer is asked for
     * those sections and does not always produce exactly them, and a picture
     * has to go under a heading that is actually there.
     *
     * @return list<string>
     */
    private function headings(string $markdown): array
    {
        preg_match_all('/^##\s+(.+?)\s*$/mu', $markdown, $matches);

        return array_values(array_filter(array_map(trim(...), $matches[1])));
    }

    /**
     * Put the image on its own line under the heading.
     *
     * Matched on the heading text rather than by position, so a body that gained
     * a line since it was read still gets the picture in the right place.
     */
    private function insertAfterHeading(string $markdown, string $heading, string $image): string
    {
        $pattern = '/^(##\s+'.preg_quote($heading, '/').'\s*)$/mu';

        $replaced = preg_replace($pattern, '$1'."\n\n".$image, $markdown, 1);

        return $replaced ?? $markdown;
    }
}
