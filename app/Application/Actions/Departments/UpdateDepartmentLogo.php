<?php

namespace App\Application\Actions\Departments;

use App\Models\Department;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Throwable;

final class UpdateDepartmentLogo
{
    private const DISK = 'public';

    private const DIRECTORY = 'department-logos';

    // Logos are simple marks, not photos — 800px on the longest edge
    // (same cap Student photos use) is already generous for anything
    // this app displays them at.
    private const MAX_DIMENSION = 800;

    private const PNG = 'png';

    public function __construct(
        private readonly ImageManager $images,
    ) {}

    /**
     * Re-upload replaces — never accumulates — a department's logo, same
     * ordering guarantee as UpdateStudentPhoto: the new file is written
     * before the DB update so a failed update can't leave the department
     * pointing at a logo_path that doesn't exist, and the old file is
     * only removed after the update commits.
     */
    public function __invoke(Department $department, UploadedFile $file): Department
    {
        $oldPath = $department->logo_path;
        $newPath = $this->storeCompressed($file, $department);

        try {
            DB::transaction(function () use ($department, $newPath) {
                $department->update(['logo_path' => $newPath]);
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($newPath);

            throw $e;
        }

        if ($oldPath && $oldPath !== $newPath) {
            try {
                Storage::disk(self::DISK)->delete($oldPath);
            } catch (Throwable $e) {
                // Non-fatal, same reasoning as UpdateStudentPhoto: the new
                // logo is already committed, so an orphaned old file is a
                // cleanup task rather than something worth failing a
                // request that already succeeded.
                Log::warning('Failed to delete previous department logo.', [
                    'department_id' => $department->id,
                    'path' => $oldPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $department->fresh();
    }

    private function storeCompressed(UploadedFile $file, Department $department): string
    {
        // PNG (not JPEG) so logos with transparent backgrounds keep their
        // transparency instead of getting a solid white/black fill.
        $image = $this->images->read($file->getRealPath());
        $image->scaleDown(width: self::MAX_DIMENSION, height: self::MAX_DIMENSION);
        $encoded = $image->toPng();

        $path = sprintf('%s/%d-%s.%s', self::DIRECTORY, $department->id, Str::random(12), self::PNG);

        Storage::disk(self::DISK)->put($path, (string) $encoded);

        return $path;
    }
}
