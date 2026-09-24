import { useEffect, useState } from 'react';
import { isAxiosError } from 'axios';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useRescheduleEventDays } from '@/application/events/use-reschedule-event-days';
import type { EventDayWithSessions } from '@/domain/entities';
import { SessionStatus } from '@/domain/enums';
import { addDaysToDateOnly, diffDateOnlyDays } from '@/lib/utils';
import { Text } from '@/presentation/components/typography';

function extractErrorMessage(error: unknown, fallback: string): string {
    return isAxiosError(error) && typeof error.response?.data?.message === 'string'
        ? error.response.data.message
        : fallback;
}

// event-day-window-edit-delete-plan.md §4.2: the same "has anything
// started or ended" guard as a plain single-day edit — a day with zero
// sessions is still eligible (nothing has started).
function isDayEligible(day: EventDayWithSessions): boolean {
    return day.sessions.every((session) => session.status === SessionStatus.Scheduled);
}

export function RescheduleDaysDialog({
    open,
    onOpenChange,
    eventId,
    days,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    eventId: number;
    days: EventDayWithSessions[];
}) {
    const reschedule = useRescheduleEventDays();
    const eligibleDays = [...days].filter(isDayEligible).sort((a, b) => a.dayNumber - b.dayNumber);
    const ineligibleCount = days.length - eligibleDays.length;

    const [dates, setDates] = useState<Record<number, string>>({});
    const [touched, setTouched] = useState<Set<number>>(new Set());

    // Reset the working copy every time the dialog opens, so a previous
    // (possibly abandoned) edit session never bleeds into the next one.
    useEffect(() => {
        if (!open) return;
        setDates(Object.fromEntries(eligibleDays.map((day) => [day.id, day.date.slice(0, 10)])));
        setTouched(new Set());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, eventId]);

    // §4.5 point 2/4: editing one day's date default-suggests shifting
    // every *later*, not-yet-manually-touched eligible day forward by the
    // same delta — real date arithmetic (addDaysToDateOnly), never string
    // math, so a month/year rollover (June 30 + 1 → July 1) is correct.
    // §4.5 point 3: fully overridable — once the admin edits a day
    // directly, it's marked touched and the cascade stops re-suggesting
    // it.
    function handleDateChange(day: EventDayWithSessions, newDate: string) {
        const oldDate = dates[day.id];
        const delta = oldDate ? diffDateOnlyDays(newDate, oldDate) : 0;

        setDates((prev) => {
            const next = { ...prev, [day.id]: newDate };

            if (delta !== 0) {
                for (const other of eligibleDays) {
                    if (other.id === day.id || other.dayNumber <= day.dayNumber || touched.has(other.id)) continue;
                    next[other.id] = addDaysToDateOnly(prev[other.id], delta);
                }
            }

            return next;
        });
        setTouched((prev) => new Set(prev).add(day.id));
    }

    function handleSubmit() {
        reschedule.mutate(
            {
                eventId,
                targets: eligibleDays.map((day) => ({ event_day_id: day.id, date: dates[day.id] })),
            },
            {
                onSuccess: () => {
                    toast.success('Days rescheduled.');
                    onOpenChange(false);
                },
                onError: (error) => {
                    toast.error(
                        extractErrorMessage(error, 'Could not reschedule. Check that no two days end up on the same date.'),
                    );
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Reschedule days</DialogTitle>
                    <DialogDescription>
                        Shift several days at once. Editing one day suggests shifting the later days by the same
                        amount — every date below is still editable before you save.
                    </DialogDescription>
                </DialogHeader>

                {eligibleDays.length === 0 ? (
                    <Text variant="small">
                        No days are eligible to reschedule — every day either has a session that's already started or
                        ended, or the event has none yet.
                    </Text>
                ) : (
                    <div className="max-h-80 space-y-2 overflow-y-auto pr-1">
                        {eligibleDays.map((day) => (
                            <div key={day.id} className="flex items-center justify-between gap-4">
                                <Label htmlFor={`reschedule-${day.id}`} className="shrink-0">
                                    Day {day.dayNumber}
                                </Label>
                                <Input
                                    id={`reschedule-${day.id}`}
                                    type="date"
                                    className="max-w-[10rem]"
                                    value={dates[day.id] ?? ''}
                                    onChange={(e) => handleDateChange(day, e.target.value)}
                                />
                            </div>
                        ))}
                    </div>
                )}

                {ineligibleCount > 0 && (
                    <Text variant="caption">
                        {ineligibleCount} day{ineligibleCount === 1 ? '' : 's'} with a started or ended session
                        {ineligibleCount === 1 ? " isn't" : " aren't"} shown here and won't be moved.
                    </Text>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={handleSubmit}
                        disabled={reschedule.isPending || eligibleDays.length === 0}
                    >
                        {reschedule.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
