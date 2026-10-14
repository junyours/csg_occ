import { type FormEvent, type ReactNode, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { CalendarClock, CalendarDays, CalendarPlus, FileBarChart, Lock, Pencil, Radio, SearchX, Trash2, UserX } from 'lucide-react';
import { toast } from 'sonner';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuthStore } from '@/application/auth/auth.store';
import { useEvents } from '@/application/events/use-events';
import { useCreateEventDay } from '@/application/events/use-create-event-day';
import { useDeleteEvent } from '@/application/events/use-delete-event';
import { useEndEvent } from '@/application/events/use-end-event';
import { useForceDeleteEvent } from '@/application/events/use-force-delete-event';
import { useMyEventAttendance } from '@/application/events/use-my-event-attendance';
import { useUpdateEvent } from '@/application/events/use-update-event';
import { EVENT_STATUS_BADGE_CLASS, EVENT_STATUS_LABEL, EventStatus, Role, SessionStatus } from '@/domain/enums';
import { pickDefaultDay, sortDaysByDate } from '@/domain/default-day';
import { cn } from '@/lib/utils';
import { LiveTag } from '@/presentation/components/live-tag';
import { Heading, Text } from '@/presentation/components/typography';
import { EmptyState, ListSkeleton, SectionHeader } from '@/presentation/components/empty-state';
import { ScrollableTabList } from '@/presentation/components/scrollable-tab-list';
import { Tile } from '@/presentation/components/tile';
import { EventDaySection } from '@/presentation/pages/events/event-day-section';
import { RescheduleDaysDialog } from '@/presentation/pages/events/reschedule-days-dialog';
import { StudentEventDaySection } from '@/presentation/pages/events/student-event-day-section';

function extractErrorMessage(error: unknown, fallback: string): string {
    return isAxiosError(error) && typeof error.response?.data?.message === 'string'
        ? error.response.data.message
        : fallback;
}

const MANAGE_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin];
// Mirrors EventModelPolicy::viewRosterReport — an SC Admin can pull
// reports for their own department even though they can't manage days
// and sessions (MANAGE_ROLES above).
const REPORT_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin, Role.ScAdmin];

interface DayFormValues {
    date: string;
    dayNumber: string;
}

const EMPTY_DAY_FORM: DayFormValues = { date: '', dayNumber: '' };

interface EventFormValues {
    name: string;
    description: string;
}

