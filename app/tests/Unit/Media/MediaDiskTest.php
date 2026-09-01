<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use App\Media\MediaDisk;
use App\Media\MediaWriteFailed;
use App\Pipelines\Core\ErrorClassifier;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/*
 * A write that fails has to say so.
 *
 * Every disk is configured `throw => false` and `report => false`, so a
 * rejected write returns false and is not logged. On the container's own
 * filesystem that almost never happens, which is why the callers were written
 * to ignore the answer. A bucket refuses writes for ordinary reasons — an
 * expired key, a full quota, a bad afternoon in one region — and each caller
 * records an Asset row on the next line.
 *
 * Ignored, the row is written anyway: green job, asset present, and a URL that
 * 404s whenever somebody opens the post days later, by which time nothing
 * connects the missing picture to the write. This is the assertion that keeps
 * that failure attached to the thing that caused it.
 *
 * The second assertion is the one with teeth. Raising *something* is not enough:
 * ErrorClassifier defaults every exception it does not recognise to terminal, so
 * the obvious `RuntimeException` ends the run on the first blink from the bucket
 * — and by then the provider has already drawn the picture and charged for it.
 * The type is the behaviour here, so the type is what gets asserted.
 */
final class MediaDiskTest extends TestCase
{
    #[Test]
    public function a_refused_write_raises_rather_than_reporting_success(): void
    {
        config(['media.disk' => 'refusing']);

        // A disk that answers the way S3 does when it will not take the object.
        $refusing = $this->createMock(Filesystem::class);
        $refusing->method('put')->willReturn(false);
        Storage::set('refusing', $refusing);

        $this->expectException(MediaWriteFailed::class);
        $this->expectExceptionMessage('Could not write panels/x.png to the refusing disk.');

        MediaDisk::put('panels/x.png', 'bytes');
    }

    #[Test]
    public function the_pipeline_treats_a_refused_write_as_worth_retrying(): void
    {
        // The type carries the classification. A step run is resumable, so the
        // ladder is the right answer there — and it only gets one because this
        // extends RetryableStepFailure, which ErrorClassifier recognises. A bare
        // exception falls through to `default => false` and ends the run.
        $classified = new ErrorClassifier()->isRetryable(
            new MediaWriteFailed('Could not write panels/x.png to the s3 disk.'),
        );

        $this->assertTrue($classified);
    }

    #[Test]
    public function a_write_that_lands_returns_quietly(): void
    {
        config(['media.disk' => 'accepting']);
        Storage::fake('accepting');

        MediaDisk::put('panels/x.png', 'bytes');

        Storage::disk('accepting')->assertExists('panels/x.png');
    }

    #[Test]
    public function the_disk_name_is_what_gets_recorded_on_the_row(): void
    {
        config(['media.disk' => 's3']);

        $this->assertSame('s3', MediaDisk::name());
    }
}
