<?php

use App\Application\Actions\Exclusions\CreateExclusion;
use App\Application\Actions\Sessions\EndSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\DuplicateExclusionException;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayNotInEventException;
use App\Domain\Exceptions\ExclusionScheduleAlreadyEndedException;
use App\Domain\Exceptions\StudentNotFoundException;
use App\Domain\Exceptions\WindowNotFoundOnDayException;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function exclusionDept(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function exclusionAdmin(): Student
{
    return Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => exclusionDept('CCS')->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => 'csg_admin',
    ]);
}

function exclusionStudent(string $studentNumber = '2023105413'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => exclusionDept()->id,
        'username' => 'jcruz'.$studentNumber,
        'password' => 'password',
        // DB defaults this to 1, but Eloquent doesn't hydrate DB-side
        // defaults back onto the model after create() — set it explicitly
        // so ->qr_version is a real int for QrPayload::forStudent().
        'qr_version' => 1,
    ]);
}

function exclusionEvent(Student $creator, array $overrides = []): EventModel
{
    return EventModel::create(array_merge([
        'name' => 'Test Event', 'created_by' => $creator->id,
    ], $overrides));
}

function exclusionDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function exclusionSession(EventDay $day, WindowType $windowType = WindowType::Morning, array $overrides = []): AttendanceSession
{
    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => $windowType,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Scheduled,
    ], $overrides));
}

// --- Event scope -----------------------------------------------------

it('creates an event-scoped exclusion by student number', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);

    $exclusion = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Academic probation',
        'student_number' => $student->student_number,
    ], $admin);

    expect($exclusion->student_id)->toBe($student->id)
        ->and($exclusion->created_by)->toBe($admin->id)
        ->and($exclusion->scope)->toBe(ExclusionScope::Event)
        ->and($exclusion->event_day_id)->toBeNull()
        ->and($exclusion->window_type)->toBeNull()
        ->and($exclusion->reason)->toBe('Academic probation')
        ->and($exclusion->status->value)->toBe('active');
});

it('creates an exclusion by resolving the student from a scanned qr token', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $exclusion = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Testing exclusion',
        'qr_token' => $token,
    ], $admin);

    expect($exclusion->student_id)->toBe($student->id);
});

it('throws when no student matches the given student number', function () {
    $admin = exclusionAdmin();
    $event = exclusionEvent($admin);

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Testing exclusion',
        'student_number' => 'does-not-exist',
    ], $admin);
})->throws(StudentNotFoundException::class);

it('throws when adding an event-scoped exclusion to an already-ended event', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin, ['status' => EventStatus::Ended]);

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);
})->throws(EventAlreadyEndedException::class);

// --- Day scope -----------------------------------------------------------

it('creates a day-scoped exclusion when the day belongs to the event and has not ended', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    exclusionSession($day); // day has a schedule, still scheduled (not ended)

    $exclusion = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Day->value,
        'event_day_id' => $day->id,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);

    expect($exclusion->scope)->toBe(ExclusionScope::Day)
        ->and($exclusion->event_day_id)->toBe($day->id)
        ->and($exclusion->window_type)->toBeNull();
});

it('throws when the given day does not belong to the given event', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $eventA = exclusionEvent($admin);
    $eventB = exclusionEvent($admin);
    $dayOnEventB = exclusionDay($eventB);

    (new CreateExclusion)([
        'event_id' => $eventA->id,
        'scope' => ExclusionScope::Day->value,
        'event_day_id' => $dayOnEventB->id,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);
})->throws(EventDayNotInEventException::class);

it('throws when adding a day-scoped exclusion to a day that has already ended', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    exclusionSession($day, WindowType::Morning, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Day->value,
        'event_day_id' => $day->id,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);
})->throws(ExclusionScheduleAlreadyEndedException::class);

// --- Window scope ----------------------------------------------------------

it('creates a window-scoped exclusion when that window exists on the day and has not ended', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    exclusionSession($day, WindowType::Morning);

    $exclusion = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Window->value,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning->value,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);

    expect($exclusion->scope)->toBe(ExclusionScope::Window)
        ->and($exclusion->event_day_id)->toBe($day->id)
        ->and($exclusion->window_type)->toBe(WindowType::Morning);
});

it('throws when the given window does not exist on that day', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    exclusionSession($day, WindowType::Morning); // only Morning exists

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Window->value,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Evening->value,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);
})->throws(WindowNotFoundOnDayException::class);

it('throws when adding a window-scoped exclusion to a window that has already ended', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    exclusionSession($day, WindowType::Morning, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Window->value,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning->value,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);
})->throws(ExclusionScheduleAlreadyEndedException::class);

it('allows excluding from Day 2 Morning while Day 2 Afternoon has already ended (independent windows)', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    exclusionSession($day, WindowType::Morning); // still open
    exclusionSession($day, WindowType::Afternoon, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    $exclusion = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Window->value,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning->value,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);

    expect($exclusion->window_type)->toBe(WindowType::Morning);
});

// --- Duplicate detection (plan §5 step 4) ---------------------------------

it('throws when the student already has an active exclusion for the exact same scope', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'First reason',
        'student_number' => $student->student_number,
    ], $admin);

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Second reason',
        'student_number' => $student->student_number,
    ], $admin);
})->throws(DuplicateExclusionException::class);

it('allows a second exclusion for the same student under a different, narrower scope', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    exclusionSession($day, WindowType::Morning);

    (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Event-wide',
        'student_number' => $student->student_number,
    ], $admin);

    // Not a duplicate — different scope (Day, not Event) — student-
    // exclusion-feature-plan.md §6a point 5: no precedence/override
    // logic, an active row for any distinct scope is simply its own row.
    $dayExclusion = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Day->value,
        'event_day_id' => $day->id,
        'reason' => 'Also day-scoped',
        'student_number' => $student->student_number,
    ], $admin);

    expect($dayExclusion->scope)->toBe(ExclusionScope::Day);
});

it('does not treat a removed exclusion as a duplicate', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);

    $first = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'First reason',
        'student_number' => $student->student_number,
    ], $admin);

    $first->update(['status' => 'removed']);

    $second = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Re-excluding',
        'student_number' => $student->student_number,
    ], $admin);

    expect($second->id)->not->toBe($first->id)
        ->and($second->status->value)->toBe('active');
});

// --- Historical safety net: creating a new exclusion can't affect an
// already-ended session either — belt-and-suspenders alongside
// EndSession's own permanent-record write (see EndSessionTest). ----------

it('an event-scoped exclusion never covers a session that already ended before it was created', function () {
    $admin = exclusionAdmin();
    $student = exclusionStudent();
    $event = exclusionEvent($admin);
    $day = exclusionDay($event);
    $session = exclusionSession($day, WindowType::Morning);

    // The session genuinely ends (via EndSession, so it gets a real
    // ended_at) before any exclusion exists.
    (new EndSession)($session);

    $exclusion = (new CreateExclusion)([
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event->value,
        'reason' => 'Testing exclusion',
        'student_number' => $student->student_number,
    ], $admin);

    $session->refresh();

    expect(Exclusion::excludedStudentIdsForSession($session))
        ->not->toContain($student->id)
        // Sanity: the exclusion itself was created fine (only the
        // session ended, not the whole event).
        ->and($exclusion->scope)->toBe(ExclusionScope::Event);
});
