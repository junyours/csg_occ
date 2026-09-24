import { type FormEvent, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, Ban, CalendarDays, SearchX, Trash2, Upload, UserX } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useEvents } from '@/application/events/use-events';
import { useCreateBulkExclusions } from '@/application/exclusions/use-create-bulk-exclusions';
import { useCreateExclusion } from '@/application/exclusions/use-create-exclusion';
import { useExclusions } from '@/application/exclusions/use-exclusions';
import { usePreviewBulkExclusions } from '@/application/exclusions/use-preview-bulk-exclusions';
import { useRemoveExclusion } from '@/application/exclusions/use-remove-exclusion';
import type { ExclusionListItem } from '@/infrastructure/exclusions/exclusions.repository.http';
import { EXCLUSION_SCOPE_LABEL, ExclusionScope, WINDOW_TYPE_LABEL, WindowType } from '@/domain/enums';
import type { EventDayWithSessions } from '@/domain/entities';
import { formatDate, formatStudentNumber } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { EmptyState, ListSkeleton, SectionHeader } from '@/presentation/components/empty-state';

interface AddFormValues {
    scope: ExclusionScope;
    eventDayId: string;
    windowType: WindowType | '';
    studentNumber: string;
    reason: string;
}

// student-exclusion-feature-plan.md §7: "Default scope selector starts
// at the narrowest option (Window) to avoid accidental over-broad
// exclusion; CSG must deliberately select Day or Event scope."
const EMPTY_ADD_FORM: AddFormValues = {
    scope: ExclusionScope.Window,
    eventDayId: '',
    windowType: '',
    studentNumber: '',
    reason: '',
};

/** Whether a given day still has at least one window CSG could target. */
function dayHasOpenWindow(day: EventDayWithSessions): boolean {
    return day.sessions.some((session) => session.status !== 'ended');
}

function windowHasEnded(day: EventDayWithSessions | undefined, windowType: WindowType): boolean {
    const sessions = day?.sessions.filter((session) => session.windowType === windowType) ?? [];
    return sessions.length > 0 && sessions.every((session) => session.status === 'ended');
}

function dayHasEnded(day: EventDayWithSessions | undefined): boolean {
    if (!day || day.sessions.length === 0) return false;
    return day.sessions.every((session) => session.status === 'ended');
}

/** Groups a flat exclusion list into Event-wide → Day 1 → Day 2 → ... per §4B. */
function groupExclusions(exclusions: ExclusionListItem[]) {
    const eventWide = exclusions.filter((e) => e.scope === ExclusionScope.Event);
    const byDay = new Map<number, ExclusionListItem[]>();

    for (const exclusion of exclusions) {
        if (exclusion.scope === ExclusionScope.Event || exclusion.dayNumber === null) continue;
        const list = byDay.get(exclusion.dayNumber) ?? [];
        list.push(exclusion);
        byDay.set(exclusion.dayNumber, list);
    }

    const days = [...byDay.entries()].sort(([a], [b]) => a - b);

    return { eventWide, days };
}

function ExclusionRow({ exclusion, onRemove, isRemoving }: { exclusion: ExclusionListItem; onRemove: () => void; isRemoving: boolean }) {
    const isRemoved = exclusion.status === 'removed';

    return (
        <div className="flex items-start justify-between gap-4 rounded-2xl border bg-card p-4">
            <div className="min-w-0 flex-1 space-y-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Text className="font-medium">{exclusion.studentName}</Text>
                    <Text variant="caption" className="text-muted-foreground">
                        {exclusion.studentNumber}
                    </Text>
                    {exclusion.windowType && (
                        <Badge variant="secondary" className="text-xs">
                            {WINDOW_TYPE_LABEL[exclusion.windowType]}
                        </Badge>
                    )}
                    {isRemoved && (
                        <Badge variant="secondary" className="border-transparent bg-muted text-xs text-muted-foreground">
                            Removed
                        </Badge>
                    )}
                </div>
                <Text variant="small" className="text-muted-foreground">
                    {exclusion.reason}
                </Text>
                <Text variant="caption" className="text-muted-foreground">
                    Added by {exclusion.createdBy} on {formatDate(exclusion.createdAt)}
                    {isRemoved && exclusion.removedBy && ` · Removed by ${exclusion.removedBy}`}
                    {exclusion.batchId && ' · Bulk upload'}
                </Text>
            </div>

            {!isRemoved && (
                <Button
                    size="icon"
                    variant="ghost"
                    className="shrink-0 text-muted-foreground hover:text-destructive"
                    disabled={exclusion.targetHasEnded || isRemoving}
                    title={exclusion.targetHasEnded ? 'Ended — cannot modify' : 'Remove exclusion'}
                    onClick={onRemove}
                >
                    <Trash2 className="size-4" />
                </Button>
            )}
        </div>
    );
}

