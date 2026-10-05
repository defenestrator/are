<?php

namespace App\Clips;

use App\Models\StreamMarker;
use App\Readiness\Check;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * How much of CLIPS_DISK the clip files use (#143), for /clips and the
 * readiness page.
 *
 * The stored total comes from stream_markers.file_bytes, which FetchClipFile
 * records and clips:prune-files clears, so no request walks the disk. Free
 * space is known only for a local disk.
 */
class ClipStorage
{
    /**
     * @return array{bytes: int, clips: int, free_bytes: int|null, disk: string}
     */
    public static function usage(): array
    {
        $stored = StreamMarker::whereNull('files_pruned_at')
            ->where(fn ($q) => $q->whereNotNull('landscape_file_path')->orWhereNotNull('portrait_file_path')->orWhereNotNull('short_file_path'));

        return [
            'bytes' => (int) (clone $stored)->sum('file_bytes') + (int) (clone $stored)->sum('short_bytes'),
            'clips' => (clone $stored)->count(),
            'free_bytes' => self::freeBytes(),
            'disk' => (string) config('clips.disk'),
        ];
    }

    /** Free space on a local CLIPS_DISK, or null for a remote disk or if unknown. */
    public static function freeBytes(): ?int
    {
        $disk = (string) config('clips.disk');
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return null;
        }

        $root = Storage::disk($disk)->path('');
        $free = is_dir($root) ? @disk_free_space($root) : false;

        return $free === false ? null : (int) $free;
    }

    /** The readiness line: amber above disk_warn_bytes stored, or below disk_min_free_bytes free. */
    public static function readinessCheck(): Check
    {
        $name = 'Clip file storage';
        $usage = self::usage();
        $warnAt = (int) config('clips.disk_warn_bytes');
        $minFree = (int) config('clips.disk_min_free_bytes');

        $summary = Number::fileSize($usage['bytes'], 1).' in '.$usage['clips'].' clip(s) on the '.$usage['disk'].' disk'
            .($usage['free_bytes'] !== null ? ', '.Number::fileSize($usage['free_bytes'], 1).' free.' : '.');
        $retention = 'Files of rejected clips go after '.config('clips.keep_rejected_days').' days, approved but unpublished after '.config('clips.keep_approved_days').'.';

        $problems = array_values(array_filter([
            $usage['bytes'] > $warnAt ? 'Clip files exceed CLIPS_DISK_WARN_BYTES ('.Number::fileSize($warnAt, 1).').' : null,
            $usage['free_bytes'] !== null && $usage['free_bytes'] < $minFree ? 'Free space is below CLIPS_DISK_MIN_FREE_BYTES ('.Number::fileSize($minFree, 1).').' : null,
        ]));

        if ($problems === []) {
            return Check::ok($name, $summary, [$retention]);
        }

        return Check::warn(
            $name,
            $summary.' '.implode(' ', $problems),
            'Review the clips waiting on /clips (pending clips are never pruned), lower CLIPS_KEEP_REJECTED_DAYS or CLIPS_KEEP_APPROVED_DAYS, or add disk space. Run php artisan clips:prune-files --dry-run to see what would go.',
            [$retention],
        );
    }
}
