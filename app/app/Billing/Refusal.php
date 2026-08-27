<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Why the engine will not spend for this project.
 *
 * Every refusal has to survive the trip to a screen with its reason intact,
 * because the reasons need different buttons under them. "Your trial ended" is
 * a pricing page; "your card failed" is the billing portal; "you have used this
 * month's articles" is neither, and offering an upgrade to somebody whose card
 * bounced is close to insulting.
 *
 * {@see Metric} names which quota ran out, and is null for the reasons that are
 * not about quota at all.
 */
final readonly class Refusal
{
    private function __construct(
        public string $code,
        public string $message,
        public ?Metric $metric = null,
    ) {}

    /**
     * No card yet, which is where every new project starts.
     *
     * Worded as the next step rather than as a fault. This fires for a project
     * whose wizard is finished and whose checkout was abandoned or never
     * reached — somebody halfway through signing up, not somebody who has done
     * anything wrong.
     */
    public static function noSubscription(): self
    {
        return new self(
            'no_subscription',
            'Add a card to start the engine. Nothing is charged today.',
        );
    }

    public static function trialEnded(): self
    {
        return new self(
            'trial_ended',
            'The trial has ended. Choose a plan to start the engine again.',
        );
    }

    public static function canceled(): self
    {
        return new self(
            'canceled',
            'This project’s subscription has ended. Everything it made stays readable.',
        );
    }

    public static function pastDue(): self
    {
        return new self(
            'past_due',
            'The last payment did not go through. Approved work is still being published.',
        );
    }

    public static function quota(Metric $metric): self
    {
        return new self(
            'quota',
            "This period’s {$metric->label()} are used up.",
            $metric,
        );
    }

    /**
     * The invisible one.
     *
     * A customer never asked for a cost ceiling and should not be told they hit
     * one in those words — from where they stand this plan is a number of
     * articles, and a second limit they were never sold reads as a bait and
     * switch. So the sentence is about us looking at it, which is true: this
     * trips on a retry storm or a mispriced model far more often than on
     * legitimate use, and both are ours to fix.
     */
    public static function costCeiling(): self
    {
        return new self(
            'cost_ceiling',
            'This project is paused while we check unusually heavy usage. Nothing has been lost.',
        );
    }

    /** @return array{code: string, message: string, metric: string|null} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'metric' => $this->metric?->value,
        ];
    }
}
