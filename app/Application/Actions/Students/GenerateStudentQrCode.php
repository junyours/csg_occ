<?php

namespace App\Application\Actions\Students;

use App\Domain\Contracts\QrCodeRendererInterface;
use App\Domain\ValueObjects\QrPayload;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;

final class GenerateStudentQrCode
{
    public function __construct(
        private readonly QrCodeRendererInterface $renderer,
    ) {}

    /**
     * Spec §4.5/§5: renders the student's permanent QR token — generated
     * once at CreateStudent-time — as a scannable PNG. Self-heals a
     * missing token (e.g. a row that predates qr_token, or a seeded
     * record) by generating and persisting one on the fly, rather than
     * 500-ing, so this endpoint doesn't depend on every student having
     * gone through CreateStudent specifically.
     */
    /**
     * Call this right before bumping a student's qr_version (e.g. in a
     * future "reissue QR" action), passing their version *before* the
     * bump. Without this, the old cache entry becomes unreachable dead
     * weight — nothing will ever look up that key again once the
     * version changes, but it also never gets deleted on its own.
     */
    public static function forgetCachedPng(Student $student, int $oldQrVersion): void
    {
        Cache::forget("qr-png:{$student->id}:{$oldQrVersion}");
    }

    public function __invoke(Student $student): string
    {
        if (! $student->qr_token) {
            $student->update([
                'qr_token' => QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt(),
            ]);
        }

        // Cache::remember writes through to the `cache` table when
        // CACHE_STORE=database, whose `value` column is a UTF-8-family
        // text column — raw PNG bytes aren't valid UTF-8 and MySQL's
        // strict mode rejects them outright (error 1366). Base64-encode
        // before caching, decode on the way out, so only ASCII ever
        // touches the cache row.
        $encoded = Cache::remember(
            "qr-png:{$student->id}:{$student->qr_version}",
            now()->addDays(90),
            fn () => base64_encode($this->renderer->renderPng($student->qr_token)),
        );

        return base64_decode($encoded);
    }
}
