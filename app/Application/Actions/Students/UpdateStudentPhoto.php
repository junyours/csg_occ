<?php

namespace App\Application\Actions\Students;

use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Throwable;

final class UpdateStudentPhoto
{
    private const DISK = 'public';

    private const DIRECTORY = 'photos';

    // Spec §4.5: cap the longest edge so a raw phone photo (often several
    // MB, thousands of pixels wide) doesn't get stored at full resolution
    // just because it passed the 5MB upload-size check.
    private const MAX_DIMENSION = 800;

    private const JPEG_QUALITY = 80;

    public function __construct(
        private readonly ImageManager $images,
    ) {}

    /**
     * Spec §4.5: re-upload replaces — never accumulates — a student's
     * photo. Allowed at any time, including while a session is ongoing.
     *
     * Ordering is deliberate: the new file is written to disk *before*
     * the DB transaction opens, so if the DB update fails the
     * transaction rolls back and the now-orphaned new file is cleaned up
     * in the catch — the student is never left pointing at a photo_path
     * that doesn't exist. The old file is only deleted *after* the
     * transaction commits, so a delete failure just leaves a harmless
     * orphaned old file rather than a student with no photo at all — of
     * the two failure modes the spec calls out, a stray file on disk is
     * far cheaper to clean up later than a blank profile photo.
     */
    public function __invoke(Student $student, UploadedFile $file): Student
    {
        $oldPath = $student->photo_path;
        $newPath = $this->storeCompressed($file, $student);

        try {
            DB::transaction(function () use ($student, $newPath) {
                $student->update(['photo_path' => $newPath]);
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($newPath);

            throw $e;
        }

        if ($oldPath && $oldPath !== $newPath) {
            try {
                Storage::disk(self::DISK)->delete($oldPath);
            } catch (Throwable $e) {
                // Non-fatal: the student's new photo is already committed
                // and correct. An orphaned old file is a cleanup task, not
                // a data-integrity problem — throwing here would fail a
                // request that already succeeded from the student's POV.
                Log::warning('Failed to delete previous student photo.', [
                    'student_id' => $student->id,
                    'path' => $oldPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $student->fresh();
    }

    private function storeCompressed(UploadedFile $file, Student $student): string
    {
        $image = $this->images->read($file->getRealPath());
        $image->scaleDown(width: self::MAX_DIMENSION, height: self::MAX_DIMENSION);
        $encoded = $image->toJpeg(quality: self::JPEG_QUALITY);

        $path = sprintf('%s/%d-%s.jpg', self::DIRECTORY, $student->id, Str::random(12));

        Storage::disk(self::DISK)->put($path, (string) $encoded);

        return $path;
    }
}
