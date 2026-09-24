<?php

use App\Application\Actions\Penalties\ReversePenalty;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\PenaltyAlreadyReversedException;
use App\Models\AttendancePenalty;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\PenaltyReversal;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reversePenaltyDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function reversePenaltyStudent(string $studentNumber, string $role = 'student'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => reversePenaltyDept('CCS')->id,
        'username' => 'u'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

function reversePenaltySession(Student $creator): AttendanceSession
{
    $event = EventModel::create(['name' => 'Intrams', 'created_by' => $creator->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'penalty_late_amount' => 10,
        'penalty_absent_amount' => 25,
    ]);
}

function reversePenaltyPenalty(Student $student, AttendanceSession $session): AttendancePenalty
{
    return AttendancePenalty::create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'amount' => 25,
        'reason' => 'Absent - Time In',
    ]);
}

it('reverses a penalty and writes an audit trail row', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Manila'));

    $admin = reversePenaltyStudent('2020000001', 'csg_admin');
    $student = reversePenaltyStudent('2020300001');
    $penalty = reversePenaltyPenalty($student, reversePenaltySession($admin));

    $reversed = (new ReversePenalty)($penalty, 'Documented excuse letter approved', $admin);

    expect($reversed->is_reversed)->toBeTrue()
        ->and($reversed->reversed_by)->toBe($admin->id)
        ->and($reversed->reversal_reason)->toBe('Documented excuse letter approved')
        ->and($reversed->reversed_at)->not->toBeNull();

    expect(PenaltyReversal::query()->count())->toBe(1);

    $log = PenaltyReversal::first();
    expect($log->attendance_penalty_id)->toBe($penalty->id)
        ->and($log->student_id)->toBe($student->id)
        ->and($log->reversed_by)->toBe($admin->id)
        ->and($log->reason)->toBe('Documented excuse letter approved');

    Carbon::setTestNow();
});

it('throws when reversing an already-reversed penalty', function () {
    $admin = reversePenaltyStudent('2020000002', 'csg_admin');
    $student = reversePenaltyStudent('2020300002');
    $penalty = reversePenaltyPenalty($student, reversePenaltySession($admin));

    (new ReversePenalty)($penalty, 'First excuse', $admin);

    expect(fn () => (new ReversePenalty)($penalty->fresh(), 'Second attempt', $admin))
        ->toThrow(PenaltyAlreadyReversedException::class);

    // The second, failed attempt must not have written a second audit row.
    expect(PenaltyReversal::query()->count())->toBe(1);
});

it('does not affect other penalties for the same student', function () {
    $admin = reversePenaltyStudent('2020000003', 'csg_admin');
    $student = reversePenaltyStudent('2020300003');
    $session = reversePenaltySession($admin);

    $penaltyOne = reversePenaltyPenalty($student, $session);
    $penaltyTwo = AttendancePenalty::create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'amount' => 10,
        'reason' => 'Late - Time In',
    ]);

    (new ReversePenalty)($penaltyOne, 'Excused', $admin);

    expect($penaltyOne->fresh()->is_reversed)->toBeTrue()
        ->and($penaltyTwo->fresh()->is_reversed)->toBeFalse();
});
