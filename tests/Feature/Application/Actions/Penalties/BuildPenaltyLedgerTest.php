<?php

use App\Application\Actions\Penalties\BuildPenaltyLedger;
use App\Application\Actions\Penalties\ReversePenalty;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendancePenalty;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ledgerDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function ledgerAdmin(string $studentNumber = '2020000001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => ledgerDept('CCS')->id,
            'username' => 'admin'.$studentNumber,
            'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function ledgerStudent(string $studentNumber, string $deptCode = 'CCS', array $overrides = []): Student
{
    return Student::create(array_merge([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => ledgerDept($deptCode)->id,
        'username' => 'stu'.$studentNumber,
        'password' => 'password',
    ], $overrides));
}

function ledgerSession(EventModel $event): AttendanceSession
{
    $day = EventDay::create([
        'event_id' => $event->id,
        'date' => '2026-11-10',
        'day_number' => $event->days()->count() + 1,
    ]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'penalty_late_amount' => 10,
        'penalty_absent_amount' => 25,
    ]);
}

function ledgerPenalty(Student $student, AttendanceSession $session, array $overrides = []): AttendancePenalty
{
    return AttendancePenalty::create(array_merge([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'amount' => 25,
        'reason' => 'Absent - Time In',
    ], $overrides));
}

it('lists every penalty with student and event context, newest first', function () {
    $admin = ledgerAdmin();
    $event = EventModel::create(['name' => 'Intrams', 'created_by' => $admin->id]);
    $session = ledgerSession($event);

    $older = ledgerPenalty(ledgerStudent('2020300001'), $session);
    // created_at isn't in AttendancePenalty's $fillable, so a plain
    // update() silently drops it (Laravel mass-assignment protection) —
    // forceFill bypasses that guard, which is what's needed to actually
    // backdate the row for this test.
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = ledgerPenalty(ledgerStudent('2020300002'), $session);

    $page = (new BuildPenaltyLedger)([]);

    expect($page->total())->toBe(2)
        ->and($page->items()[0]['id'])->toBe($newer->id)
        ->and($page->items()[1]['id'])->toBe($older->id)
        ->and($page->items()[0]['event_name'])->toBe('Intrams')
        ->and($page->items()[0]['window_type'])->toBe('morning')
        ->and($page->items()[0]['check_type'])->toBe('time_in');
});

it('filters by department', function () {
    $admin = ledgerAdmin();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);
    $session = ledgerSession($event);

    $ccsStudent = ledgerStudent('2020300003', 'CCS');
    $bsbaStudent = ledgerStudent('2020300004', 'BSBA');
    ledgerPenalty($ccsStudent, $session);
    ledgerPenalty($bsbaStudent, $session);

    $page = (new BuildPenaltyLedger)(['department_id' => ledgerDept('BSBA')->id]);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['student_id'])->toBe($bsbaStudent->id);
});

it('filters by event', function () {
    $admin = ledgerAdmin();
    $eventOne = EventModel::create(['name' => 'Event One', 'created_by' => $admin->id]);
    $eventTwo = EventModel::create(['name' => 'Event Two', 'created_by' => $admin->id]);

    $penaltyOne = ledgerPenalty(ledgerStudent('2020300005'), ledgerSession($eventOne));
    ledgerPenalty(ledgerStudent('2020300006'), ledgerSession($eventTwo));

    $page = (new BuildPenaltyLedger)(['event_id' => $eventOne->id]);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]['id'])->toBe($penaltyOne->id);
});

it('filters by reversed status', function () {
    $admin = ledgerAdmin();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);
    $session = ledgerSession($event);

    $active = ledgerPenalty(ledgerStudent('2020300007'), $session);
    $reversed = ledgerPenalty(ledgerStudent('2020300008'), $session);
    (new ReversePenalty)($reversed, 'Excused', $admin);

    $activeOnly = (new BuildPenaltyLedger)(['status' => 'active']);
    $reversedOnly = (new BuildPenaltyLedger)(['status' => 'reversed']);

    expect($activeOnly->total())->toBe(1)
        ->and($activeOnly->items()[0]['id'])->toBe($active->id)
        ->and($reversedOnly->total())->toBe(1)
        ->and($reversedOnly->items()[0]['id'])->toBe($reversed->id)
        ->and($reversedOnly->items()[0]['reversed_by_name'])->toBe('CSG Admin')
        ->and($reversedOnly->items()[0]['reversal_reason'])->toBe('Excused');
});

it('searches by student number and name', function () {
    $admin = ledgerAdmin();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);
    $session = ledgerSession($event);

    $target = ledgerStudent('2020399999', 'CCS', ['last_name' => 'Delacruz']);
    ledgerPenalty($target, $session);
    ledgerPenalty(ledgerStudent('2020300009', 'CCS', ['last_name' => 'Santos']), $session);

    $byNumber = (new BuildPenaltyLedger)(['search' => '2020399999']);
    $byName = (new BuildPenaltyLedger)(['search' => 'Delacruz']);

    expect($byNumber->total())->toBe(1)
        ->and($byNumber->items()[0]['student_id'])->toBe($target->id)
        ->and($byName->total())->toBe(1)
        ->and($byName->items()[0]['student_id'])->toBe($target->id);
});

it('carries the student photo url so the ledger can render a real avatar', function () {
    $admin = ledgerAdmin();
    $event = EventModel::create(['name' => 'Intrams', 'created_by' => $admin->id]);
    $session = ledgerSession($event);

    $withPhoto = ledgerStudent('2020300050', 'CCS', ['photo_path' => 'photos/2020300050.jpg']);
    $withoutPhoto = ledgerStudent('2020300051');

    ledgerPenalty($withPhoto, $session);
    ledgerPenalty($withoutPhoto, $session);

    $rows = collect((new BuildPenaltyLedger)([])->items())->keyBy('student_number');

    // Root-relative on purpose — see Student::photoUrl for why this isn't
    // built from APP_URL.
    expect($rows['2020300050']['photo_url'])->toBe('/storage/photos/2020300050.jpg')
        // Null rather than absent: the frontend keys its initials fallback
        // off this being null, so the field has to exist either way.
        ->and($rows['2020300051'])->toHaveKey('photo_url')
        ->and($rows['2020300051']['photo_url'])->toBeNull();
});
