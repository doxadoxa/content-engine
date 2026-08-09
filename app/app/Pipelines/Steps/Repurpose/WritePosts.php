<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Enums\ChannelType;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * One post per connected social channel (§8.1, §8.2).
 *
 * The format instruction comes from the brief and a per-channel shape, not from
 * a hardcoded prompt in this step — §8.1 is explicit that "форматные промпты по
 * каналам — из Brief, а не хардкодом в шаге", which is what lets a third
 * channel be a config entry rather than a code change.
 */
class WritePosts extends AbstractStep
{
    public static function key(): string
    {
        return 'write_posts';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [LinkInternally::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $parent = $context->output(LoadParent::key(), ParentPayload::class);
        $links = $context->output(LinkInternally::key(), LinksPayload::class);

        $posts = [];

        foreach ($parent->channels as $channel) {
            $type = ChannelType::tryFrom($channel);

            if ($type === null) {
                continue;
            }

            $answer = $context->ask(
                role: 'draft',
                prompt: implode("\n\n", [
                    "The article:\n{$parent->markdown}",
                    // Inherited, and stated as a constraint: §8.1 asks for one
                    // voice, and a derivative that introduces a new subject is
                    // not a derivative.
                    'Cover these and nothing else: '.implode(', ', $parent->entities),
                    $links->links === []
                        ? 'Link only to the article itself.'
                        : 'The article also points at: '
                            .implode(', ', array_map(
                                static fn (array $link): string => $link['anchor'],
                                $links->links,
                            )),
                    $this->format($type),
                ]),
                instructions: $parent->compiledBrief
                    ."\n\nYou are writing a social post in {$parent->locale} derived from an article we published. "
                    .'It must not state anything the article does not.',
            );

            $posts[$channel] = trim($answer->text);
        }

        return StepResult::success(new DerivativesPayload($posts));
    }

    /**
     * The channel's shape, from config.
     *
     * A new channel is a line in `config/publishing.php` plus an enum case, and
     * no change here.
     */
    private function format(ChannelType $type): string
    {
        /** @var array<string, string> $formats */
        $formats = config('publishing.formats', []);

        return $formats[$type->value] ?? 'Write one short post.';
    }
}
