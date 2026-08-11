<?php

declare(strict_types=1);

namespace App\Media;

use App\Enums\AssetRole;
use App\Enums\AssetSource;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Support\Social\ChannelPlaybook;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A photograph somebody took, made into a candidate for a post.
 *
 * The cheapest treatment in the studio and the one that answers the complaint
 * the studio exists for: a real picture of the real work is the only image a
 * competitor cannot also produce, and it costs nothing to use. No provider, no
 * prompt, no model.
 *
 * **It is cropped rather than accepted as-is.** Every other picture on a post
 * is rendered to the channel's own ratio — 4:5 on Instagram, square on Threads,
 * 16:9 on X — and an upload that keeps whatever shape the operator's phone
 * produced would be the one image in the set the feed letterboxes. Cropping is
 * the same decision applied to a different source, not a liberty taken with
 * somebody's photograph.
 *
 * **Centre crop, and that is a real limitation stated rather than hidden.** The
 * subject of a photograph is not always in the middle of it, and this will
 * sometimes cut the wrong edge. Fixing it properly means an operator dragging a
 * crop box, which is a screen rather than a rule; until that exists a candidate
 * cropped slightly wrong can be looked at and rejected, which is what the
 * candidate strip is for.
 *
 * **Re-encoded to WebP** so an upload and a generation are the same kind of file
 * downstream — the publish adapters, the previews and the storage accounting all
 * stop needing to care where a picture came from at the moment it is stored.
 */
class UploadedPicture
{
    /**
     * Anything below this in either dimension is not a photograph worth
     * publishing, whatever it is. Small enough to admit a screenshot, large
     * enough to refuse an icon somebody dragged in by accident.
     */
    public const int MIN_EDGE = 400;

    public function store(ContentItem $item, UploadedFile $file, ChannelPlaybook $playbook): Asset
    {
        $source = $this->read($file);

        [$width, $height] = [imagesx($source), imagesy($source)];

        if (min($width, $height) < self::MIN_EDGE) {
            imagedestroy($source);

            throw new RuntimeException(
                sprintf('That picture is %d×%d. Both sides need to be at least %d.', $width, $height, self::MIN_EDGE),
            );
        }

        $canvas = $this->crop($source, $playbook->imageWidth, $playbook->imageHeight);
        imagedestroy($source);

        $path = 'uploads/'.Str::random(24).'.webp';

        ob_start();
        imagewebp($canvas, null, 90);
        $bytes = (string) ob_get_clean();

        imagedestroy($canvas);

        Storage::disk((string) config('media.disk', 'public'))->put($path, $bytes);

        return Asset::query()->create([
            'content_item_id' => $item->getKey(),
            'role' => AssetRole::Variant,
            'source' => AssetSource::Uploaded,
            'anchor' => null,
            'disk' => (string) config('media.disk', 'public'),
            'path' => $path,
            // The operator's own filename, which is the only description of the
            // picture anybody has supplied. Better than the post's first line,
            // which describes the text rather than the image.
            'alt' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 255, ''),
            'width' => imagesx($canvas),
            'height' => imagesy($canvas),
        ]);
    }

    /**
     * The uploaded bytes as an image, whatever the browser called them.
     *
     * Decoded from the content rather than trusted from the extension: a `.jpg`
     * that is not a JPEG is either a mistake or an attempt, and both are the
     * same answer here.
     */
    private function read(UploadedFile $file): GdImage
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('That file is not an image this engine can read.');
        }

        return $image;
    }

    /**
     * Fill the channel's frame from the middle of the picture.
     *
     * Cover rather than contain: a letterboxed photograph with bars down the
     * sides is worse in a feed than a photograph missing its edges, and the bars
     * are the thing that reads as a mistake.
     *
     * Never enlarged past its own resolution. An upload smaller than the
     * channel's frame is copied at the size it has, because upscaling produces a
     * soft picture that looks like a bad generation — which is the impression
     * this whole treatment exists to avoid.
     */
    private function crop(GdImage $source, int $width, int $height): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $scale = min($sourceWidth / $width, $sourceHeight / $height);
        $takeWidth = (int) round($width * $scale);
        $takeHeight = (int) round($height * $scale);

        $target = $scale < 1.0
            ? [(int) round($width * $scale), (int) round($height * $scale)]
            : [$width, $height];

        $canvas = imagecreatetruecolor(max(1, $target[0]), max(1, $target[1]));

        if ($canvas === false) {
            throw new RuntimeException('The picture could not be prepared for this channel.');
        }

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            (int) round(($sourceWidth - $takeWidth) / 2),
            (int) round(($sourceHeight - $takeHeight) / 2),
            max(1, $target[0]),
            max(1, $target[1]),
            max(1, $takeWidth),
            max(1, $takeHeight),
        );

        return $canvas;
    }
}
