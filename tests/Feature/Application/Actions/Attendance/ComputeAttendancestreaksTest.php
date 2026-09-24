<?php

use App\Application\Actions\Attendance\BuildStreakLeaderboard;
use App\Application\Actions\Attendance\ComputeAttendanceStreaks;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function streakDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function streakOfficer(string $studentNumber = '2020000009'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Gate', 'first_name' => 'Officer',
            'department_id' => streakDept('CCS')->id,
            'username' => 'off'.$studentNumber,
            'password' => 'password',
            'role' => 'officer',
        ],
    );
}

function streakStudent(string $studentNumber, string $deptCode = 'CCS'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => streakDept($deptCode)->id,
        'username' => 'stu'.$studentNumber,
        'password' => 'password',
    ]);
}

/** A single time-in session, at an explicit date/time, on its own event day. */
function streakSession(EventModel $event, string $date, string $startTime = '07:00:00'): AttendanceSession
{
    $day = EventDay::create([
        'event_id' => $event->id,
        'date' => $date,
        'day_number' => $event->days()->count() + 1,
    ]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => $startTime,
        'end_time' => '08:00:00',
    ]);
}

function streakRecord(Student $student, AttendanceSession $session, string $status, ?string $scannedAt = null, ?Student $scanner = null): AttendanceRecord
{
    return AttendanceRecord::create([
        'session_id' => $session->id,
        'student_id' => $student->id,
        'scanned_by' => $scanner?->id,
        'scanned_at' => $status === 'absent' ? null : ($scannedAt ?? now()),
        'status' => $status,
    ]);
}

it('carries a streak across events instead of resetting at an event boundary', function () {
    $officer = streakOfficer();
    $student = streakStudent('2023100001');

    $eventOne = EventModel::create(['name' => 'Orientation', 'created_by' => $officer->id]);
    streakRecord($student, streakSession($eventOne, '2026-08-01'), 'present');
    streakRecord($student, streakSession($eventOne, '2026-08-02'), 'present');

    // A later, entirely separate event — chronologically after the first
    // one, but a different EventModel row (a different `id`), which is
    // exactly the case the old per-event scoping used to reset on.
    $eventTwo = EventModel::create(['name' => 'Intramurals', 'created_by' => $officer->id]);
    streakRecord($student, streakSession($eventTwo, '2026-08-10'), 'present');

    $streak = (new ComputeAttendanceStreaks)($student->id)->get($student->id);

    expect($streak['current'])->toBe(3)
        ->and($streak['longest'])->toBe(3);
});

it('does not break the streak on an excluded session, and does not add to it either', function () {
    $officer = streakOfficer();
    $student = streakStudent('2023100002');
    $event = EventModel::create(['name' => 'Orientation', 'created_by' => $officer->id]);

    streakRecord($student, streakSession($event, '2026-08-01'), 'present');
    // Frozen in as `excluded` the way EndSession would leave it — neither
    // a present nor an absent outcome.
    streakRecord($student, streakSession($event, '2026-08-02'), 'excluded');
    streakRecord($student, streakSession($event, '2026-08-03'), 'present');

    $streak = (new ComputeAttendanceStreaks)($student->id)->get($student->id);

    // The excluded middle session is invisible to the count: two Present
    // sessions on either side of it still add up to a streak of 2, not
    // reset to 1 by the exclusion and not inflated to 3 either.
    expect($streak['current'])->toBe(2)
        ->and($streak['longest'])->toBe(2);
});

it('resets the streak to zero on an absent or late session, still across events', function () {
    $officer = streakOfficer();
    $student = streakStudent('2023100003');

    $eventOne = EventModel::create(['name' => 'Orientation', 'created_by' => $officer->id]);
    streakRecord($student, streakSession($eventOne, '2026-08-01'), 'present');
    streakRecord($student, streakSession($eventOne, '2026-08-02'), 'present');

    $eventTwo = EventModel::create(['name' => 'Intramurals', 'created_by' => $officer->id]);
    streakRecord($student, streakSession($eventTwo, '2026-08-10'), 'absent');
    streakRecord($student, streakSession($eventTwo, '2026-08-11'), 'present');

    $streak = (new ComputeAttendanceStreaks)($student->id)->get($student->id);

    // Longest remembers the earlier 2-run; current only reflects the
    // single Present session after the Absent that reset it.
    expect($streak['current'])->toBe(1)
        ->and($streak['longest'])->toBe(2);
});

