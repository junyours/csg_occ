<?php

namespace App\Domain\Support;

/**
 * BuildEventRosterReport already hands back `sessions` as one flat,
 * pre-sorted list (day -> window -> check order) with a single
 * concatenated label per column (e.g. "Day 1 — Morning — Time In"). That
 * flat shape is what the group/session lookups in BuildEventRosterReport
 * are keyed on, and changing it would ripple through every existing test
 * for that action — so instead of reshaping the data there, this is a
 * pure, display-only transform that both renderers (the PDF Blade view
 * and the Excel sheet) call to turn that flat list into a nested
 * day -> window -> check tree for printing a dynamic, multi-row header.
 *
 * "Dynamic" here means literally what's in the input: a day with only a
 * Morning session never gets an Afternoon/Evening column group, and a
 * window with only a time-in session never gets a Time Out column —
 * there's no padding with empty placeholder columns for windows/checks
 * that were never configured for that day.
 */
final class RosterReportColumnGrouper
{
    /**
     * @param  array<int, array{id: int, day_number: int, window_type: string, check_type: string, label: string}>  $sessions
     * @return array<int, array{
     *     day_number: int,
     *     span: int,
     *     windows: array<int, array{window_type: string, span: int, checks: array<int, array{check_type: string, session_id: int, label: string}>}>,
     * }>
     */
    public static function groupByDayAndWindow(array $sessions): array
    {
        $days = [];

        foreach ($sessions as $session) {
            $dayNumber = $session['day_number'];
            $windowType = $session['window_type'];

            $days[$dayNumber] ??= ['day_number' => $dayNumber, 'windows' => []];
            $days[$dayNumber]['windows'][$windowType] ??= ['window_type' => $windowType, 'checks' => []];
            $days[$dayNumber]['windows'][$windowType]['checks'][] = [
                'check_type' => $session['check_type'],
                'session_id' => $session['id'],
                'label' => self::checkLabel($session['check_type']),
            ];
        }

        ksort($days);

        return array_values(array_map(function (array $day) {
            $windows = array_values(array_map(function (array $window) {
                $window['span'] = count($window['checks']);

                return $window;
            }, $day['windows']));

            $day['windows'] = $windows;
            $day['span'] = array_sum(array_column($windows, 'span'));

            return $day;
        }, $days));
    }

    private static function checkLabel(string $checkType): string
    {
        return $checkType === 'time_out' ? 'Time Out' : 'Time In';
    }

    /**
     * The master report's equivalent of groupByDayAndWindow, with one
     * more level on top: Event -> Day -> Window -> Check (see the
     * attached mock: "event 1" / "event 2" spanning their own days,
     * each day spanning its windows, each window spanning its checks).
     *
     * Events keep whatever order they already arrive in (BuildMasterRosterReport
     * emits sessions in the same order the caller picked its events, then
     * day/window/check within each) — this never re-sorts by event id or
     * name, so the column order on screen matches the order the person
     * selected events in.
     *
     * @param  array<int, array{id: int, event_id: int, event_name: string, day_number: int, window_type: string, check_type: string, label: string}>  $sessions
     * @return array<int, array{
     *     event_id: int,
     *     event_name: string,
     *     span: int,
     *     days: array<int, array{
     *         day_number: int,
     *         span: int,
     *         windows: array<int, array{window_type: string, span: int, checks: array<int, array{check_type: string, session_id: int, label: string}>}>,
     *     }>,
     * }>
     */
    public static function groupByEventDayAndWindow(array $sessions): array
    {
        $events = [];

        foreach ($sessions as $session) {
            $eventId = $session['event_id'];
            $events[$eventId] ??= ['event_id' => $eventId, 'event_name' => $session['event_name'], 'sessions' => []];
            $events[$eventId]['sessions'][] = $session;
        }

        return array_values(array_map(function (array $event) {
            $days = self::groupByDayAndWindow($event['sessions']);

            return [
                'event_id' => $event['event_id'],
                'event_name' => $event['event_name'],
                'span' => array_sum(array_column($days, 'span')),
                'days' => $days,
            ];
        }, $events));
    }
}
