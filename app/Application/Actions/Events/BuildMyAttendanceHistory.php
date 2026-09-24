<?php

namespace App\Application\Actions\Events;

use App\Models\EventModel;
use App\Models\Student;

final class BuildMyAttendanceHistory
{
    public function __construct(private readonly BuildMyEventAttendance $buildMyEventAttendance)
    {
    }

    /**
     * One student's own attendance across every event, flattened to one
     * row per session — the "history" counterpart to
     * BuildMyEventAttendance, which stays nested under one event for the
     * event-detail screen. Reuses that same action per event rather than
     * re-deriving the Present/Late/Absent/Excluded/pending reasoning, so
     * the two screens can never drift apart on what a given session
     * reports.
     *
     * Newest event first (most recently created), then in day/session
     * order within each event — the order a student would actually want
     * to scroll through their own history.
     *
     * @return list<array{
     *     event_id: int, event_name: string, event_status: string,
     *     day_id: int, day_number: int, date: string,
     *     session_id: int, window_type: string, check_type: string,
     *     start_time: string, end_time: string, session_status: string,
     *     attendance_status: ?string, scanned_at: ?string, scanned_by_name: ?string,
     * }>
     */
    public function __invoke(Student $student): array
    {
        return EventModel::orderByDesc('id')
            ->get()
            ->flatMap(function (EventModel $event) use ($student) {
                $attendance = ($this->buildMyEventAttendance)($event, $student);

                return collect($attendance['days'])->flatMap(
                    fn (array $day) => collect($day['sessions'])->map(fn (array $session) => [
                        'event_id' => $attendance['event_id'],
                        'event_name' => $attendance['event_name'],
                        'event_status' => $attendance['event_status'],
                        'day_id' => $day['id'],
                        'day_number' => $day['day_number'],
                        'date' => $day['date'],
                        'session_id' => $session['id'],
                        'window_type' => $session['window_type'],
                        'check_type' => $session['check_type'],
                        'start_time' => $session['start_time'],
                        'end_time' => $session['end_time'],
                        'session_status' => $session['session_status'],
                        'attendance_status' => $session['attendance_status'],
                        'scanned_at' => $session['scanned_at'],
                        'scanned_by_name' => $session['scanned_by_name'],
                    ]),
                );
            })
            ->values()
            ->all();
    }
}
