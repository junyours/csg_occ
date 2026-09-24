<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Student;

class AttendanceSessionPolicy
{
    /**
     * Spec §10 groups GET /sessions/{id}/report with the other
     * administration endpoints, not the Officer's scan endpoint — an
     * Officer runs the scanner but doesn't get the roster-level report,
     * mirroring StudentPolicy::viewAny. Row-level department scoping for
     * SC Admin (own department only) is applied in the controller, since
     * it depends on which students appear in the report, not on whether
     * the session itself is viewable.
     */
    public function viewReport(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin, Role::ScAdmin], true);
    }

    /**
     * An Officer doesn't get the org-wide ledger viewReport() gates (that
     * would let them browse every other officer's scans too), but they
     * do get to look back on their own — "who did I scan" — the same
     * audit trail everyone above already has, just pre-scoped to
     * scanned_by = themselves. AttendanceHistoryController is what
     * forces that scoping; this ability only decides who gets in the
     * door, same split as viewReport + department scoping for SC Admin.
     */
    public function viewOwnScanHistory(Student $user): bool
    {
        return $this->viewReport($user) || $user->role === Role::Officer;
    }

    /**
     * Spec §3/§10: scanning is the Officer's job at the gate, with every
     * admin role able to do it too (an admin covering a shift, testing a
     * scanner, etc.). A plain Student is never a valid scanner — they're
     * the thing being scanned, not the one holding the device — so
     * Student is deliberately the one role left out here.
     *
     * This was previously wide open ("any authenticated account") per an
     * explicit TODO-style comment on ScanAttendanceRequest; tightened to
     * match every other sensitive endpoint in this app, which gates on a
     * real role check rather than "logged in at all."
     */
    public function scan(Student $user): bool
    {
        return in_array($user->role, [
            Role::SystemAdmin,
            Role::CsgAdmin,
            Role::ScAdmin,
            Role::Officer,
        ], true);
    }

    /**
     * Undoing a scan is deliberately the *same* tier as making one, not
     * an admin-only escalation. The whole point of the feature is that
     * the officer holding the scanner is the only person who can see
     * that the face in front of them isn't the face on the badge — if
     * reversing had to wait for a CSG Admin, the wrong student stays
     * marked Present for the rest of the session and the real owner
     * can't scan in at all (the unique index on session+student means
     * their slot is taken).
     *
     * The safety net isn't a narrower role list, it's the audit trail:
     * every reversal writes an append-only attendance_record_reversals
     * row naming the officer who did it and why (see
     * ReverseAttendanceRecord), and the window is narrow — only while
     * the session is still Ongoing.
     */
    public function reverseScan(Student $user): bool
    {
        return $this->scan($user);
    }

    /**
     * The "Recent scans" strip on the scanning screen — the last handful
     * of badges read into *this one session*. Gated with the scanners
     * rather than with viewReport: it's the officer's own working list
     * (it's what they tap to reverse a mis-scan), and unlike the report
     * it's capped at a few rows of one live session rather than being a
     * roster-wide read.
     */
    public function viewRecentScans(Student $user): bool
    {
        return $this->scan($user);
    }

    /**
     * event-day-window-edit-delete-plan.md §6: creating a session is
     * currently gated by EventModelPolicy::create rather than a
     * same-named ability here — this normalizes update/delete onto the
     * session's own policy instead of following that (slightly odd)
     * precedent, while keeping the exact same two-role gate. Covers
     * both a single-check edit/delete (§4.3a) and the whole-window
     * delete convenience endpoint (§4.3b) — the latter isn't really
     * "one session" but shares the same CSG-only audience, so it's
     * gated with delete() too rather than a separate ability.
     */
    public function update(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }

    public function delete(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }
}