export function ManageExclusionsPage() {
    const { eventId } = useParams<{ eventId: string }>();
    const navigate = useNavigate();
    const numericEventId = Number(eventId);

    const { data: events, isLoading: isLoadingEvent } = useEvents();
    const event = events?.find((candidate) => candidate.id === numericEventId);
    const sortedDays = useMemo(() => (event ? [...event.days].sort((a, b) => a.dayNumber - b.dayNumber) : []), [event]);

    const { data: exclusions, isLoading: isLoadingExclusions } = useExclusions(numericEventId);
    const createExclusion = useCreateExclusion();
    const removeExclusion = useRemoveExclusion(numericEventId);
    const previewBulk = usePreviewBulkExclusions(numericEventId);
    const createBulk = useCreateBulkExclusions(numericEventId);

    const [isAddOpen, setIsAddOpen] = useState(false);
    const [addForm, setAddForm] = useState<AddFormValues>(EMPTY_ADD_FORM);

    const [isBulkOpen, setIsBulkOpen] = useState(false);
    const [bulkFile, setBulkFile] = useState<File | null>(null);
    const [bulkReason, setBulkReason] = useState('');
    const [bulkFileInputKey, setBulkFileInputKey] = useState(0);

    const [removingId, setRemovingId] = useState<number | null>(null);

    if (isLoadingEvent) {
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

    const selectedDay = sortedDays.find((day) => String(day.id) === addForm.eventDayId);
    const availableWindows = selectedDay
        ? [...new Set(selectedDay.sessions.map((session) => session.windowType))].filter(
              (windowType) => !windowHasEnded(selectedDay, windowType),
          )
        : [];

    function resetAddForm() {
        setAddForm(EMPTY_ADD_FORM);
    }

    function handleAddSubmit(formEvent: FormEvent) {
        formEvent.preventDefault();

        if (addForm.studentNumber.trim() === '' || addForm.reason.trim() === '') {
            toast.error('Student number and reason are both required.');
            return;
        }
        if (addForm.scope !== ExclusionScope.Event && addForm.eventDayId === '') {
            toast.error('Pick a day.');
            return;
        }
        if (addForm.scope === ExclusionScope.Window && addForm.windowType === '') {
            toast.error('Pick a window.');
            return;
        }

        createExclusion.mutate(
            {
                eventId: numericEventId,
                scope: addForm.scope,
                eventDayId: addForm.scope !== ExclusionScope.Event ? Number(addForm.eventDayId) : undefined,
                windowType: addForm.scope === ExclusionScope.Window ? (addForm.windowType as WindowType) : undefined,
                reason: addForm.reason.trim(),
                studentNumber: addForm.studentNumber.trim(),
            },
            {
                onSuccess: (result) => {
                    toast.success('Student excluded.');

                    // student-exclusion-feature-plan.md §6a point 1: the add
                    // succeeded, but CSG needs telling that it does not take
                    // effect for a window the student already scanned into.
                    // Shown as a longer-lived warning toast, one per affected
                    // session, so it isn't lost behind the success toast.
                    for (const warning of result.warnings) {
                        toast.warning(warning, { duration: 8000 });
                    }

                    resetAddForm();
                    setIsAddOpen(false);
                },
                onError: (error: unknown) => {
                    const message =
                        (error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Could not add exclusion.';
                    toast.error(message);
                },
            },
        );
    }

    function confirmRemove(exclusion: ExclusionListItem) {
        setRemovingId(exclusion.id);
    }

    function handleRemoveConfirmed() {
        if (removingId === null) return;

        removeExclusion.mutate(removingId, {
            onSuccess: () => {
                toast.success('Exclusion removed.');
                setRemovingId(null);
            },
            onError: (error: unknown) => {
                const message =
                    (error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Could not remove exclusion.';
                toast.error(message);
                setRemovingId(null);
            },
        });
    }

    function handleBulkFileChange(fileList: FileList | null) {
        setBulkFile(fileList?.[0] ?? null);
        previewBulk.reset();
    }

    function handleBulkPreview() {
        if (!bulkFile) {
            toast.error('Choose a CSV file first.');
            return;
        }
        previewBulk.mutate(bulkFile);
    }

    function handleBulkConfirm() {
        if (!bulkFile || bulkReason.trim() === '') {
            toast.error('A reason is required for the whole batch.');
            return;
        }

        createBulk.mutate(
            { file: bulkFile, reason: bulkReason.trim() },
            {
                onSuccess: (result) => {
                    toast.success(`${result.excluded} student(s) excluded.${result.failed > 0 ? ` ${result.failed} row(s) failed.` : ''}`);
                    setIsBulkOpen(false);
                    setBulkFile(null);
                    setBulkReason('');
                    previewBulk.reset();
                    setBulkFileInputKey((key) => key + 1);
                },
                onError: () => toast.error('Could not process the bulk upload.'),
            },
        );
    }

    const { eventWide, days } = groupExclusions(exclusions ?? []);
    const removingExclusion = exclusions?.find((e) => e.id === removingId) ?? null;

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            <div className="flex items-center gap-4">
                <Button size="icon" variant="ghost" onClick={() => navigate(`/events/${event.id}`)}>
                    <ArrowLeft className="size-4" />
                </Button>
                <div className="min-w-0 flex-1">
                    <Heading level="h2">Manage Exclusions</Heading>
                    <Text variant="small" className="text-muted-foreground">
                        {event.name}
                    </Text>
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    onClick={() => {
                        resetAddForm();
                        setIsAddOpen(true);
                    }}
                >
                    <UserX className="size-4" />
                    Add exclusion
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        setBulkFile(null);
                        setBulkReason('');
                        previewBulk.reset();
                        setIsBulkOpen(true);
                    }}
                >
                    <Upload className="size-4" />
                    Bulk upload
                </Button>
            </div>

            {isLoadingExclusions ? (
                <ListSkeleton rows={3} />
            ) : (exclusions ?? []).length === 0 ? (
                <EmptyState Icon={Ban} title="No exclusions yet" description="Students excluded from this event will show up here." />
            ) : (
                <div className="space-y-6">
                    {eventWide.length > 0 && (
                        <div className="space-y-2">
                            <SectionHeader Icon={Ban} tone="red">
                                Event-wide
                            </SectionHeader>
                            {eventWide.map((exclusion) => (
                                <ExclusionRow
                                    key={exclusion.id}
                                    exclusion={exclusion}
                                    isRemoving={removeExclusion.isPending}
                                    onRemove={() => confirmRemove(exclusion)}
                                />
                            ))}
                        </div>
                    )}

                    {days.map(([dayNumber, dayExclusions]) => (
                        <div key={dayNumber} className="space-y-2">
                            <SectionHeader Icon={CalendarDays} tone="sky">
                                {`Day ${dayNumber}`}
                            </SectionHeader>
                            {dayExclusions.map((exclusion) => (
                                <ExclusionRow
                                    key={exclusion.id}
                                    exclusion={exclusion}
                                    isRemoving={removeExclusion.isPending}
                                    onRemove={() => confirmRemove(exclusion)}
                                />
                            ))}
                        </div>
                    ))}
                </div>
            )}

            {/* Add Exclusion dialog — single student, any scope (student-exclusion-feature-plan.md §5). */}
            <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add exclusion</DialogTitle>
                        <DialogDescription>
                            {addForm.scope === ExclusionScope.Event &&
                                'Applies to the whole event — every day and window that has not ended yet.'}
                            {addForm.scope === ExclusionScope.Day && 'Applies to the selected day only. It will not carry over to other days.'}
                            {addForm.scope === ExclusionScope.Window &&
                                'Applies to this day and window only. It will not carry over to other windows or days unless added separately.'}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleAddSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Scope</Label>
                            <Select
                                value={addForm.scope}
                                onValueChange={(value) =>
                                    setAddForm((prev) => ({ ...prev, scope: value as ExclusionScope, eventDayId: '', windowType: '' }))
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ExclusionScope.Window}>{EXCLUSION_SCOPE_LABEL[ExclusionScope.Window]}</SelectItem>
                                    <SelectItem value={ExclusionScope.Day}>{EXCLUSION_SCOPE_LABEL[ExclusionScope.Day]}</SelectItem>
                                    <SelectItem value={ExclusionScope.Event}>{EXCLUSION_SCOPE_LABEL[ExclusionScope.Event]}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {addForm.scope !== ExclusionScope.Event && (
                            <div className="space-y-2">
                                <Label>Day</Label>
                                <Select
                                    value={addForm.eventDayId}
                                    onValueChange={(value) => setAddForm((prev) => ({ ...prev, eventDayId: value, windowType: '' }))}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Select a day" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sortedDays.map((day) => {
                                            const ended = dayHasEnded(day);
                                            const disabled = ended || !dayHasOpenWindow(day);
                                            return (
                                                <SelectItem key={day.id} value={String(day.id)} disabled={disabled}>
                                                    Day {day.dayNumber} ({formatDate(day.date)})
                                                    {ended ? ' — Ended — cannot modify' : ''}
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        {addForm.scope === ExclusionScope.Window && (
                            <div className="space-y-2">
                                <Label>Window</Label>
                                <Select
                                    value={addForm.windowType}
                                    onValueChange={(value) => setAddForm((prev) => ({ ...prev, windowType: value as WindowType }))}
                                    disabled={!selectedDay}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Select a window" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {selectedDay &&
                                            [...new Set(selectedDay.sessions.map((s) => s.windowType))].map((windowType) => {
                                                const ended = windowHasEnded(selectedDay, windowType);
                                                return (
                                                    <SelectItem key={windowType} value={windowType} disabled={ended}>
                                                        {WINDOW_TYPE_LABEL[windowType]}
                                                        {ended ? ' — Ended — cannot modify' : ''}
                                                    </SelectItem>
                                                );
                                            })}
                                    </SelectContent>
                                </Select>
                                {selectedDay && availableWindows.length === 0 && (
                                    <Text variant="caption" className="flex items-center gap-2 text-amber-600">
                                        <AlertTriangle className="size-3.5" />
                                        Every window on this day has already ended.
                                    </Text>
                                )}
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="add-exclusion-student">Student number</Label>
                            <Input
                                id="add-exclusion-student"
                                value={addForm.studentNumber}
                                onChange={(e) => setAddForm((prev) => ({ ...prev, studentNumber: formatStudentNumber(e.target.value) }))}
                                placeholder="e.g. 2023-1-05413"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="add-exclusion-reason">Reason</Label>
                            <Input
                                id="add-exclusion-reason"
                                value={addForm.reason}
                                onChange={(e) => setAddForm((prev) => ({ ...prev, reason: e.target.value }))}
                                placeholder="e.g. Academic probation"
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsAddOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={createExclusion.isPending}>
                                {createExclusion.isPending ? 'Adding…' : 'Add exclusion'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Bulk Upload dialog — student-exclusion-feature-plan.md §5 (bulk path) + §7 (preview required before commit). */}
            <Dialog open={isBulkOpen} onOpenChange={setIsBulkOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Bulk upload exclusions</DialogTitle>
                        <DialogDescription>
                            CSV columns: student_id, scope (EVENT/DAY/WINDOW), day, window. One reason applies to the whole batch.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="bulk-exclusion-file">CSV file</Label>
                            <Input
                                id="bulk-exclusion-file"
                                key={bulkFileInputKey}
                                type="file"
                                accept=".csv,.xlsx,.xls"
                                onChange={(e) => handleBulkFileChange(e.target.files)}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="bulk-exclusion-reason">Reason (applies to every row)</Label>
                            <Input
                                id="bulk-exclusion-reason"
                                value={bulkReason}
                                onChange={(e) => setBulkReason(e.target.value)}
                                placeholder="e.g. Disciplinary case #4821"
                            />
                        </div>

                        <Button type="button" variant="outline" size="sm" onClick={handleBulkPreview} disabled={!bulkFile || previewBulk.isPending}>
                            {previewBulk.isPending ? 'Checking…' : 'Preview'}
                        </Button>

                        {previewBulk.data && (
                            <Card className="border-dashed">
                                <CardContent className="space-y-2 p-4">
                                    <Text className="font-medium">
                                        {previewBulk.data.valid} of {previewBulk.data.totalRows} row(s) will be excluded.
                                    </Text>
                                    {previewBulk.data.invalid > 0 && (
                                        <div className="max-h-40 space-y-2 overflow-y-auto">
                                            {previewBulk.data.rows
                                                .filter((row) => !row.valid)
                                                .map((row) => (
                                                    <Text key={row.row} variant="caption" className="text-destructive">
                                                        Row {row.row} ({row.studentNumber ?? '—'}): {row.reasons.join('; ')}
                                                    </Text>
                                                ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setIsBulkOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={handleBulkConfirm}
                            disabled={!bulkFile || !previewBulk.data || bulkReason.trim() === '' || createBulk.isPending}
                        >
                            {createBulk.isPending ? 'Uploading…' : 'Confirm & exclude'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Remove confirmation — student-exclusion-feature-plan.md §6. */}
            <AlertDialog open={removingId !== null} onOpenChange={(open) => !open && setRemovingId(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove this exclusion?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {removingExclusion?.studentName} will become trackable again for any day/window that has not ended yet. Days/windows
                            that already ended while this exclusion was active keep their "Excluded" record permanently.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleRemoveConfirmed} disabled={removeExclusion.isPending}>
                            Remove
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
