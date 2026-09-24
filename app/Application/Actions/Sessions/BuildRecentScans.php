<?php

namespace App\Application\Actions\Sessions;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use Illuminate\Database\Eloquent\Collection;

final class BuildRecentScans
{
    /** Keeps an unbounded ?limit from turning the scan screen into a full roster dump. */
    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 10;

    /**
     * The scanning screen's "Recent scans" strip, for one session only.
     *
     * This used to be a purely client-side list the officer's browser
     * accumulated from its own mutation results. Two things broke that:
     *
     *  1. It couldn't survive a reversal. Once a scan can be undone, a
     *     list assembled from "what this device happened to post" keeps
     *     showing rows the server no longer has.
     *  2. It wasn't really per-session in any authoritative way — it was
     *     whatever that one device had cached, which after switching
     *     sessions (or scanning another event earlier in the day) meant
     *     an officer could be looking at names that have nothing to do
     *     with the session they're scanning into now.
     *
     * Reading it back from the session itself fixes both, and makes the
     * list shared: several officers on several phones working the same
     * gate now see one list instead of three private ones.
     *
     * Scoped to `whereNotNull('scanned_at')` so it only ever shows real
     * scans. The Absent rows EndSession backfills are records too, but
     * nobody scanned them and they'd otherwise flood the list the
     * instant a session closed.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function __invoke(AttendanceSession $session, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return AttendanceRecord::query()
            ->where('session_id', $session->id)
            ->whereNotNull('scanned_at')
            // Same eager load as the scan endpoint's response, so the
            // officer's cross-check row (photo, name, department,
            // section) renders from one round trip rather than N+1.
            ->with(['student.department'])
            // id as the tiebreaker: a busy gate puts several scans in
            // the same second, and `scanned_at` alone would order those
            // arbitrarily — leaving the newest badge somewhere in the
            // middle of the strip instead of at the top where the
            // officer is looking.
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
