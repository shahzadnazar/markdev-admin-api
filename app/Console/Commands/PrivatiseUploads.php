<?php

namespace App\Console\Commands;

use App\Support\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Move already-uploaded sensitive files off the public disk.
 *
 * Every upload used to land on `public` and be served straight off the
 * filesystem by the storage symlink — measured: an assignment submission and a
 * student's photo both returned 200 with no session. New uploads go to the
 * private disk from this release; this moves the ones already there.
 *
 * THE STORED PATHS DO NOT CHANGE. A path like `students/documents/ab12.pdf`
 * names the same relative location on either disk, so nothing in the database
 * is rewritten — which is what makes this safe to interrupt and safe to re-run.
 * A half-finished run leaves some files on one disk and some on the other, and
 * both are found: the accessors read the private disk, and PrivateFiles::forget
 * deletes from both.
 *
 * Resumable by construction. Every file is handled in the order copy → verify →
 * delete the public copy, and a file already present and identical on the
 * private disk is treated as done rather than copied again. Re-running after a
 * complete run reports everything as already-private and changes nothing.
 *
 * Verification is a byte-for-byte hash, not a size check. Truncated copies are
 * the failure mode that matters here: a size comparison passes on a file whose
 * middle is wrong, and the original is about to be deleted.
 */
class PrivatiseUploads extends Command
{
    protected $signature = 'files:privatise
        {--dry-run : Report what would move without touching anything}
        {--keep-public : Copy and verify, but do not delete the public originals}';

    protected $description = 'Move sensitive uploads from the public disk to the private one';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keepPublic = (bool) $this->option('keep-public');

        $public = Storage::disk('public');
        $private = Storage::disk(PrivateFiles::DISK);

        $totals = ['moved' => 0, 'already' => 0, 'failed' => 0];
        $rows = [];

        foreach (PrivateFiles::PRIVATE_PREFIXES as $prefix) {
            $files = $public->allFiles($prefix);
            $counts = ['moved' => 0, 'already' => 0, 'failed' => 0];

            foreach ($files as $path) {
                // Already there and identical: a previous run got this far.
                if ($private->exists($path) && $this->sameBytes($public, $private, $path)) {
                    $counts['already']++;

                    if (! $dryRun && ! $keepPublic) {
                        $public->delete($path);
                    }

                    continue;
                }

                if ($dryRun) {
                    $counts['moved']++;

                    continue;
                }

                $stream = $public->readStream($path);

                if ($stream === false || $stream === null) {
                    $this->warn("  unreadable, left alone: {$path}");
                    $counts['failed']++;

                    continue;
                }

                $private->writeStream($path, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                // Verify BEFORE deleting anything. A copy that did not land is
                // recoverable while the original is still there and is not a
                // moment later.
                if (! $private->exists($path) || ! $this->sameBytes($public, $private, $path)) {
                    $this->error("  copy did not verify, public copy kept: {$path}");
                    $counts['failed']++;

                    continue;
                }

                if (! $keepPublic) {
                    $public->delete($path);
                }

                $counts['moved']++;
            }

            $rows[] = [$prefix, count($files), $counts['moved'], $counts['already'], $counts['failed']];

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
        }

        $this->table(['kind', 'found on public', 'moved', 'already private', 'failed'], $rows);

        $orphans = $this->reportOrphans();

        if ($dryRun) {
            $this->info('Dry run — nothing was copied or deleted.');
        } else {
            $this->info("Moved {$totals['moved']}, already private {$totals['already']}, failed {$totals['failed']}, paths with no file {$orphans}.");
        }

        // A failed copy is worth a non-zero exit so a deploy step notices;
        // an orphaned path is a data observation, not a failure of this run.
        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Stored paths with nothing behind them, on either disk.
     *
     * Reported rather than fixed, and never fatal: a row pointing at a file
     * that was deleted outside the app is a data question for a human, and
     * failing the whole run over one would leave every other file unmoved.
     */
    protected function reportOrphans(): int
    {
        $columns = [
            [\App\Models\StudentProfile::class, 'photo_path'],
            [\App\Models\StudentProfile::class, 'cnic_doc_path'],
            [\App\Models\StudentProfile::class, 'degree_doc_path'],
            [\App\Models\AssignmentSubmission::class, 'file_path'],
            [\App\Models\AssignmentAttachment::class, 'file_path'],
            [\App\Models\Transaction::class, 'receipt_path'],
            [\App\Models\Note::class, 'file_path'],
            [\App\Models\LessonResource::class, 'file_path'],
        ];

        $private = Storage::disk(PrivateFiles::DISK);
        $public = Storage::disk('public');
        $found = 0;

        foreach ($columns as [$model, $column]) {
            $model::query()->whereNotNull($column)->where($column, '!=', '')
                ->select(['id', $column])->chunkById(200, function ($rows) use ($column, $private, $public, $model, &$found) {
                    foreach ($rows as $row) {
                        $path = $row->{$column};

                        if ($private->exists($path) || $public->exists($path)) {
                            continue;
                        }

                        $found++;
                        $this->warn(sprintf(
                            '  no file behind %s#%d %s = %s',
                            class_basename($model), $row->id, $column, $path,
                        ));
                    }
                });
        }

        return $found;
    }

    /** Byte-for-byte, because a size match is not a copy. */
    protected function sameBytes($from, $to, string $path): bool
    {
        return $from->checksum($path, ['checksum_algo' => 'md5'])
            === $to->checksum($path, ['checksum_algo' => 'md5']);
    }
}
