<?php

namespace App\Http\Controllers;

use App\Models\StreamMarker;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Play a fetched clip file on /clips (#11). Moderators only, through the
 * route's can:moderate. The disk path is never sent: the URL names only the
 * marker and the variant.
 */
class ClipFileController extends Controller
{
    public function __invoke(StreamMarker $marker, string $variant): Response
    {
        $path = $marker->filePath($variant);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(config('clips.disk'));
        abort_unless($path !== null && $disk->exists($path), 404);

        $headers = [
            'Content-Type' => 'video/mp4',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $name = 'clip-'.$marker->id.'-'.$variant.'.mp4';

        // A local disk can answer Range requests, so the player can seek.
        // Not public: BinaryFileResponse would otherwise mark it cacheable.
        if (config('filesystems.disks.'.config('clips.disk').'.driver') === 'local') {
            $response = new BinaryFileResponse($disk->path($path), 200, $headers, false, 'inline');
            $response->setContentDisposition('inline', $name);
        } else {
            $response = $disk->response($path, $name, $headers, 'inline');
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
