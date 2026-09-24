<?php

namespace App\Application\Actions\Attendance;

use App\Models\Student;

final class BuildStreakLeaderboard
{
    public function __construct(
        private readonly ComputeAttendanceStreaks $computeAttendanceStreaks = new ComputeAttendanceStreaks
    ) {
    }

    /**
     * Top $limit students by current attendance streak — global, across
     * every department and every event (spec: "global leader board of
     * students streak visible in the Dashboard top 5"). Students with no
     * streak at all (current === 0) never appear here; there's nothing
     * to rank them on.
     *
     * Ties on current streak are broken by who scanned their most recent
     * attendance record first — spec's own example: a session opens at
     * 1:00pm, Student A scans at 1:05:20pm, Student B scans at 1:06:10pm
     * — A is faster, so A outranks B despite an equal streak. This reads
     * ComputeAttendanceStreaks' latest_scan_at (the scanned_at of each
     * student's single most recent real scan — see that class's
     * docblock for why it's never an older one), so the same two
     * students can trade places the very next session: whichever of them
     * scans first *this* time is who wins the tiebreak next time the
     * leaderboard is built, since only the latest scan is ever compared.
     * A student tied on streak but with no scan at all yet (only
     * possible at streak 0, which is already excluded above) would sort
     * last among ties.
     *
     * @return list<array{
     *     rank: int, student_id: int, student_name: string,
     *     section: ?string, department_code: ?string, photo_url: ?string,
     *     current_streak: int, longest_streak: int, latest_scan_at: ?string,
     * }>
     */
    public function __invoke(int $limit = 5): array
    {
        $streaks = ($this->computeAttendanceStreaks)();

        $entries = $streaks
            ->filter(fn (array $row) => $row['current'] > 0)
            ->map(fn (array $row, int $studentId) => array_merge(['student_id' => $studentId], $row))
            ->values()
            ->sort(function (array $a, array $b) {
                if ($a['current'] !== $b['current']) {
                    return $b['current'] <=> $a['current']; // higher streak first
                }

                $aScan = $a['latest_scan_at'];
                $bScan = $b['latest_scan_at'];

                if ($aScan === null && $bScan === null) {
                    return $a['student_id'] <=> $b['student_id']; // deterministic, arbitrary
                }

                if ($aScan === null) {
                    return 1; // never scanned sorts behind one who has
                }

                if ($bScan === null) {
                    return -1;
                }

                return $aScan <=> $bScan; // earlier (faster) scan wins
            })
            ->values()
            ->take($limit);

        if ($entries->isEmpty()) {
            return [];
        }

        $students = Student::whereIn('id', $entries->pluck('student_id'))
            ->with('department:id,code')
            ->get()
            ->keyBy('id');

        return $entries
            ->values()
            ->map(function (array $entry, int $index) use ($students) {
                $student = $students->get($entry['student_id']);

                return [
                    'rank' => $index + 1,
                    'student_id' => $entry['student_id'],
                    'student_name' => $student ? trim("{$student->first_name} {$student->last_name}") : 'Unknown',
                    'section' => $student->section ?? null,
                    'department_code' => $student?->department?->code,
                    'photo_url' => $student?->photo_url,
                    'current_streak' => $entry['current'],
                    'longest_streak' => $entry['longest'],
                    'latest_scan_at' => $entry['latest_scan_at']?->toISOString(),
                ];
            })
            ->all();
    }
}
