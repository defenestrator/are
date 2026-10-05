<?php

namespace App\Console\Commands;

use App\Models\StreamMarker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Delete the files of decided clips past their retention (#143). Scheduled
 * daily in routes/console.php.
 *
 * Only the files go: the row stays, with its paths cleared and
 * files_pruned_at set, so /clips still lists the clip and its decisions.
 * Each row is locked and checked again just before its files are deleted, so
 * a moderator's decision made during the run wins. A clip still to review is
 * never selected. Running it again deletes nothing more.
 */
class PruneClipFiles extends Command
{
    protected $signature = 'clips:prune-files {--dry-run : List what would be deleted, and delete nothing}';

    protected $description = 'Delete the files of rejected clips, and of approved clips not yet published, past their retention';

    public function handle(): int
    {
        $disk = Storage::disk(config('clips.disk'));
        $dryRun = (bool) $this->option('dry-run');
        $pruned = 0;
        $bytes = 0;

        StreamMarker::prunableFiles()->select('id')->chunkById(100, function ($markers) use ($disk, $dryRun, &$pruned, &$bytes) {
            foreach ($markers as $candidate) {
                DB::transaction(function () use ($candidate, $disk, $dryRun, &$pruned, &$bytes) {
                    $marker = StreamMarker::whereKey($candidate->id)->lockForUpdate()->first();

                    // The decision may have changed since the query: re-check under the lock.
                    if ($marker === null || ! $marker->filesArePrunable()) {
                        return;
                    }

                    $paths = array_values(array_filter(array_map(fn ($variant) => $marker->filePath($variant), StreamMarker::VARIANTS)));
                    $size = (int) $marker->file_bytes;

                    if ($dryRun) {
                        $this->line("Would delete clip {$marker->id} ({$marker->review_status->value}, decided {$marker->reviewed_at?->toDateString()}): ".count($paths).' file(s), '.Number::fileSize($size, 1));
                    } else {
                        // Missing files are fine: the goal is that they are gone.
                        $disk->delete($paths);
                        $marker->update([
                            'landscape_file_path' => null,
                            'portrait_file_path' => null,
                            'file_bytes' => null,
                            'files_pruned_at' => now(),
                        ]);
                    }

                    $pruned++;
                    $bytes += $size;
                });
            }
        });

        $this->info(($dryRun ? 'Would free ' : 'Freed ').Number::fileSize($bytes, 1)." from {$pruned} clip(s).");

        return self::SUCCESS;
    }
}
