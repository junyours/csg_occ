import { type FormEvent, useState } from 'react';
import { isAxiosError } from 'axios';
import { toast } from 'sonner';

import { CalendarDays, Moon, Pencil, Plus, Sun, Sunrise, Trash2, type LucideIcon } from 'lucide-react';

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
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useCreateSession } from '@/application/sessions/use-create-session';
import { useDeleteSession } from '@/application/sessions/use-delete-session';
import { useDeleteWindow } from '@/application/sessions/use-delete-window';
import { useEndSession } from '@/application/sessions/use-end-session';
import { useStartSession } from '@/application/sessions/use-start-session';
import { useUpdateSession } from '@/application/sessions/use-update-session';
import { useDeleteEventDay } from '@/application/events/use-delete-event-day';
import { useUpdateEventDay } from '@/application/events/use-update-event-day';
import type { AttendanceSession, EventDayWithSessions } from '@/domain/entities';
import { CHECK_TYPE_LABEL, CheckType, SESSION_STATUS_BADGE_CLASS, SessionStatus, WindowType, WINDOW_TYPE_LABEL } from '@/domain/enums';
import { cn, formatDate } from '@/lib/utils';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';

/** Window → icon + hue. Same pairing as the dashboard's streak strip, the
 *  event schedule and the scanner, so a "Morning" row is amber everywhere. */
const WINDOW_STYLE: Record<string, { Icon: LucideIcon; tone: Tone }> = {
    [WindowType.Morning]: { Icon: Sunrise, tone: 'amber' },
    [WindowType.Afternoon]: { Icon: Sun, tone: 'orange' },
    [WindowType.Evening]: { Icon: Moon, tone: 'violet' },
};

interface SessionFormValues {
    windowType: string;
    checkType: string;
    startTime: string;
    endTime: string;
    graceMinutes: string;
    penaltyLateAmount: string;
    penaltyAbsentAmount: string;
}

const EMPTY_SESSION_FORM: SessionFormValues = {
    windowType: '',
    checkType: '',
    startTime: '',
    endTime: '',
    graceMinutes: '',
    penaltyLateAmount: '',
    penaltyAbsentAmount: '',
};

function sessionToForm(session: AttendanceSession): SessionFormValues {
    return {
        windowType: session.windowType,
        checkType: session.checkType,
        // <input type="time"> wants "HH:MM" — sessions are always stored
        // as "HH:MM:SS" (see AttendanceSession::startTime()'s mutator).
        startTime: session.startTime.slice(0, 5),
        endTime: session.endTime.slice(0, 5),
        graceMinutes: session.graceMinutes ? String(session.graceMinutes) : '',
        penaltyLateAmount: session.penaltyLateAmount ? String(session.penaltyLateAmount) : '',
        penaltyAbsentAmount: session.penaltyAbsentAmount ? String(session.penaltyAbsentAmount) : '',
    };
}

// Backend: App\Domain\Enums\WindowType / CheckType — a window can have up
// to two sessions (one time-in, one time-out), but never two of the same
// check (unique(event_day_id, window_type, check_type) in the migration).
const WINDOW_TYPE_OPTIONS = Object.values(WindowType).map((value) => ({
    value,
    label: WINDOW_TYPE_LABEL[value],
}));

const CHECK_TYPE_OPTIONS = Object.values(CheckType).map((value) => ({
    value,
    label: CHECK_TYPE_LABEL[value],
}));

// Backend errors (EventHasOngoingSessionException, and validation
// messages on session creation) already come back as a plain
// { message } JSON body — surface that instead of a generic string so
// the officer sees *why*, not just that something failed.
function extractErrorMessage(error: unknown, fallback: string): string {
    return isAxiosError(error) && typeof error.response?.data?.message === 'string'
        ? error.response.data.message
        : fallback;
}

