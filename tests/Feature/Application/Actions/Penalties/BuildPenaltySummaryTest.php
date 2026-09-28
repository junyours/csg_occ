<?php

use App\Application\Actions\Penalties\BuildPenaltySummary;
use App\Application\Actions\Penalties\ReversePenalty;
use App\Models\AttendancePenalty;
use App\Models\EventModel;
use App\Models\Semester;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Reuses the ledgerDept/ledgerAdmin/ledgerStudent/ledgerSession/ledgerPenalty
// helpers defined in BuildPenaltyLedgerTest.php — Pest loads every test
// file in the suite, so those top-level function declarations are already
// available here without a require.

uses(RefreshDatabase::class);

it('totals amount and counts by outcome across every matching row, not just one page', function () {
    $admin = ledgerAdmin();
    $event = EventModel::create(['name' => 'Intrams', 'created_by' => $admin->id]);
    $session = ledgerSession($event);

    ledgerPenalty(ledgerStudent('2020400001'), $session, ['reason' => 'Missed - Time In', 'amount' => 25]);
    ledgerPenalty(ledgerStudent('2020400002'), $session, ['reason' => 'Missed - Time In', 'amount' => 25]);
    ledgerPenalty(ledgerStudent('2020400003'), $session, ['reason' => 'Late - Time In', 'amount' => 10]);

    $summary = (new BuildPenaltySummary)([]);

    expect($summary['total'])->toBe(60.0)
        ->and($summary['absentCount'])->toBe(2)
        ->and($summary['lateCount'])->toBe(1)
        ->and($summary['count'])->toBe(3);
});

it('scopes the summary to the same department/event filters as the ledger', function () {
    $admin = ledgerAdmin();
    $eventOne = EventModel::create(['name' => 'Event One', 'created_by' => $admin->id]);
    $eventTwo = EventModel::create(['name' => 'Event Two', 'created_by' => $admin->id]);

    ledgerPenalty(ledgerStudent('2020400004', 'CCS'), ledgerSession($eventOne), ['amount' => 25]);
    ledgerPenalty(ledgerStudent('2020400005', 'BSBA'), ledgerSession($eventOne), ['amount' => 25]);
    ledgerPenalty(ledgerStudent('2020400006', 'CCS'), ledgerSession($eventTwo), ['amount' => 25]);

    $byEvent = (new BuildPenaltySummary)(['event_id' => $eventOne->id]);
    $byDept = (new BuildPenaltySummary)(['department_id' => ledgerDept('CCS')->id]);

    expect($byEvent['total'])->toBe(50.0)
        ->and($byEvent['count'])->toBe(2)
        ->and($byDept['total'])->toBe(50.0)
        ->and($byDept['count'])->toBe(2);
});

it('includes reversed penalties in the total only when status asks for them', function () {
    $admin = ledgerAdmin();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);
    $session = ledgerSession($event);

    ledgerPenalty(ledgerStudent('2020400007'), $session, ['amount' => 25]);
    $reversed = ledgerPenalty(ledgerStudent('2020400008'), $session, ['amount' => 25]);
    (new ReversePenalty)($reversed, 'Excused', $admin);

    $active = (new BuildPenaltySummary)(['status' => 'active']);
    $reversedOnly = (new BuildPenaltySummary)(['status' => 'reversed']);
    $all = (new BuildPenaltySummary)(['status' => 'all']);

    expect($active['total'])->toBe(25.0)
        ->and($active['count'])->toBe(1)
        ->and($reversedOnly['total'])->toBe(25.0)
        ->and($reversedOnly['count'])->toBe(1)
        ->and($all['total'])->toBe(50.0)
        ->and($all['count'])->toBe(2);
});

it('breaks the total down per semester, newest first, only when listing all semesters', function () {
    $admin = ledgerAdmin();
    $first = seedActiveSemester('semester_1');
    $second = Semester::create([
        'academic_year_id' => $first->academic_year_id, 'name' => 'semester_2',
        'is_active' => false, 'created_by' => $admin->id,
    ]);
    $oldEvent = EventModel::create(['name' => 'Old', 'created_by' => $admin->id, 'semester_id' => $first->id]);
    $newEvent = EventModel::create(['name' => 'New', 'created_by' => $admin->id, 'semester_id' => $second->id]);

    ledgerPenalty(ledgerStudent('2020400101'), ledgerSession($oldEvent), ['amount' => 25]);
    ledgerPenalty(ledgerStudent('2020400102'), ledgerSession($newEvent), ['reason' => 'Late - Time In', 'amount' => 10]);
    ledgerPenalty(ledgerStudent('2020400103'), ledgerSession($newEvent), ['amount' => 25]);

    $summary = (new BuildPenaltySummary)(['scope' => 'all']);

    expect($summary['total'])->toBe(60.0)
        ->and($summary['bySemester'])->toHaveCount(2)
        ->and($summary['bySemester'][0]['semester_id'])->toBe($second->id)
        ->and($summary['bySemester'][0]['total'])->toBe(35.0)
        ->and($summary['bySemester'][0]['count'])->toBe(2)
        ->and($summary['bySemester'][0]['lateCount'])->toBe(1)
        ->and($summary['bySemester'][0]['absentCount'])->toBe(1)
        ->and($summary['bySemester'][0]['is_current'])->toBeFalse()
        ->and($summary['bySemester'][1]['total'])->toBe(25.0)
        ->and($summary['bySemester'][1]['is_current'])->toBeTrue()
        ->and((new BuildPenaltySummary)([]))->not->toHaveKey('bySemester');
});
