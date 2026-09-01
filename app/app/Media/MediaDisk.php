<?php

declare(strict_types=1);

namespace App\Media;

use App\Pipelines\Exceptions\RetryableStepFailure;
use Illuminate\Support\Facades\Storage;

/**
 * The disk generated and uploaded images are written to, and the one thing
 * every writer has to do about the answer.
 *
 * Every disk in config/filesystems.php is configured `throw => false` and
 * `report => false`, so a rejected write returns false and says nothing at all
 * — not even to the log. Against the container's own filesystem that is close
 * to unreachable, and the callers here were written against that disk. A
 * bucket refuses writes for entirely ordinary reasons: a key that expired, a
 * quota reached, a region having a bad afternoon.
 *
 * Each caller records an Asset row immediately after writing. Discarding the
 * result meant the row was written regardless — the job finished green, the
 * asset existed, and the URL on it 404'd whenever somebody eventually opened
 * the post. The failure surfaced days later as a missing picture, at which
 * point nothing connects it to the write that never happened.
 *
 * Failing loudly puts it back where it can be seen, and no row gets written
 * describing a file that is not there.
 *
 * `RetryableStepFailure` and not a plain `RuntimeException`, which is what this
 * threw first and was wrong: ErrorClassifier defaults anything it does not
 * recognise to terminal, so a blink from the bucket ended the run rather than
 * taking the retry ladder. It matters most where it costs most — IllustrateDraft
 * catches only `TerminalStepFailure` around the hero, so a write that failed
 * after the provider had already drawn and been paid for threw the picture away
 * along with the run.
 *
 * A `false` cannot tell a bad afternoon from a wrong bucket name, so this
 * classifies every refusal as worth retrying. That is the right way round here:
 * the expensive half already happened by the time the write is attempted, a
 * misconfigured bucket fails the ladder and lands terminal a few minutes later
 * anyway, and only one of those two mistakes throws away work somebody paid for.
 */
final class MediaDisk
{
    /** The configured disk name, which is also what gets recorded on the row. */
    public static function name(): string
    {
        return (string) config('media.disk', 'public');
    }

    public static function put(string $path, string $bytes): void
    {
        $disk = self::name();

        // Strictly false: `put` answers bool here, but returns the generated
        // path in its other form, and a truthy-check would be wrong the day
        // somebody calls that one.
        if (Storage::disk($disk)->put($path, $bytes) === false) {
            throw new RetryableStepFailure("Could not write {$path} to the {$disk} disk.");
        }
    }
}
