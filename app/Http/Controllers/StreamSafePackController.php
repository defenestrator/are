<?php

namespace App\Http\Controllers;

use App\Models\Track;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public stream-safe pack. Only stream-safe tracks resolve; everything
 * else is a 404, so the catalogue's other tracks are not even confirmed to
 * exist. Files stream from the private music disk and the disk path is never
 * exposed.
 */
class StreamSafePackController extends Controller
{
    public function index(): View
    {
        return view('music.index', [
            'tracks' => Track::streamSafe()->orderBy('artist')->orderBy('title')->get(),
        ]);
    }

    public function download(int $track): StreamedResponse
    {
        $track = Track::streamSafe()->findOrFail($track);

        return $this->send($track->file_path, $track->downloadName($track->file_path));
    }

    public function stems(int $track): StreamedResponse
    {
        $track = Track::streamSafe()->whereNotNull('stems_path')->findOrFail($track);

        return $this->send($track->stems_path, $track->downloadName($track->stems_path, 'stems'));
    }

    private function send(string $path, string $name): StreamedResponse
    {
        $disk = Storage::disk(config('music.disk'));
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $name);
    }
}
