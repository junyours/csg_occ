import { type FormEvent, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useSemesters } from '@/application/semesters/use-semesters';
import { useCreateSemester } from '@/application/semesters/use-create-semester';
import { useUpdateSemester } from '@/application/semesters/use-update-semester';
import { useSetSemesterActive } from '@/application/semesters/use-set-semester-active';
import type { Semester } from '@/domain/entities';
import { Semester as SemesterTerm, SEMESTER_LABEL } from '@/domain/enums';
import { cn, formatDate } from '@/lib/utils';
import { Text } from '@/presentation/components/typography';
import { TONE } from '@/presentation/components/tone';

// Every academic year has exactly these three terms (backend: App\Domain\Enums\Semester,
// enforced by StoreSemesterRequest) — a semester's `name` is one of these
// values, not free text, so the create form offers a fixed choice rather
// than an open Input.
const SEMESTER_TERM_OPTIONS = Object.values(SemesterTerm).map((value) => ({
    value,
    label: SEMESTER_LABEL[value],
}));

interface CreateFormValues {
    name: string;
    startDate: string;
    endDate: string;
}

interface EditFormValues {
    startDate: string;
    endDate: string;
}

const EMPTY_CREATE_FORM: CreateFormValues = { name: '', startDate: '', endDate: '' };
const EMPTY_EDIT_FORM: EditFormValues = { startDate: '', endDate: '' };