export function EventDetailPage() {
    const { eventId } = useParams<{ eventId: string }>();
    const navigate = useNavigate();
    const student = useAuthStore((state) => state.student);
    const { data: events, isLoading } = useEvents();

    const [isCreatingDay, setIsCreatingDay] = useState(false);
    const [dayForm, setDayForm] = useState<DayFormValues>(EMPTY_DAY_FORM);
    const [isEndEventDialogOpen, setIsEndEventDialogOpen] = useState(false);
    const [isEditingEvent, setIsEditingEvent] = useState(false);
    const [eventForm, setEventForm] = useState<EventFormValues>({ name: '', description: '' });
    const [isRescheduleOpen, setIsRescheduleOpen] = useState(false);
    const [isDeletingEvent, setIsDeletingEvent] = useState(false);
    const [isForceDeleting, setIsForceDeleting] = useState(false);
    const [forceDeleteName, setForceDeleteName] = useState('');
    // Which day tab is open (by day number, so a freshly added day can be
    // selected before its id is known). null = use the smart default.
    const [selectedDayNumber, setSelectedDayNumber] = useState<number | null>(null);

    const event = events?.find((candidate) => candidate.id === Number(eventId));
    const createEventDay = useCreateEventDay(Number(eventId));
    const endEvent = useEndEvent();
    const updateEvent = useUpdateEvent();
    const deleteEvent = useDeleteEvent();
    const forceDeleteEvent = useForceDeleteEvent();
    // Only rendered for non-managers (see canManage below), but the hook
    // itself has to run unconditionally either way — React's rules of
    // hooks don't allow it behind the early `if (!student)` return.
    const myAttendance = useMyEventAttendance(Number(eventId));

    if (!student) return null;

    const canManage = MANAGE_ROLES.includes(student.role);
    const sortedDays = event ? sortDaysByDate(event.days) : [];
    const nextDayNumber = sortedDays.length > 0 ? Math.max(...sortedDays.map((day) => day.dayNumber)) + 1 : 1;
    const isEventEnded = event?.status === EventStatus.Ended;
    // Only one session per event may be ongoing at a time (spec: don't
    // start Day 1 Afternoon while Day 1 Morning Time Out is still open) —
    // computed across every day, not just the one being rendered, and
    // passed down so EventDaySection can disable "Start" on every *other*
    // session the moment one goes ongoing, instead of only finding out
    // after the server rejects it.
    const ongoingSessionId = sortedDays.flatMap((day) => day.sessions).find((session) => session.status === SessionStatus.Ongoing)?.id ?? null;
    // Mirrors DeleteEvent's own "nothing has actually happened yet"
    // guard (any session anywhere under the event already started or
    // ended) — just to decide whether to show the delete affordance at
    // all. The server re-checks for real, including the exclusion/report
    // guard this can't see from here.
    const eventHasStartedSession = sortedDays.some((day) =>
        day.sessions.some((session) => session.status !== SessionStatus.Scheduled),
    );

    // Default tab: a day with a session still ongoing (even if it's not today,
    // so a session left open past midnight is seen), else today's date, else
    // the next upcoming day, else the last one. See pickDefaultDay.
    const pickDay = pickDefaultDay;

    function renderDayTabs(days: { id: number; dayNumber: number }[], activeNumber: number | undefined, trailing?: ReactNode) {
        return (
            <ScrollableTabList className="flex gap-6 border-b px-4 sm:px-6" activeKey={isCreatingDay ? 'new' : (activeNumber ?? 'none')}>
                {days.map((d) => {
                    const isSelected = d.dayNumber === activeNumber && !isCreatingDay;

                    return (
                        <button
                            key={d.id}
                            type="button"
                            role="tab"
                            aria-selected={isSelected}
                            onClick={() => {
                                setIsCreatingDay(false);
                                setSelectedDayNumber(d.dayNumber);
                            }}
                            className={cn(
                                'relative -mb-px shrink-0 border-b-2 px-1 pb-2 text-small font-medium whitespace-nowrap transition-colors',
                                isSelected
                                    ? 'border-violet-500 text-violet-600 dark:text-violet-400'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            Day {d.dayNumber}
                        </button>
                    );
                })}
                {trailing}
            </ScrollableTabList>
        );
    }

    function confirmEndEvent() {
        if (!event) return;

        endEvent.mutate(event.id, {
            onSuccess: (result) => {
                toast.success(
                    result.sessionsEnded > 0 ? `Event ended. ${result.sessionsEnded} ongoing session(s) were ended too.` : 'Event ended.',
                );
            },
            onError: () => toast.error('Could not end event.'),
        });
    }

    function confirmForceDelete() {
        if (!event) return;

        forceDeleteEvent.mutate(
            { eventId: event.id, confirmName: forceDeleteName },
            {
                onSuccess: () => {
                    toast.success('Event and all its attendance data were deleted.');
                    setIsForceDeleting(false);
                    navigate('/events');
                },
                onError: (error) => toast.error(extractErrorMessage(error, 'Could not delete this event.')),
            },
        );
    }

    function confirmDeleteEvent() {
        if (!event) return;

        deleteEvent.mutate(event.id, {
            onSuccess: () => {
                toast.success('Event deleted.');
                navigate('/events');
            },
            onError: (error) => {
                const message = extractErrorMessage(
                    error,
                    'Could not delete event. It may already have a started session or a generated report tied to it.',
                );

                // DeleteEvent's one resolvable guard: an active exclusion.
                // Point straight at the screen that fixes it instead of
                // just saying "no" — same pattern as the day-date-collision
                // → Reschedule toast in event-day-section.tsx.
                if (message.toLowerCase().includes('exclusion')) {
                    toast.error(message, {
                        action: {
                            label: 'Go to Exclusions',
                            onClick: () => navigate(`/events/${event.id}/exclusions`),
                        },
                    });
                    return;
                }

                toast.error(message);
            },
        });
    }

    function openEditEvent() {
        if (!event) return;
        setEventForm({ name: event.name, description: event.description ?? '' });
        setIsEditingEvent(true);
    }

    function handleUpdateEvent(formEvent: FormEvent) {
        formEvent.preventDefault();
        if (!event) return;

        updateEvent.mutate(
            { id: event.id, payload: { name: eventForm.name, description: eventForm.description || null } },
            {
                onSuccess: () => {
                    toast.success('Event updated.');
                    setIsEditingEvent(false);
                },
                onError: (error) => toast.error(extractErrorMessage(error, 'Could not update event.')),
            },
        );
    }

    function handleCreateDay(formEvent: FormEvent) {
        formEvent.preventDefault();
        createEventDay.mutate(
            {
                date: dayForm.date,
                day_number: Number(dayForm.dayNumber),
            },
            {
                onSuccess: () => {
                    toast.success('Day added.');
                    setSelectedDayNumber(Number(dayForm.dayNumber));
                    setDayForm(EMPTY_DAY_FORM);
                    setIsCreatingDay(false);
                },
                onError: () => {
                    toast.error("Could not add day. Check the day number isn't already used.");
                },
            },
        );
    }

    if (isLoading) {
        return (
            <div className="mx-auto max-w-2xl space-y-4">
                <div className="h-8 w-56 animate-pulse rounded-full bg-muted" />
                <ListSkeleton rows={3} />
            </div>
        );
    }

    if (!event) {
        return (
            <div className="mx-auto max-w-2xl">
                <EmptyState
                    Icon={SearchX}
                    title="Event not found"
                    description="It may have been removed, or the link is wrong."
                    action={
                        <Button size="sm" variant="outline" onClick={() => navigate('/events')}>
                            Back to events
                        </Button>
                    }
                />
            </div>
        );
    }

    const hasOngoingSession = ongoingSessionId !== null;
    const activeDay = sortedDays.find((d) => d.dayNumber === selectedDayNumber) ?? pickDay(sortedDays);
    const studentDays = sortDaysByDate(myAttendance.data?.days ?? []);
    const activeStudentDay = studentDays.find((d) => d.dayNumber === selectedDayNumber) ?? pickDay(studentDays);

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            {/*
              The event header became a card: a tile that's lit while a
              session is actually running, the name, and the status badge.
              Previously the title and its badge sat loose above a divider,
              which made "is anything happening right now" a question you
              had to answer by scrolling into the day list.
            */}
            <div className="rounded-[10px] border bg-card p-4 sm:p-6">
                <div className="flex items-start gap-4">
                    <Tile
                        tone={hasOngoingSession ? 'emerald' : isEventEnded ? 'neutral' : 'sky'}
                        size="lg"
                        variant={hasOngoingSession ? 'solid' : 'soft'}
                        Icon={hasOngoingSession ? Radio : isEventEnded ? Lock : CalendarDays}
                        className={hasOngoingSession ? '[&>svg]:animate-pulse' : undefined}
                    />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <Heading level="h2" className="min-w-0 break-words">
                                {event.name}
                            </Heading>
                            {hasOngoingSession ? (
                                <LiveTag />
                            ) : (
                                <Badge variant="secondary" className={cn('rounded-sm', EVENT_STATUS_BADGE_CLASS[event.status])}>
                                    {EVENT_STATUS_LABEL[event.status]}
                                </Badge>
                            )}
                            {canManage && !isEventEnded && (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7 shrink-0 text-muted-foreground"
                                    onClick={openEditEvent}
                                    aria-label="Edit event"
                                >
                                    <Pencil className="size-3.5" />
                                </Button>
                            )}
                            {/* Only while nothing under the event has started
                                or ended yet — the server re-checks this (plus
                                an exclusion/report guard this can't see from
                                here) for real. */}
                            {canManage && !isEventEnded && !eventHasStartedSession && (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                                    onClick={() => setIsDeletingEvent(true)}
                                    aria-label="Delete event"
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            )}
                            {/* System Admin only, ended events only — the
                                override DeleteEvent deliberately doesn't offer. */}
                            {student.role === Role.SystemAdmin && isEventEnded && (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                                    onClick={() => {
                                        setForceDeleteName('');
                                        setIsForceDeleting(true);
                                    }}
                                    aria-label="Delete ended event and all its data"
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            )}
                        </div>
                        {event.description && <Text variant="small">{event.description}</Text>}
                        {hasOngoingSession && (
                            <Text variant="caption" className="mt-1">
                                A session is open for scanning right now.
                            </Text>
                        )}
                        {isEventEnded && <Text variant="caption">Ended — days and sessions are read-only.</Text>}
                    </div>
                </div>

                {(REPORT_ROLES.includes(student.role) || canManage) && (
                    <div className="mt-4 flex flex-wrap gap-2 border-t pt-4">
                        {REPORT_ROLES.includes(student.role) && (
                            <Button size="sm" variant="outline" className="gap-2" onClick={() => navigate(`/events/${event.id}/report`)}>
                                <FileBarChart className="size-4" />
                                Reports
                            </Button>
                        )}
                        {canManage && (
                            // student-exclusion-feature-plan.md §4B: "Manage Exclusions"
                            // is reachable any time from the event detail page, for the
                            // life of the event — not gated on isEventEnded, since the
                            // screen still shows history (and lets CSG remove an
                            // event-scope exclusion) even after the event itself ends.
                            <Button
                                size="sm"
                                variant="outline"
                                className="gap-2"
                                onClick={() => navigate(`/events/${event.id}/exclusions`)}
                            >
                                <UserX className="size-4" />
                                Exclusions
                            </Button>
                        )}
                        {canManage && !isEventEnded && sortedDays.length > 1 && (
                            <Button size="sm" variant="outline" className="gap-2" onClick={() => setIsRescheduleOpen(true)}>
                                <CalendarClock className="size-4" />
                                Reschedule days
                            </Button>
                        )}
                        {canManage && !isEventEnded && (
                            <Button size="sm" variant="destructive" onClick={() => setIsEndEventDialogOpen(true)} disabled={endEvent.isPending}>
                                {endEvent.isPending ? 'Ending…' : 'End event'}
                            </Button>
                        )}
                    </div>
                )}
            </div>

            <div className="overflow-hidden rounded-[10px] border bg-card">
                <div className="px-4 pt-4 sm:px-6">
                    <SectionHeader Icon={CalendarDays} tone="sky">
                        Days
                    </SectionHeader>
                </div>
                <div className="space-y-4 pb-4 sm:pb-6 [&>*:not([role=tablist])]:mx-4 sm:[&>*:not([role=tablist])]:mx-6">

                {canManage ? (
                    <>
                        {sortedDays.length === 0 && (
                            <EmptyState
                                Icon={CalendarPlus}
                                title="No days scheduled"
                                description="Add a day, then add the morning, afternoon and evening sessions that belong to it."
                            />
                        )}

                        {sortedDays.length > 0 &&
                            renderDayTabs(
                                sortedDays,
                                activeDay?.dayNumber,
                                !isEventEnded && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setDayForm({ date: '', dayNumber: String(nextDayNumber) });
                                            setIsCreatingDay(true);
                                        }}
                                        aria-selected={isCreatingDay}
                                        role="tab"
                                        className={cn(
                                            '-mb-px flex shrink-0 items-center gap-1 border-b-2 px-1 pb-2 text-small font-medium whitespace-nowrap transition-colors',
                                            isCreatingDay
                                                ? 'border-sky-500 text-sky-600'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        <CalendarPlus className="size-4" />
                                        Add day {nextDayNumber}
                                    </button>
                                ),
                            )}

                        {activeDay && !isCreatingDay && (
                            <EventDaySection
                                key={activeDay.id}
                                day={activeDay}
                                canManage={!isEventEnded}
                                ongoingSessionId={ongoingSessionId}
                                onDateCollision={() => setIsRescheduleOpen(true)}
                            />
                        )}
                    </>
                ) : (
                    <>
                        {myAttendance.isLoading && <ListSkeleton rows={2} />}

                        {!myAttendance.isLoading && (myAttendance.data?.days.length ?? 0) === 0 && (
                            <EmptyState
                                Icon={CalendarDays}
                                title="No days scheduled yet"
                                description="Sessions appear here once CSG schedules them."
                            />
                        )}

                        {studentDays.length > 0 && renderDayTabs(studentDays, activeStudentDay?.dayNumber)}
                        {activeStudentDay && <StudentEventDaySection key={activeStudentDay.id} day={activeStudentDay} />}
                    </>
                )}

                {canManage &&
                    !isEventEnded &&
                    (isCreatingDay || sortedDays.length === 0) &&
                    (isCreatingDay ? (
                        <Card>
                            <CardContent>
                                <form onSubmit={handleCreateDay} className="space-y-4">
                                    <div className="flex items-center gap-2">
                                        <Tile tone="sky" size="sm" variant="soft" Icon={CalendarPlus} />
                                        <Text variant="small" className="font-medium text-foreground">
                                            New day
                                        </Text>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="dayDate">Date</Label>
                                            <Input
                                                id="dayDate"
                                                type="date"
                                                value={dayForm.date}
                                                onChange={(e) => setDayForm((prev) => ({ ...prev, date: e.target.value }))}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="dayNumber">Day number</Label>
                                            <Input
                                                id="dayNumber"
                                                type="number"
                                                min={1}
                                                value={dayForm.dayNumber}
                                                onChange={(e) => setDayForm((prev) => ({ ...prev, dayNumber: e.target.value }))}
                                                required
                                            />
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" disabled={createEventDay.isPending}>
                                            {createEventDay.isPending ? 'Adding…' : 'Add day'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                setIsCreatingDay(false);
                                                setDayForm(EMPTY_DAY_FORM);
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    ) : (
                        <button
                            type="button"
                            onClick={() => {
                                setDayForm({ date: '', dayNumber: String(nextDayNumber) });
                                setIsCreatingDay(true);
                            }}
                            className="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-border p-4 text-small font-medium text-muted-foreground transition-colors hover:border-sky-500/40 hover:text-foreground"
                        >
                            <CalendarPlus className="size-4" />
                            Add day {nextDayNumber}
                        </button>
                    ))}
                </div>
            </div>

            <Dialog open={isEditingEvent} onOpenChange={setIsEditingEvent}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit event</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleUpdateEvent} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="editEventName">Name</Label>
                            <Input
                                id="editEventName"
                                value={eventForm.name}
                                onChange={(e) => setEventForm((prev) => ({ ...prev, name: e.target.value }))}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="editEventDescription">Description</Label>
                            <Input
                                id="editEventDescription"
                                value={eventForm.description}
                                onChange={(e) => setEventForm((prev) => ({ ...prev, description: e.target.value }))}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsEditingEvent(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={updateEvent.isPending}>
                                {updateEvent.isPending ? 'Saving…' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <RescheduleDaysDialog
                open={isRescheduleOpen}
                onOpenChange={setIsRescheduleOpen}
                eventId={event.id}
                days={sortedDays}
            />

            <AlertDialog open={isEndEventDialogOpen} onOpenChange={setIsEndEventDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>End this event?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Any still-ongoing session will be ended automatically too. This can't be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmEndEvent} className={buttonVariants({ variant: 'destructive' })}>
                            Yes, end event
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog open={isForceDeleting} onOpenChange={setIsForceDeleting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete ended event and all its data?</DialogTitle>
                    </DialogHeader>
                    <Text variant="small">
                        This permanently deletes {event?.name} and every day, session, attendance record (present, late, missed, excluded),
                        penalty, exclusion and generated report that belongs to it. Students and other events are not affected, and dashboard
                        and penalty totals will drop accordingly. This can't be undone.
                    </Text>
                    <div className="space-y-2">
                        <Label htmlFor="force-delete-name">Type the event name to confirm</Label>
                        <Input
                            id="force-delete-name"
                            value={forceDeleteName}
                            onChange={(e) => setForceDeleteName(e.target.value)}
                            placeholder={event?.name}
                            autoComplete="off"
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsForceDeleting(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmForceDelete}
                            disabled={forceDeleteEvent.isPending || forceDeleteName.trim() !== event?.name}
                        >
                            {forceDeleteEvent.isPending ? 'Deleting…' : 'Delete everything'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog open={isDeletingEvent} onOpenChange={setIsDeletingEvent}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this event?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {event?.name} and every day/session under it will be removed. This can't be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={confirmDeleteEvent}
                            disabled={deleteEvent.isPending}
                            className={buttonVariants({ variant: 'destructive' })}
                        >
                            {deleteEvent.isPending ? 'Deleting…' : 'Yes, delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
