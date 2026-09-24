<?php

use App\Application\Actions\Sessions\EndSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Exports\EventRosterReportExport;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

function rosterApiDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function rosterApiStaff(string $role, string $studentNumber, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => 'Test',
        'department_id' => rosterApiDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

function rosterApiEvent(int $createdBy): EventModel
{
    return EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $createdBy]);
}

function rosterApiSession(EventModel $event): AttendanceSession
{
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00', 'end_time' => '08:00:00', 'grace_minutes' => 15,
        'penalty_late_amount' => 10, 'penalty_absent_amount' => 25,
        'status' => SessionStatus::Ongoing,
    ]);
}

it('rejects an unauthenticated roster report request', function () {
    $admin = rosterApiStaff('csg_admin', '2020000001');
    $event = rosterApiEvent($admin->id);

    $this->getJson("/api/events/{$event->id}/roster-report")->assertStatus(401);
});

it('rejects an officer requesting the roster report', function () {
    $officer = rosterApiStaff('officer', '2020200001');
    $event = rosterApiEvent($officer->id);

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/events/{$event->id}/roster-report")
        ->assertStatus(403);
});

it('lets a csg admin download the roster report as an xlsx workbook by default', function () {
    Excel::fake();

    $admin = rosterApiStaff('csg_admin', '2020000002');
    $event = rosterApiEvent($admin->id);
    rosterApiSession($event);
    $bsit = rosterApiDept('BSIT');
    Student::create([
        'student_number' => '2023000001', 'last_name' => 'Cruz', 'first_name' => 'Ana',
        'department_id' => $bsit->id, 'year_level' => '1', 'section' => 'A',
        'username' => 'acruz', 'password' => 'password',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->get("/api/events/{$event->id}/roster-report")
        ->assertOk();

    Excel::assertDownloaded('intramurals-2026-roster-report.xlsx', function (EventRosterReportExport $export) {
        $sheets = $export->sheets();

        return count($sheets) === 1 && $sheets[0]->title() === 'BSIT 1A';
    });
});

it('lets a csg admin download the roster report as a pdf', function () {
    $admin = rosterApiStaff('csg_admin', '2020000003');
    $event = rosterApiEvent($admin->id);
    rosterApiSession($event);

    $response = $this->actingAs($admin, 'sanctum')
        ->get("/api/events/{$event->id}/roster-report?format=pdf");

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('forces an sc admin to their own department regardless of the department_id query param', function () {
    Excel::fake();

    $bsit = rosterApiDept('BSIT');
    $bsba = rosterApiDept('BSBA');
    $scAdmin = rosterApiStaff('sc_admin', '2020000004', $bsit->id);
    $event = rosterApiEvent($scAdmin->id);
    rosterApiSession($event);

    Student::create([
        'student_number' => '2023000002', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
        'department_id' => $bsit->id, 'year_level' => '1', 'section' => 'A',
        'username' => 'jdelacruz', 'password' => 'password',
    ]);
    Student::create([
        'student_number' => '2023000003', 'last_name' => 'Reyes', 'first_name' => 'Liza',
        'department_id' => $bsba->id, 'year_level' => '1', 'section' => 'A',
        'username' => 'lreyes', 'password' => 'password',
    ]);

    $this->actingAs($scAdmin, 'sanctum')
        ->get("/api/events/{$event->id}/roster-report?department_id={$bsba->id}")
        ->assertOk();

    Excel::assertDownloaded('intramurals-2026-roster-report.xlsx', function (EventRosterReportExport $export) {
        $sheets = $export->sheets();
        $students = $sheets[0]->array();

        return count($sheets) === 1
            && $sheets[0]->title() === 'BSIT 1A'
            && $students[0][0] === '2023000002';
    });
});