it('breaks the leaderboard tie by who scanned their latest session fastest', function () {
    $officer = streakOfficer();
    $event = EventModel::create(['name' => 'Orientation', 'created_by' => $officer->id]);

    $studentA = streakStudent('2023100004');
    $studentB = streakStudent('2023100005');

    // Both build up the exact same streak of 1...
    $session = streakSession($event, '2026-08-01', '13:00:00');
    // ...but A scans sooner after the 1:00pm session opens than B does.
    streakRecord($studentA, $session, 'present', '2026-08-01 13:05:20');
    streakRecord($studentB, $session, 'present', '2026-08-01 13:06:10');

    $leaderboard = (new BuildStreakLeaderboard)(5);

    expect($leaderboard)->toHaveCount(2)
        ->and($leaderboard[0]['student_id'])->toBe($studentA->id)
        ->and($leaderboard[0]['rank'])->toBe(1)
        ->and($leaderboard[1]['student_id'])->toBe($studentB->id)
        ->and($leaderboard[1]['rank'])->toBe(2);
});

it('lets a slower student overtake on the tiebreak next session, using only the latest scan', function () {
    $officer = streakOfficer();
    $event = EventModel::create(['name' => 'Orientation', 'created_by' => $officer->id]);

    $studentA = streakStudent('2023100006');
    $studentB = streakStudent('2023100007');

    $sessionOne = streakSession($event, '2026-08-01', '13:00:00');
    streakRecord($studentA, $sessionOne, 'present', '2026-08-01 13:05:20'); // faster
    streakRecord($studentB, $sessionOne, 'present', '2026-08-01 13:06:10');

    // Second session: B is faster this time. Both are still tied at a
    // streak of 2, and only THIS scan should decide the order — not an
    // average, and not the older one from the first session.
    $sessionTwo = streakSession($event, '2026-08-02', '13:00:00');
    streakRecord($studentA, $sessionTwo, 'present', '2026-08-02 13:10:00');
    streakRecord($studentB, $sessionTwo, 'present', '2026-08-02 13:02:00'); // faster this time

    $leaderboard = (new BuildStreakLeaderboard)(5);

    expect($leaderboard[0]['student_id'])->toBe($studentB->id)
        ->and($leaderboard[0]['current_streak'])->toBe(2)
        ->and($leaderboard[1]['student_id'])->toBe($studentA->id)
        ->and($leaderboard[1]['current_streak'])->toBe(2);
});

it('excludes students with no streak from the leaderboard', function () {
    $officer = streakOfficer();
    $event = EventModel::create(['name' => 'Orientation', 'created_by' => $officer->id]);

    $onStreak = streakStudent('2023100008');
    $neverScanned = streakStudent('2023100009');

    streakRecord($onStreak, streakSession($event, '2026-08-01'), 'present');

    $leaderboard = (new BuildStreakLeaderboard)(5);

    expect($leaderboard)->toHaveCount(1)
        ->and($leaderboard[0]['student_id'])->toBe($onStreak->id);
});

it('only checks a student against sessions from events that include their department', function () {
    $officer = streakOfficer();
    $ccsStudent = streakStudent('2023100010', 'CCS');

    $bsitOnlyEvent = EventModel::create(['name' => 'BSIT Only Event', 'created_by' => $officer->id]);
    $bsitOnlyEvent->departments()->sync([streakDept('BSIT')->id]);
    streakSession($bsitOnlyEvent, '2026-08-01'); // CCS student never appears in this roster at all

    $streak = (new ComputeAttendanceStreaks)($ccsStudent->id)->get($ccsStudent->id);

    // Nothing to resolve against — not a broken streak, just an empty one.
    expect($streak['current'])->toBe(0)
        ->and($streak['longest'])->toBe(0)
        ->and($streak['latest_scan_at'])->toBeNull();
});
