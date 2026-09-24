import { type FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { toast } from 'sonner';
import { CalendarDays, CalendarPlus, ChevronRight, Radio, TriangleAlert } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuthStore } from '@/application/auth/auth.store';
import { useActiveAcademicYear } from '@/application/academic-years/use-academic-years';
import { useSemesters } from '@/application/semesters/use-semesters';
import { useDepartments } from '@/application/departments/use-departments';
import { useEvents } from '@/application/events/use-events';
import { useCreateEvent } from '@/application/events/use-create-event';
import {
    EVENT_STATUS_BADGE_CLASS,
    EVENT_STATUS_LABEL,
    EventStatus,
    Role,
    SEMESTER_LABEL,
    type Semester as SemesterTerm,
} from '@/domain/enums';
import { cn } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { EmptyState, ListSkeleton } from '@/presentation/components/empty-state';
import { Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import { CHIP } from '@/presentation/components/spacing';

const MANAGE_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin];

interface EventFormValues {
    name: string;
    description: string;
    departmentIds: number[];
}

const EMPTY_FORM: EventFormValues = { name: '', description: '', departmentIds: [] };

export function EventsPage() {
    const student = useAuthStore((state) => state.student);
    const { activeAcademicYear, isLoading: isLoadingAcademicYear } = useActiveAcademicYear();
    const { data: semesters, isLoading: isLoadingSemesters } = useSemesters(activeAcademicYear?.id ?? null);
    const { data: events, isLoading: isLoadingEvents } = useEvents();
    const { data: departments, isLoading: isLoadingDepartments } = useDepartments();
    const createEvent = useCreateEvent();

    const [isCreating, setIsCreating] = useState(false);
    const [form, setForm] = useState<EventFormValues>(EMPTY_FORM);

    // Every checkbox starts checked (spec: an event is open to everyone
    // unless a CSG Admin deliberately narrows it) — reset to "all" each
    // time the department list resolves or the dialog reopens, rather
    // than starting empty and forcing a "select all" click every time.
    useEffect(() => {
        if (isCreating && departments) {
            setForm((prev) => ({ ...prev, departmentIds: departments.map((department) => department.id) }));
        }
        // Only when the dialog opens / the department list first loads —
        // not on every department toggle, which would fight the user's
        // own unchecking.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isCreating, departments?.length]);

    if (!student) return null;

    const canManage = MANAGE_ROLES.includes(student.role);

    function resetCreateForm() {
        setIsCreating(false);
        setForm(EMPTY_FORM);
    }

    function toggleDepartment(departmentId: number, checked: boolean) {
        setForm((prev) => ({
            ...prev,
            departmentIds: checked ? [...prev.departmentIds, departmentId] : prev.departmentIds.filter((id) => id !== departmentId),
        }));
    }

    function handleCreateSubmit(event: FormEvent) {
        event.preventDefault();

        if (form.departmentIds.length === 0) {
            toast.error('Select at least one department.');
            return;
        }

        createEvent.mutate(
            {
                name: form.name,
                description: form.description || null,
                department_ids: form.departmentIds,
            },
            {
                onSuccess: () => {
                    toast.success('Event created.');
                    resetCreateForm();
                },
                onError: (error) => {
                    toast.error(
                        isAxiosError(error) && typeof error.response?.data?.message === 'string'
                            ? error.response.data.message
                            : 'Could not create event.',
                    );
                },
            },
        );
    }

    // Every event is attached to whichever semester is currently active
    // (CreateEvent resolves it server-side — there's no picker here), so
    // there's nothing useful to create until an active academic year with
    // an active semester exists. Guide the admin there instead of showing
    // a form that can only fail.
    const canCreate = canManage && !isLoadingAcademicYear && !isLoadingSemesters;
    const hasActiveYear = !!activeAcademicYear;
    const activeSemester = semesters?.find((semester) => semester.isActive) ?? null;
    const hasActiveSemester = !!activeSemester;
    const allDepartmentsSelected = !!departments && form.departmentIds.length === departments.length;

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <Heading level="h1">Events</Heading>
                    <Text variant="small">Every event is scoped to a semester within the active academic year.</Text>
                </div>
                {canManage && hasActiveYear && hasActiveSemester && (
                    <Button onClick={() => setIsCreating(true)} size="sm" className="shrink-0 gap-2">
                        <CalendarPlus className="size-4" />
                        Add event
                    </Button>
                )}
            </div>

            {/*
              Both blockers below are "do this first" states, not failures —
              amber rather than red, and each one hands over the link it's
              telling you to follow instead of describing where to go.
            */}
            {canManage && !isLoadingAcademicYear && !hasActiveYear && (
                <EmptyState
                    Icon={TriangleAlert}
                    tone="amber"
                    title="No active academic year"
                    description="An event is created under the active year's active semester, so one has to exist first."
                    action={
                        <Button asChild size="sm" variant="outline">
                            <Link to="/academic-years">Go to Academic Years</Link>
                        </Button>
                    }
                />
            )}

            {canManage && hasActiveYear && !isLoadingSemesters && !hasActiveSemester && (
                <EmptyState
                    Icon={TriangleAlert}
                    tone="amber"
                    title={`${activeAcademicYear?.name} has no active semester`}
                    description="Activate one before creating an event — the semester is set at creation and can't be changed afterward."
                    action={
                        <Button asChild size="sm" variant="outline">
                            <Link to="/academic-years">Go to Academic Years</Link>
                        </Button>
                    }
                />
            )}

            {isLoadingEvents && <ListSkeleton rows={3} />}

            {!isLoadingEvents && events?.length === 0 && (
                <EmptyState
                    Icon={CalendarDays}
                    title="No events yet"
                    description={
                        canManage
                            ? 'Create one to start scheduling days and attendance sessions.'
                            : 'Events show up here once CSG publishes them.'
                    }
                />
            )}

            <div className="space-y-4">
                {events?.map((event) => {
                    const isOngoing = event.status === EventStatus.Ongoing;

                    return (
                        <Link
                            key={event.id}
                            to={`/events/${event.id}`}
                            className={cn(
                                'flex items-center gap-4 rounded-2xl border bg-card p-4 transition-all hover:shadow-md active:scale-[0.995]',
                                isOngoing && TONE.emerald.wash,
                            )}
                        >
                            {/* Lit only while the event is running. A list of
                                fifteen finished events shouldn't glow — the
                                one that's live should be findable instantly. */}
                            <Tile
                                tone={isOngoing ? 'emerald' : 'neutral'}
                                size="lg"
                                variant={isOngoing ? 'solid' : 'soft'}
                                Icon={isOngoing ? Radio : CalendarDays}
                            />

                            <div className="min-w-0 flex-1 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Text className="truncate font-medium">{event.name}</Text>
                                    <Badge variant="secondary" className={EVENT_STATUS_BADGE_CLASS[event.status]}>
                                        {EVENT_STATUS_LABEL[event.status]}
                                    </Badge>
                                </div>

                                <Text variant="caption" className="truncate">
                                    {[
                                        event.semesterTerm ? SEMESTER_LABEL[event.semesterTerm as SemesterTerm] : null,
                                        event.academicYearName,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </Text>

                                {event.description && (
                                    <Text variant="small" className="line-clamp-1">
                                        {event.description}
                                    </Text>
                                )}

                                {/* Only worth showing when the event is narrowed to
                                    some departments — "all of them" is the default
                                    and says nothing. */}
                                {departments && event.departments.length < departments.length && (
                                    <div className="flex flex-wrap gap-2 pt-1">
                                        {event.departments.map((department) => (
                                            <span key={department.id} className={cn(CHIP, TONE.violet.chip)}>
                                                {department.code}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                        </Link>
                    );
                })}
            </div>

            {events && events.length > 0 && (
                <Text variant="caption" className="px-0.5">
                    An event's semester, and the academic year it belongs to, are set at creation and can't be changed afterward.
                </Text>
            )}

            <Dialog open={isCreating && canCreate} onOpenChange={(open) => (open ? setIsCreating(true) : resetCreateForm())}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>New event</DialogTitle>
                        {activeSemester && (
                            <DialogDescription>
                                This event will be created under{' '}
                                <span className="font-medium text-foreground">{SEMESTER_LABEL[activeSemester.name as SemesterTerm]}</span>,
                                the active semester of {activeAcademicYear?.name}.
                            </DialogDescription>
                        )}
                    </DialogHeader>
                    <form onSubmit={handleCreateSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="eventName">Name</Label>
                            <Input
                                id="eventName"
                                placeholder="Freshmen Orientation"
                                value={form.name}
                                onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="eventDescription">Description</Label>
                            <Input
                                id="eventDescription"
                                placeholder="Optional"
                                value={form.description}
                                onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
                            />
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Departments</Label>
                                <button
                                    type="button"
                                    className="text-caption font-medium text-violet-600 underline-offset-4 hover:underline dark:text-violet-400"
                                    onClick={() =>
                                        setForm((prev) => ({
                                            ...prev,
                                            departmentIds: allDepartmentsSelected ? [] : (departments ?? []).map((d) => d.id),
                                        }))
                                    }
                                >
                                    {allDepartmentsSelected ? 'Clear all' : 'Select all'}
                                </button>
                            </div>
                            <Text variant="caption">
                                Every department is included by default. Uncheck any that shouldn't be part of this event — e.g. an
                                intramural just for BSIT and BEd.
                            </Text>
                            {isLoadingDepartments && <Text variant="small">Loading departments…</Text>}
                            <div className="max-h-56 space-y-2 overflow-y-auto rounded-2xl border border-border p-2">
                                {departments?.map((department) => {
                                    const checked = form.departmentIds.includes(department.id);

                                    return (
                                        <label
                                            key={department.id}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-2 rounded-xl px-2 py-2 text-sm transition-colors',
                                                checked ? 'bg-violet-500/8 dark:bg-violet-500/12' : 'hover:bg-muted',
                                            )}
                                        >
                                            <Checkbox
                                                checked={checked}
                                                onCheckedChange={(value) => toggleDepartment(department.id, value === true)}
                                            />
                                            <span className="min-w-0 flex-1 truncate">{department.name}</span>
                                            <span
                                                className={cn(CHIP, 'shrink-0', checked ? TONE.violet.chip : 'bg-muted text-muted-foreground')}
                                            >
                                                {department.code}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={resetCreateForm}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={createEvent.isPending}>
                                {createEvent.isPending ? 'Creating…' : 'Create'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
