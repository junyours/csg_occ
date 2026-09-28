<?php

namespace App\Application\Actions\Students;

use App\Domain\ValueObjects\QrPayload;
use App\Domain\Exceptions\NoActiveSemesterException;
use App\Domain\Support\CurrentSemester;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class CreateStudent
{
    /** Also what an admin's "Reset password" sets (see ResetStudentPassword). */
    public const DEFAULT_PASSWORD = 'password123';

    private static ?string $defaultPasswordHash = null;

    /**
     * Every bulk-imported student shares the exact same default password
     * (spec §4.3) — hashing that identical string once per request and
     * reusing it, instead of paying bcrypt's cost-12 hash 1,000 separate
     * times for a 1,000-row import, is the difference between a fast
     * import and a slow one. Laravel's 'hashed' cast on Student::password
     * detects an already-hashed value (Hash::isHashed()) and passes it
     * through untouched, so this is safe to assign directly.
     */
    private static function defaultPasswordHash(): string
    {
        return self::$defaultPasswordHash ??= \Illuminate\Support\Facades\Hash::make(self::DEFAULT_PASSWORD);
    }

    /**
     * Spec §4.1/§4.3: username = student number, default password
     * `password123`, forced to change it on first login. Spec §5.2: the QR
     * payload only needs the student's id + qr_version — generated once
     * here and persisted so it's a stable, permanent code, not
     * regenerated on every profile view.
     *
     * @param array{
     *     student_number: string,
     *     last_name: string,
     *     first_name: string,
     *     middle_name?: string|null,
     *     suffix?: string|null,
     *     department_id: int,
     *     major?: string|null,
     *     year_level: string,
     *     section?: string|null,
     *     date_enrolled?: string|null,
     * } $data
     */
    public function __invoke(array $data): Student
    {
        // Only ever enrolled into the active semester; none active = refuse.
        $semesterId = CurrentSemester::id()
            ?? throw new NoActiveSemesterException('There is no active semester to enroll students into.');

        return DB::transaction(function () use ($data, $semesterId) {
            $student = Student::create([
                'student_number' => $data['student_number'],
                'last_name' => $data['last_name'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'suffix' => $data['suffix'] ?? null,
                'department_id' => $data['department_id'],
                'major' => $data['major'] ?? null,
                'year_level' => $data['year_level'],
                'section' => $data['section'] ?? null,
                'date_enrolled' => $data['date_enrolled'] ?? null,
                'semester_id' => $semesterId,
                'status' => Student::STATUS_ACTIVE,
                'username' => $data['student_number'],
                'password' => self::defaultPasswordHash(),
                'must_change_password' => true,
            ]);

            // Refresh first: Eloquent doesn't hydrate DB-side column
            // defaults (qr_version defaults to 1 at the schema level)
            // back onto the in-memory model after create(), so
            // $student->qr_version would otherwise be null here.
            $student->refresh();

            // Written after create() because it's a permanent, stable
            // code (spec §5.2) tied to the student's real id, not
            // something we can pre-compute before the row exists.
            $student->update([
                'qr_token' => QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt(),
            ]);

            return $student;
        });
    }
}
