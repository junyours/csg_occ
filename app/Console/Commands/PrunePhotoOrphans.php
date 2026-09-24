<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * `migrate:fresh` truncates the students table but never touches
 * storage/app/public — any photo file left behind by a student who no
 * longer exists in the DB (most commonly after a dev-machine
 * migrate:fresh) becomes permanently unreferenced disk space. This
 * doesn't affect correctness — UpdateStudentPhoto already guarantees a
 * student has at most one live photo_path at a time — it's purely
 * cleanup for stray files nothing points at anymore.
 *
 * Usage:
 *   php artisan photos:prune-orphans          # dry run, lists what would go
 *   php artisan photos:prune-orphans --force  # actually deletes them
 */
class PrunePhotoOrphans extends Command
{
    protected $signature = 'photos:prune-orphans {--force : Actually delete the files instead of just listing them}';

    protected $description = 'Delete student photo files on the public disk that no student currently references';

    public function handle(): int
    {
        $referenced = Student::query()
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->all();

        $referenced = array_flip($referenced);

        $onDisk = Storage::disk('public')->files('photos');

        $orphans = array_values(array_filter(
            $onDisk,
            fn (string $path) => ! isset($referenced[$path]),
        ));

        if ($orphans === []) {
            $this->info('No orphaned photo files found.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Found %d orphaned photo file(s):', count($orphans)));
        foreach ($orphans as $path) {
            $this->line("  - {$path}");
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->comment('Dry run — re-run with --force to delete these.');

            return self::SUCCESS;
        }

        Storage::disk('public')->delete($orphans);
        $this->info(sprintf('Deleted %d orphaned photo file(s).', count($orphans)));

        return self::SUCCESS;
    }
}
