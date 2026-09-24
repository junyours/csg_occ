<?php

use App\Application\Actions\Penalties\BuildPenaltySummary;
use App\Application\Actions\Penalties\ReversePenalty;
use App\Models\AttendancePenalty;
use App\Models\EventModel;
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

    ledgerPenalty(ledgerStudent('2020400001'), $session, ['reason' => 'Absent - Time In', 'amount' => 25]);
    ledgerPenalty(ledgerStudent('2020400002'), $session, ['reason' => 'Absent - Time In', 'amount' => 25]);
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