/** True once a 422 validation error names the given field — e.g. a
 *  day-date edit colliding with another day, or a session's edited
 *  (window_type, check_type) colliding with a sibling check. */
function hasValidationError(error: unknown, field: string): boolean {
    return (
        isAxiosError(error) &&
        error.response?.status === 422 &&
        Boolean((error.response.data?.errors as Record<string, unknown> | undefined)?.[field])
    );
}

// event-day-window-edit-delete-plan.md §4.2/§4.3b: the same "has anything
// started or ended" guard the backend enforces (EventDay::hasAnyStartedOrEndedSession /
// windowHasAnyStartedOrEndedSession) — mirrored here client-side just to
// decide whether to show edit/delete affordances at all. The server
// re-checks for real under a lock either way.
function isLocked(sessions: AttendanceSession[]): boolean {
    return sessions.some((session) => session.status !== SessionStatus.Scheduled);
}

export function EventDaySection({
    day,
    canManage,
    ongoingSessionId,
    onDateCollision,
}: {
    day: EventDayWithSessions;
    canManage: boolean;
    // Id of the one session (in *any* day of this event) currently
    // ongoing, or null if none is. Only one session per event may be
    // ongoing at a time, so every Start button except the one for this
    // exact session gets disabled client-side — see EventHasOngoingSessionException.
    ongoingSessionId: number | null;
    // event-day-window-edit-delete-plan.md §4.5: a day-date edit that
    // collides with another day in the event comes back as a 422 — the
    // resolution path is the Reschedule flow, owned by the parent page
    // (it needs every day in the event, not just this one).
    onDateCollision: () => void;
}) {
    const [isCreating, setIsCreating] = useState(false);
    const [form, setForm] = useState<SessionFormValues>(EMPTY_SESSION_FORM);
    const createSession = useCreateSession(day.id);
    const startSession = useStartSession();
    const endSession = useEndSession();
    const updateSession = useUpdateSession();
    const deleteSession = useDeleteSession();
    const deleteWindow = useDeleteWindow();
    const updateEventDay = useUpdateEventDay();
    const deleteEventDay = useDeleteEventDay();

    const [isEditingDay, setIsEditingDay] = useState(false);
    const [dayDateForm, setDayDateForm] = useState('');
    const [isDeletingDay, setIsDeletingDay] = useState(false);

    const [editingSession, setEditingSession] = useState<AttendanceSession | null>(null);
    const [sessionEditForm, setSessionEditForm] = useState<SessionFormValues>(EMPTY_SESSION_FORM);
    const [deletingSession, setDeletingSession] = useState<AttendanceSession | null>(null);
    const [deletingWindowType, setDeletingWindowType] = useState<string | null>(null);

    const dayLocked = isLocked(day.sessions);

    function handleStart(sessionId: number) {
        startSession.mutate(sessionId, {
            onSuccess: () => toast.success('Session started.'),
            onError: (error) => toast.error(extractErrorMessage(error, 'Could not start session.')),
        });
    }

    function handleEnd(sessionId: number) {
        endSession.mutate(sessionId, {
            onSuccess: () => toast.success('Session ended.'),
            onError: () => toast.error('Could not end session.'),
        });
    }

    function handleSubmit(formEvent: FormEvent) {
        formEvent.preventDefault();

        createSession.mutate(
            {
                window_type: form.windowType,
                check_type: form.checkType,
                start_time: form.startTime,
                end_time: form.endTime,
                grace_minutes: form.graceMinutes ? Number(form.graceMinutes) : null,
                penalty_late_amount: form.penaltyLateAmount ? Number(form.penaltyLateAmount) : null,
                penalty_absent_amount: form.penaltyAbsentAmount ? Number(form.penaltyAbsentAmount) : null,
            },
            {
                onSuccess: () => {
                    toast.success('Session added.');
                    setForm(EMPTY_SESSION_FORM);
                    setIsCreating(false);
                },
                onError: (error) => {
                    toast.error(
                        extractErrorMessage(
                            error,
                            "Could not add session. Check this day doesn't already have that window + check.",
                        ),
                    );
                },
            },
        );
    }

    function openEditDay() {
        setDayDateForm(day.date.slice(0, 10));
        setIsEditingDay(true);
    }

    function handleUpdateDay(formEvent: FormEvent) {
        formEvent.preventDefault();

        updateEventDay.mutate(
            { id: day.id, payload: { date: dayDateForm } },
            {
                onSuccess: () => {
                    toast.success('Day updated.');
                    setIsEditingDay(false);
                },
                onError: (error) => {
                    if (hasValidationError(error, 'date')) {
                        toast.error('That date is already used by another day in this event.', {
                            action: { label: 'Reschedule instead', onClick: onDateCollision },
                        });
                        return;
                    }
                    toast.error(extractErrorMessage(error, 'Could not update day.'));
                },
            },
        );
    }

    function confirmDeleteDay() {
        deleteEventDay.mutate(day.id, {
            onSuccess: (result) => {
                toast.success(
                    result.exclusionsRemoved > 0
                        ? `Day deleted. ${result.exclusionsRemoved} exclusion(s) tied to it were removed too.`
                        : 'Day deleted.',
                );
                setIsDeletingDay(false);
            },
            onError: (error) => {
                toast.error(extractErrorMessage(error, 'Could not delete day.'));
                setIsDeletingDay(false);
            },
        });
    }

    function openEditSession(session: AttendanceSession) {
        setEditingSession(session);
        setSessionEditForm(sessionToForm(session));
    }

    function handleUpdateSession(formEvent: FormEvent) {
        formEvent.preventDefault();
        if (!editingSession) return;

        updateSession.mutate(
            {
                id: editingSession.id,
                payload: {
                    window_type: sessionEditForm.windowType,
                    check_type: sessionEditForm.checkType,
                    start_time: sessionEditForm.startTime,
                    end_time: sessionEditForm.endTime,
                    grace_minutes: sessionEditForm.graceMinutes ? Number(sessionEditForm.graceMinutes) : null,
                    penalty_late_amount: sessionEditForm.penaltyLateAmount ? Number(sessionEditForm.penaltyLateAmount) : null,
                    penalty_absent_amount: sessionEditForm.penaltyAbsentAmount
                        ? Number(sessionEditForm.penaltyAbsentAmount)
                        : null,
                },
            },
            {
                onSuccess: () => {
                    toast.success('Session updated.');
                    setEditingSession(null);
                },
                onError: (error) => {
                    if (hasValidationError(error, 'window_type')) {
                        toast.error('This day already has that window + check combination.');
                        return;
                    }
                    toast.error(extractErrorMessage(error, 'Could not update session.'));
                },
            },
        );
    }

    function confirmDeleteSession() {
        if (!deletingSession) return;

        deleteSession.mutate(deletingSession.id, {
            onSuccess: (result) => {
                toast.success(
                    result.exclusionsRemoved > 0
                        ? `Session deleted. ${result.exclusionsRemoved} exclusion(s) tied to its window were removed too.`
                        : 'Session deleted.',
                );
                setDeletingSession(null);
            },
            onError: (error) => {
                toast.error(extractErrorMessage(error, 'Could not delete session.'));
                setDeletingSession(null);
            },
        });
    }

    function confirmDeleteWindow() {
        if (!deletingWindowType) return;

        deleteWindow.mutate(
            { eventDayId: day.id, windowType: deletingWindowType },
            {
                onSuccess: (result) => {
                    toast.success(
                        result.exclusionsRemoved > 0
                            ? `Window deleted. ${result.exclusionsRemoved} exclusion(s) for it were removed too.`
                            : 'Window deleted.',
                    );
                    setDeletingWindowType(null);
                },
                onError: (error) => {
                    toast.error(extractErrorMessage(error, 'Could not delete window.'));
                    setDeletingWindowType(null);
                },
            },
        );
    }

    // Hide window/check combinations already scheduled today instead of
    // letting the form hit the server's unique(event_day_id, window_type,
    // check_type) constraint.
    const isTaken = (windowType: string, checkType: string) =>
        day.sessions.some((session) => session.windowType === windowType && session.checkType === checkType);

    const availableWindowOptions = WINDOW_TYPE_OPTIONS.filter(
        (option) => !CHECK_TYPE_OPTIONS.every((check) => isTaken(option.value, check.value)),
    );
    const availableCheckOptions = form.windowType
        ? CHECK_TYPE_OPTIONS.filter((option) => !isTaken(form.windowType, option.value))
        : CHECK_TYPE_OPTIONS;

    // Same options, but scoped to the session currently being edited —
    // its own current (window_type, check_type) is always allowed even
    // though it's "taken" (Bug #13 handled server-side; client-side this
    // just needs to not hide the session's own current values from its
    // own edit form).
    const editIsTaken = (windowType: string, checkType: string) =>
        editingSession
            ? day.sessions.some(
                  (session) =>
                      session.id !== editingSession.id &&
                      session.windowType === windowType &&
                      session.checkType === checkType,
              )
            : false;
    const editAvailableWindowOptions = WINDOW_TYPE_OPTIONS.filter(
        (option) => !CHECK_TYPE_OPTIONS.every((check) => editIsTaken(option.value, check.value)),
    );
    const editAvailableCheckOptions = sessionEditForm.windowType
        ? CHECK_TYPE_OPTIONS.filter((option) => !editIsTaken(sessionEditForm.windowType, option.value))
        : CHECK_TYPE_OPTIONS;

    // Group sessions by window_type for display — a "window" (e.g.
    // Morning) is up to two AttendanceSession rows (time-in/time-out)
    // sharing one window_type, not a row of its own (event-day-window-
    // edit-delete-plan.md §1). Grouped so "delete whole window" reads as
    // one action over the pair rather than two separate row-level deletes.
    const windowGroups = Object.values(WindowType)
        .map((windowType) => ({
            windowType,
            sessions: day.sessions.filter((session) => session.windowType === windowType),
        }))
        .filter((group) => group.sessions.length > 0);

    return (
        <div className="space-y-4 rounded-3xl border border-border bg-card p-4">
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <Tile tone="sky" size="sm" variant="soft" Icon={CalendarDays} />
                    <div className="min-w-0">
                        <Text variant="small" className="font-medium text-foreground">
                            Day {day.dayNumber}
                        </Text>
                        <Text variant="caption">{formatDate(day.date)}</Text>
                    </div>
                </div>

                {canManage && !dayLocked && (
                    <div className="flex shrink-0 gap-1">
                        <Button size="icon" variant="ghost" className="size-7 text-muted-foreground" onClick={openEditDay} aria-label="Edit day">
                            <Pencil className="size-3.5" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7 text-muted-foreground hover:text-destructive"
                            onClick={() => setIsDeletingDay(true)}
                            aria-label="Delete day"
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    </div>
                )}
            </div>

            {day.sessions.length === 0 && !isCreating && (
                <Text variant="small" className="px-0.5">
                    No sessions yet — add the windows this day runs.
                </Text>
            )}

            <div className="space-y-2">
                {windowGroups.map((group) => {
                    const groupLocked = isLocked(group.sessions);

                    return (
                        <div key={group.windowType} className="space-y-2">
                            {canManage && !groupLocked && (
                                <div className="flex items-center justify-between px-0.5">
                                    <Text variant="caption" className="font-medium text-muted-foreground">
                                        {WINDOW_TYPE_LABEL[group.windowType]}
                                    </Text>
                                    <button
                                        type="button"
                                        onClick={() => setDeletingWindowType(group.windowType)}
                                        className="text-caption font-medium text-muted-foreground transition-colors hover:text-destructive"
                                    >
                                        Delete window
                                    </button>
                                </div>
                            )}

                            {group.sessions.map((session) => {
                                const windowStyle = WINDOW_STYLE[session.windowType] ?? { Icon: Sun, tone: 'neutral' as Tone };
                                const isOngoing = session.status === SessionStatus.Ongoing;
                                const isScheduled = session.status === SessionStatus.Scheduled;

                                return (
                                    <div
                                        key={session.id}
                                        className={cn(
                                            'flex items-center justify-between gap-4 rounded-2xl border border-border p-4',
                                            // Only the running session is filled and washed.
                                            // Everything else on the day stays quiet.
                                            isOngoing && TONE.emerald.wash,
                                        )}
                                    >
                                        <div className="flex min-w-0 items-center gap-4">
                                            <Tile tone={windowStyle.tone} variant={isOngoing ? 'solid' : 'soft'} Icon={windowStyle.Icon} />
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Text variant="small" className="font-medium text-foreground">
                                                        {WINDOW_TYPE_LABEL[session.windowType]} · {CHECK_TYPE_LABEL[session.checkType]}
                                                    </Text>
                                                    <Badge variant="secondary" className={SESSION_STATUS_BADGE_CLASS[session.status]}>
                                                        {session.status}
                                                    </Badge>
                                                </div>
                                                <Text variant="caption">
                                                    {session.startTime}–{session.endTime}
                                                    {session.graceMinutes ? ` · +${session.graceMinutes}m grace` : ''}
                                                </Text>
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 items-center gap-1">
                                            {canManage && isScheduled && (
                                                <>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-7 text-muted-foreground"
                                                        onClick={() => openEditSession(session)}
                                                        aria-label="Edit session"
                                                    >
                                                        <Pencil className="size-3.5" />
                                                    </Button>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-7 text-muted-foreground hover:text-destructive"
                                                        onClick={() => setDeletingSession(session)}
                                                        aria-label="Delete session"
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </Button>
                                                </>
                                            )}

                                            {canManage && isScheduled && (
                                                <Button
                                                    size="sm"
                                                    onClick={() => handleStart(session.id)}
                                                    disabled={
                                                        startSession.isPending ||
                                                        (ongoingSessionId !== null && ongoingSessionId !== session.id)
                                                    }
                                                    title={
                                                        ongoingSessionId !== null && ongoingSessionId !== session.id
                                                            ? 'Another session in this event is already ongoing. End it first.'
                                                            : undefined
                                                    }
                                                >
                                                    {startSession.isPending ? 'Starting…' : 'Start'}
                                                </Button>
                                            )}

                                            {canManage && isOngoing && (
                                                <Button
                                                    size="sm"
                                                    variant="destructive"
                                                    onClick={() => handleEnd(session.id)}
                                                    disabled={endSession.isPending}
                                                >
                                                    {endSession.isPending ? 'Ending…' : 'End'}
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    );
                })}
            </div>

            {canManage && availableWindowOptions.length > 0 && (
                <button
                    type="button"
                    onClick={() => setIsCreating(true)}
                    className="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-border p-4 text-small font-medium text-muted-foreground transition-colors hover:border-sky-500/40 hover:text-foreground"
                >
                    <Plus className="size-4" />
                    Add session
                </button>
            )}

            <Dialog
                open={isCreating}
                onOpenChange={(open) => {
                    setIsCreating(open);
                    if (!open) setForm(EMPTY_SESSION_FORM);
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add session</DialogTitle>
                        <DialogDescription>
                            Day {day.dayNumber} — {formatDate(day.date)}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor={`sessionWindow-${day.id}`}>Window</Label>
                                <Select
                                    value={form.windowType}
                                    onValueChange={(value) =>
                                        setForm((prev) => ({ ...prev, windowType: value, checkType: '' }))
                                    }
                                    required
                                >
                                    <SelectTrigger id={`sessionWindow-${day.id}`} className="w-full">
                                        <SelectValue placeholder="Select a window…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableWindowOptions.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`sessionCheck-${day.id}`}>Check type</Label>
                                <Select
                                    value={form.checkType}
                                    onValueChange={(value) => setForm((prev) => ({ ...prev, checkType: value }))}
                                    disabled={!form.windowType}
                                    required
                                >
                                    <SelectTrigger id={`sessionCheck-${day.id}`} className="w-full">
                                        <SelectValue placeholder="Select a check…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableCheckOptions.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor={`startTime-${day.id}`}>Start time</Label>
                                <Input
                                    id={`startTime-${day.id}`}
                                    type="time"
                                    value={form.startTime}
                                    onChange={(e) => setForm((prev) => ({ ...prev, startTime: e.target.value }))}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`endTime-${day.id}`}>End time</Label>
                                <Input
                                    id={`endTime-${day.id}`}
                                    type="time"
                                    value={form.endTime}
                                    onChange={(e) => setForm((prev) => ({ ...prev, endTime: e.target.value }))}
                                    required
                                />
                            </div>
                        </div>

                        <div className="space-y-2 rounded-2xl border border-border p-4">
                            <Label htmlFor={`graceMinutes-${day.id}`}>Grace period (minutes)</Label>
                            <Text variant="caption">Scans inside this window still count as Present.</Text>
                            <Input
                                id={`graceMinutes-${day.id}`}
                                type="number"
                                min={0}
                                placeholder="Optional"
                                value={form.graceMinutes}
                                onChange={(e) => setForm((prev) => ({ ...prev, graceMinutes: e.target.value }))}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor={`penaltyLate-${day.id}`}>Late penalty</Label>
                                <Input
                                    id={`penaltyLate-${day.id}`}
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    placeholder="Optional"
                                    value={form.penaltyLateAmount}
                                    onChange={(e) => setForm((prev) => ({ ...prev, penaltyLateAmount: e.target.value }))}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`penaltyAbsent-${day.id}`}>Absent penalty</Label>
                                <Input
                                    id={`penaltyAbsent-${day.id}`}
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    placeholder="Optional"
                                    value={form.penaltyAbsentAmount}
                                    onChange={(e) =>
                                        setForm((prev) => ({ ...prev, penaltyAbsentAmount: e.target.value }))
                                    }
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setIsCreating(false);
                                    setForm(EMPTY_SESSION_FORM);
                                }}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={createSession.isPending}>
                                {createSession.isPending ? 'Adding…' : 'Add session'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit day date */}
            <Dialog open={isEditingDay} onOpenChange={setIsEditingDay}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Edit day {day.dayNumber}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleUpdateDay} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor={`editDayDate-${day.id}`}>Date</Label>
                            <Input
                                id={`editDayDate-${day.id}`}
                                type="date"
                                value={dayDateForm}
                                onChange={(e) => setDayDateForm(e.target.value)}
                                required
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsEditingDay(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={updateEventDay.isPending}>
                                {updateEventDay.isPending ? 'Saving…' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete day */}
            <AlertDialog open={isDeletingDay} onOpenChange={setIsDeletingDay}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete day {day.dayNumber}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This removes every session scheduled on this day. This can't be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDeleteDay} className={buttonVariants({ variant: 'destructive' })}>
                            Yes, delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Edit session */}
            <Dialog
                open={editingSession !== null}
                onOpenChange={(open) => {
                    if (!open) setEditingSession(null);
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit session</DialogTitle>
                        <DialogDescription>
                            Day {day.dayNumber} — {formatDate(day.date)}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleUpdateSession} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor={`editSessionWindow-${day.id}`}>Window</Label>
                                <Select
                                    value={sessionEditForm.windowType}
                                    onValueChange={(value) =>
                                        setSessionEditForm((prev) => ({ ...prev, windowType: value, checkType: '' }))
                                    }
                                    required
                                >
                                    <SelectTrigger id={`editSessionWindow-${day.id}`} className="w-full">
                                        <SelectValue placeholder="Select a window…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {editAvailableWindowOptions.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`editSessionCheck-${day.id}`}>Check type</Label>
                                <Select
                                    value={sessionEditForm.checkType}
                                    onValueChange={(value) => setSessionEditForm((prev) => ({ ...prev, checkType: value }))}
                                    disabled={!sessionEditForm.windowType}
                                    required
                                >
                                    <SelectTrigger id={`editSessionCheck-${day.id}`} className="w-full">
                                        <SelectValue placeholder="Select a check…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {editAvailableCheckOptions.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor={`editStartTime-${day.id}`}>Start time</Label>
                                <Input
                                    id={`editStartTime-${day.id}`}
                                    type="time"
                                    value={sessionEditForm.startTime}
                                    onChange={(e) => setSessionEditForm((prev) => ({ ...prev, startTime: e.target.value }))}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`editEndTime-${day.id}`}>End time</Label>
                                <Input
                                    id={`editEndTime-${day.id}`}
                                    type="time"
                                    value={sessionEditForm.endTime}
                                    onChange={(e) => setSessionEditForm((prev) => ({ ...prev, endTime: e.target.value }))}
                                    required
                                />
                            </div>
                        </div>

                        <div className="space-y-2 rounded-2xl border border-border p-4">
                            <Label htmlFor={`editGraceMinutes-${day.id}`}>Grace period (minutes)</Label>
                            <Text variant="caption">Scans inside this window still count as Present.</Text>
                            <Input
                                id={`editGraceMinutes-${day.id}`}
                                type="number"
                                min={0}
                                placeholder="Optional"
                                value={sessionEditForm.graceMinutes}
                                onChange={(e) => setSessionEditForm((prev) => ({ ...prev, graceMinutes: e.target.value }))}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor={`editPenaltyLate-${day.id}`}>Late penalty</Label>
                                <Input
                                    id={`editPenaltyLate-${day.id}`}
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    placeholder="Optional"
                                    value={sessionEditForm.penaltyLateAmount}
                                    onChange={(e) =>
                                        setSessionEditForm((prev) => ({ ...prev, penaltyLateAmount: e.target.value }))
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`editPenaltyAbsent-${day.id}`}>Absent penalty</Label>
                                <Input
                                    id={`editPenaltyAbsent-${day.id}`}
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    placeholder="Optional"
                                    value={sessionEditForm.penaltyAbsentAmount}
                                    onChange={(e) =>
                                        setSessionEditForm((prev) => ({ ...prev, penaltyAbsentAmount: e.target.value }))
                                    }
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditingSession(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={updateSession.isPending}>
                                {updateSession.isPending ? 'Saving…' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete session */}
            <AlertDialog open={deletingSession !== null} onOpenChange={(open) => !open && setDeletingSession(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this session?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deletingSession && (
                                <>
                                    {WINDOW_TYPE_LABEL[deletingSession.windowType]} ·{' '}
                                    {CHECK_TYPE_LABEL[deletingSession.checkType]} will be removed. This can't be undone.
                                </>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDeleteSession} className={buttonVariants({ variant: 'destructive' })}>
                            Yes, delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Delete whole window */}
            <AlertDialog open={deletingWindowType !== null} onOpenChange={(open) => !open && setDeletingWindowType(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this window?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deletingWindowType && (
                                <>
                                    Every check under {WINDOW_TYPE_LABEL[deletingWindowType as keyof typeof WINDOW_TYPE_LABEL]} on this
                                    day will be removed. This can't be undone.
                                </>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDeleteWindow} className={buttonVariants({ variant: 'destructive' })}>
                            Yes, delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