export function SemesterSection({ academicYearId, canManage }: { academicYearId: number; canManage: boolean }) {
    const [expanded, setExpanded] = useState(false);
    const [isCreating, setIsCreating] = useState(false);
    const [createForm, setCreateForm] = useState<CreateFormValues>(EMPTY_CREATE_FORM);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editForm, setEditForm] = useState<EditFormValues>(EMPTY_EDIT_FORM);

    // Only fetch once the person actually opens this year's semesters —
    // there's one of these sections per academic year card, no reason to
    // fire N requests for years nobody is looking at.
    const { data: semesters, isLoading } = useSemesters(expanded ? academicYearId : null);
    const createSemester = useCreateSemester(academicYearId);
    const updateSemester = useUpdateSemester(academicYearId);
    const setActive = useSetSemesterActive(academicYearId);

    function startEditing(semester: Semester) {
        setEditingId(semester.id);
        setEditForm({
            startDate: semester.startDate ?? '',
            endDate: semester.endDate ?? '',
        });
    }

    function handleCreateSubmit(event: FormEvent) {
        event.preventDefault();
        createSemester.mutate(
            {
                name: createForm.name,
                start_date: createForm.startDate || null,
                end_date: createForm.endDate || null,
            },
            {
                onSuccess: () => {
                    toast.success('Semester created.');
                    setCreateForm(EMPTY_CREATE_FORM);
                    setIsCreating(false);
                },
                onError: () => {
                    toast.error("Could not create semester. Check the name isn't already used in this year.");
                },
            },
        );
    }

    function handleEditSubmit(event: FormEvent, id: number) {
        event.preventDefault();
        updateSemester.mutate(
            {
                id,
                payload: {
                    start_date: editForm.startDate || null,
                    end_date: editForm.endDate || null,
                },
            },
            {
                onSuccess: () => {
                    toast.success('Semester updated.');
                    setEditingId(null);
                },
                onError: () => {
                    toast.error('Could not update semester.');
                },
            },
        );
    }

    function handleToggleActive(semester: Semester) {
        setActive.mutate(
            { id: semester.id, active: !semester.isActive },
            {
                onSuccess: () => {
                    toast.success(semester.isActive ? `${semester.name} deactivated.` : `${semester.name} activated.`);
                },
                onError: () => {
                    toast.error('Could not update the active semester.');
                },
            },
        );
    }

    return (
        <div className="border-t border-border">
            <button
                type="button"
                onClick={() => setExpanded((value) => !value)}
                className="flex w-full items-center gap-2 px-6 py-4 text-left transition-colors hover:bg-accent"
            >
                {expanded ? (
                    <ChevronDown className="size-4 text-muted-foreground" />
                ) : (
                    <ChevronRight className="size-4 text-muted-foreground" />
                )}
                <Text variant="small" className="font-medium">
                    Semesters
                </Text>
            </button>

            {expanded && (
                <div className="space-y-4 px-6 pb-6">
                    {isLoading && <Text variant="small">Loading…</Text>}

                    {!isLoading && semesters?.length === 0 && !isCreating && (
                        <Text variant="small">No semesters yet.</Text>
                    )}

                    <div className="space-y-2">
                        {semesters?.map((semester: Semester) =>
                            editingId === semester.id ? (
                                <form
                                    key={semester.id}
                                    onSubmit={(event) => handleEditSubmit(event, semester.id)}
                                    className="space-y-4 rounded-lg border border-border p-4"
                                >
                                    <Text className="font-medium">{SEMESTER_LABEL[semester.name as SemesterTerm]}</Text>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor={`editSemStart-${semester.id}`}>Start date</Label>
                                            <Input
                                                id={`editSemStart-${semester.id}`}
                                                type="date"
                                                value={editForm.startDate}
                                                onChange={(event) =>
                                                    setEditForm((form) => ({ ...form, startDate: event.target.value }))
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor={`editSemEnd-${semester.id}`}>End date</Label>
                                            <Input
                                                id={`editSemEnd-${semester.id}`}
                                                type="date"
                                                value={editForm.endDate}
                                                onChange={(event) =>
                                                    setEditForm((form) => ({ ...form, endDate: event.target.value }))
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={updateSemester.isPending}>
                                            {updateSemester.isPending ? 'Saving…' : 'Save'}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditingId(null)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            ) : (
                                <div
                                    key={semester.id}
                                    className="flex items-center justify-between gap-4 rounded-lg border border-border p-4"
                                >
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <Text className="font-medium">
                                                {SEMESTER_LABEL[semester.name as SemesterTerm]}
                                            </Text>
                                            {semester.isActive && (
                                                <Badge variant="secondary" className={cn('border-transparent', TONE.emerald.chip)}>
                                                    Active
                                                </Badge>
                                            )}
                                        </div>
                                        {(semester.startDate || semester.endDate) && (
                                            <Text variant="small">
                                                {formatDate(semester.startDate)} to {formatDate(semester.endDate)}
                                            </Text>
                                        )}
                                    </div>
                                    {canManage && (
                                        <div className="flex shrink-0 gap-2">
                                            <Button size="sm" variant="outline" onClick={() => startEditing(semester)}>
                                                Edit
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant={semester.isActive ? 'outline' : 'secondary'}
                                                disabled={setActive.isPending}
                                                onClick={() => handleToggleActive(semester)}
                                            >
                                                {semester.isActive ? 'Deactivate' : 'Activate'}
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ),
                        )}
                    </div>

                    {canManage &&
                        (isCreating ? (
                            <form onSubmit={handleCreateSubmit} className="space-y-4 rounded-lg border border-border p-4">
                                <div className="space-y-2">
                                    <Label htmlFor={`createSemName-${academicYearId}`}>Term</Label>
                                    <Select
                                        value={createForm.name}
                                        onValueChange={(value) =>
                                            setCreateForm((form) => ({ ...form, name: value }))
                                        }
                                        required
                                    >
                                        <SelectTrigger id={`createSemName-${academicYearId}`} className="w-full">
                                            <SelectValue placeholder="Select a term…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {/* One semester per term per academic year (server-enforced unique
                                                constraint) — hide terms already created this year rather than
                                                letting the person pick one and hit a 422. */}
                                            {SEMESTER_TERM_OPTIONS.filter(
                                                (option) => !semesters?.some((semester) => semester.name === option.value),
                                            ).map((option) => (
                                                <SelectItem key={option.value} value={option.value}>
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label htmlFor={`createSemStart-${academicYearId}`}>Start date</Label>
                                        <Input
                                            id={`createSemStart-${academicYearId}`}
                                            type="date"
                                            value={createForm.startDate}
                                            onChange={(event) =>
                                                setCreateForm((form) => ({ ...form, startDate: event.target.value }))
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={`createSemEnd-${academicYearId}`}>End date</Label>
                                        <Input
                                            id={`createSemEnd-${academicYearId}`}
                                            type="date"
                                            value={createForm.endDate}
                                            onChange={(event) =>
                                                setCreateForm((form) => ({ ...form, endDate: event.target.value }))
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" size="sm" disabled={createSemester.isPending}>
                                        {createSemester.isPending ? 'Creating…' : 'Create'}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            setIsCreating(false);
                                            setCreateForm(EMPTY_CREATE_FORM);
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <>
                                {semesters && semesters.length > 0 && <Separator />}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setIsCreating(true)}
                                    className="w-full"
                                >
                                    Add semester
                                </Button>
                            </>
                        ))}
                </div>
            )}
        </div>
    );
}
