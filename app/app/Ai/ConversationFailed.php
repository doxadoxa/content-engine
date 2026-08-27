<?php

declare(strict_types=1);

namespace App\Ai;

use RuntimeException;
use Throwable;

/**
 * A turn that broke partway, carrying whatever it had already done — and,
 * since the meter went in, whatever it had already spent.
 *
 * **The tool calls are the point of this class.** A turn is a loop: the model
 * reaches for tools, they run, and then it is asked once more for the words to
 * say about them. If that last request is the one that fails, the work has
 * already happened — an article is written and queued, a month is being planned
 * — and a plain failure would throw the receipts away with the error. The
 * operator would be told the turn did not finish, see no record of anything,
 * and ask again; the second ask starts the work a second time.
 *
 * So the failure carries what completed, and {@see Assistant\Assistant} writes
 * those rows before it writes the apology.
 *
 * The usage is the same argument applied to money. The tokens that bought the
 * tool calls were spent whether or not the last leg came back, and a cost
 * ceiling that only counts turns which succeeded is a ceiling a failing
 * provider can be walked straight through. It is nullable because a failure
 * before the first request really did cost nothing, and zero is a different
 * claim from "not measured".
 */
final class ConversationFailed extends RuntimeException
{
    /**
     * @param  list<array{name: string, arguments: array<string, mixed>, result: mixed}>  $toolCalls
     */
    public function __construct(
        string $message,
        public readonly array $toolCalls = [],
        ?Throwable $previous = null,
        public readonly ?ConversationUsage $usage = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
