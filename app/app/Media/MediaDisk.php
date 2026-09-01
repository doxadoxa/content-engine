<?php

declare(strict_types=1);

namespace App\Media;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
 * Failing loudly puts it back where it can be seen: image work runs in a queue,
 * so the job fails in Horizon, which is visible and retryable, and no row is
 * written describing a file that is not there.
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
            throw new RuntimeException("Could not write {$path} to the {$disk} disk.");
        }
    }
}
