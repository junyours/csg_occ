<?php

namespace App\Application\Actions\Attendance;

use App\Models\Student;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class BuildStreakLeaderboard
{
    /**
     * How long a computed ranking is reused. Building one walks every
     * session x every student, which is by far the most expensive thing
     * the dashboard does — so it is built at most once per window per
     * scope no matter how many people open the dashboard or the
     * Leaderboard screen. A scan therefore shows up in the ranking within
     * this many seconds, which is plenty for a leaderboard.
     */
    public const CACHE_SECONDS = 60;

    public function __construct(
        private readonly ComputeAttendanceStreaks $computeAttendanceStreaks = new ComputeAttendanceStreaks
    ) {
    }

    /**
     * Top $limit students by current attendance streak — global, across
     * every department and every event. Students with no streak at all
     * (current === 0) never appear here; there's nothing to rank them on.
     *
     * Ties on current streak are broken by who scanned their most recent
     * attendance record first — spec's own example: a session opens at
     * 1:00pm, Student A scans at 1:05:20pm, Student B scans at 1:06:10pm
     * — A is faster, so A outranks B despite an equal streak. See
     * ComputeAttendanceStreaks for why latest_scan_at is always the
     * single most recent real scan.
     *
     * @return list<array{
     *     rank: int, student_id: int, student_name: string,
     *     section: ?string, department_code: ?string, photo_url: ?string,
     *     current_streak: int, longest_streak: int, latest_scan_at: ?string,
     * }>
     */
    public function __invoke(int $limit = 5): array
    {
        $ranking = $this->ranking();

        return $this->hydrate(array_slice($ranking['ranked'], 0, $limit), 0);
    }

    /**
     * The full ordered ranking for one scope, cached.
     *
     *  - no arguments            -> Overall (current streak right now)
     *  - $onlyDate (Y-m-d)       -> Today (streak earned within that date)
     *  - $throughSessionId       -> Session (streak as of that session)
     *
     * `ranked` holds ONLY students with a streak above zero, best first,
     * as compact rows — a few thousand tiny arrays at most. The position
     * of a student inside it IS their rank, so looking one student up is
     * a single pass over this array (see rankOf) and costs next to
     * nothing once the ranking exists. `total` is every streak-eligible
     * student, including the ones on zero.
     *
     * @return array{ranked: list<array{student_id: int, current: int, longest: int, latest_scan_at: ?string}>, total: int}
     */
    public function ranking(?string $onlyDate = null, ?int $throughSessionId = null): array
    {
        $key = match (true) {
            $throughSessionId !== null => "streak-ranking:session:{$throughSessionId}",
            $onlyDate !== null => "streak-ranking:date:{$onlyDate}",
            default => 'streak-ranking:overall',
        };

        $build = function () use ($onlyDate, $throughSessionId) {
            $streaks = ($this->computeAttendanceStreaks)(null, $onlyDate, $throughSessionId);

            $ranked = $streaks
                ->filter(fn (array $row) => $row['current'] > 0)
                ->map(fn (array $row, int $studentId) => [
                    'student_id' => $studentId,
                    'current' => $row['current'],
                    'longest' => $row['longest'],
                    // ISO-8601 UTC strings sort chronologically as plain
                    // strings, and unlike Carbon they serialise cleanly.
                    'latest_scan_at' => $row['latest_scan_at']?->toISOString(),
                ])
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
                ->all();

            return ['ranked' => $ranked, 'total' => $streaks->count()];
        };

        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        // Cache miss: let ONE request rebuild while the rest wait for its
        // result. Without this, a minute boundary under load would start
        // as many full streak computations as there are people hitting the
        // screen at that instant — the one way this feature could hurt the
        // server. Whoever gets the lock re-checks the cache first, so only
        // the first one actually computes.
        try {
            return Cache::lock("{$key}:lock", 30)->block(15, fn () => Cache::remember($key, self::CACHE_SECONDS, $build));
        } catch (LockTimeoutException) {
            return Cache::remember($key, self::CACHE_SECONDS, $build);
        }
    }

    /**
     * One student's place in a ranking from ranking().
     *
     * On a streak -> their exact position (1-based), the same one the
     * list shows. On zero -> they all share one rank just behind the last
     * student on a streak (a plain tie, the way every leaderboard treats
     * a tied score), and `tied` is true so the UI can say so if it wants.
     *
     * @param  array{ranked: list<array{student_id: int, current: int}>, total: int}  $ranking
     * @return array{rank: int, points: int, tied: bool}
     */
    public function rankOf(array $ranking, int $studentId): array
    {
        foreach ($ranking['ranked'] as $index => $row) {
            if ($row['student_id'] === $studentId) {
                return ['rank' => $index + 1, 'points' => $row['current'], 'tied' => false];
            }
        }

        return ['rank' => count($ranking['ranked']) + 1, 'points' => 0, 'tied' => true];
    }

    /**
     * Turns a slice of ranking() rows into display entries. One query for
     * all the students, however many rows.
     *
     * @param  list<array{student_id: int, current: int, longest: int, latest_scan_at: ?string}>  $rows
     * @param  int  $offset  Rank of the first row minus one.
     * @return list<array{
     *     rank: int, student_id: int, student_name: string,
     *     section: ?string, department_code: ?string, photo_url: ?string,
     *     current_streak: int, longest_streak: int, latest_scan_at: ?string,
     * }>
     */
    public function hydrate(array $rows, int $offset = 0): array
    {
        if ($rows === []) {
            return [];
        }

        $students = Student::whereIn('id', array_column($rows, 'student_id'))
            ->with('department:id,code')
            ->get()
            ->keyBy('id');

        return array_values(array_map(function (array $row, int $index) use ($students, $offset) {
            $student = $students->get($row['student_id']);

            return [
                'rank' => $offset + $index + 1,
                'student_id' => $row['student_id'],
                'student_name' => $student ? trim("{$student->first_name} {$student->last_name}") : 'Unknown',
                'section' => $student->section ?? null,
                'department_code' => $student?->department?->code,
                'photo_url' => $student?->photo_url,
                'current_streak' => $row['current'],
                'longest_streak' => $row['longest'],
                'latest_scan_at' => $row['latest_scan_at'],
            ];
        }, $rows, array_keys($rows)));
    }
}
