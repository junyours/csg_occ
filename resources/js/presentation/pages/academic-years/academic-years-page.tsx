import { type FormEvent, useState } from 'react';
import { CalendarRange } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useAuthStore } from '@/application/auth/auth.store';
import { useAcademicYears } from '@/application/academic-years/use-academic-years';
import { useCreateAcademicYear } from '@/application/academic-years/use-create-academic-year';
import { useUpdateAcademicYear } from '@/application/academic-years/use-update-academic-year';
import { useSetAcademicYearActive } from '@/application/academic-years/use-set-academic-year-active';
import type { AcademicYear } from '@/domain/entities';
import { Role } from '@/domain/enums';
import { cn, formatDate } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import { SemesterSection } from '@/presentation/pages/academic-years/semester-section';

const MANAGE_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin];

interface AcademicYearFormValues {
    name: string;
    startDate: string;
    endDate: string;
}

const EMPTY_FORM: AcademicYearFormValues = { name: '', startDate: '', endDate: '' };

export function AcademicYearsPage() {
    const student = useAuthStore((state) => state.student);
    const { data: academicYears, isLoading } = useAcademicYears();
    const createAcademicYear = useCreateAcademicYear();
    const updateAcademicYear = useUpdateAcademicYear();
    const setActive = useSetAcademicYearActive();

    const [isCreating, setIsCreating] = useState(false);
    const [createForm, setCreateForm] = useState<AcademicYearFormValues>(EMPTY_FORM);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editForm, setEditForm] = useState<AcademicYearFormValues>(EMPTY_FORM);

    if (!student) return null;

    const canManage = MANAGE_ROLES.includes(student.role);

    function startEditing(year: AcademicYear) {
        setEditingId(year.id);
        setEditForm({
            name: year.name,
            startDate: year.startDate ?? '',
            endDate: year.endDate ?? '',
        });
    }

    function handleCreateSubmit(event: FormEvent) {
        event.preventDefault();
        createAcademicYear.mutate(
            {
                name: createForm.name,
                start_date: createForm.startDate || null,
                end_date: createForm.endDate || null,
            },
            {
                onSuccess: () => {
                    toast.success('Academic year created.');
                    setCreateForm(EMPTY_FORM);
                    setIsCreating(false);
                },
                onError: () => {
                    toast.error("Could not create academic year. Check the name isn't already in use.");
                },
            },
        );
    }

    function handleEditSubmit(event: FormEvent, id: number) {
        event.preventDefault();
        updateAcademicYear.mutate(
            {
                id,
                payload: {
                    name: editForm.name,
                    start_date: editForm.startDate || null,
                    end_date: editForm.endDate || null,
                },
            },
            {
                onSuccess: () => {
                    toast.success('Academic year updated.');
                    setEditingId(null);
                },
                onError: () => {
                    toast.error('Could not update academic year.');
                },
            },
        );
    }

    function handleToggleActive(year: AcademicYear) {
        setActive.mutate(
            { id: year.id, active: !year.isActive },
            {
                onSuccess: () => {
                    toast.success(year.isActive ? `${year.name} deactivated.` : `${year.name} activated.`);
                },
                onError: () => {
                    toast.error('Could not update the active academic year.');
                },
            },
        );
    }

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <Heading level="h1">Academic Years</Heading>
                    <Text variant="small">
                        Every dashboard, event, and report is scoped to whichever academic year is active.
                    </Text>
                </div>
                {canManage && !isCreating && (
                    <Button onClick={() => setIsCreating(true)} size="sm">
                        Add academic year
                    </Button>
                )}
            </div>

            {isCreating && (
                <Card>
                    <CardHeader>
                        <Text variant="small">New academic year</Text>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleCreateSubmit} className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="createName">Name</Label>
                                <Input
                                    id="createName"
                                    placeholder="2026-2027"
                                    value={createForm.name}
                                    onChange={(event) => setCreateForm((form) => ({ ...form, name: event.target.value }))}
                                    required
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="createStart">Start date</Label>
                                    <Input
                                        id="createStart"
                                        type="date"
                                        value={createForm.startDate}
                                        onChange={(event) => setCreateForm((form) => ({ ...form, startDate: event.target.value }))}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="createEnd">End date</Label>
                                    <Input
                                        id="createEnd"
                                        type="date"
                                        value={createForm.endDate}
                                        onChange={(event) => setCreateForm((form) => ({ ...form, endDate: event.target.value }))}
                                    />
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" disabled={createAcademicYear.isPending}>
                                    {createAcademicYear.isPending ? 'Creating…' : 'Create'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setIsCreating(false);
                                        setCreateForm(EMPTY_FORM);
                                    }}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {isLoading && <Text variant="small">Loading…</Text>}

            {!isLoading && academicYears?.length === 0 && <Text variant="small">No academic years yet.</Text>}

            <div className="space-y-4">
                {academicYears?.map((year) => (
                    <Card key={year.id}>
                        {editingId === year.id ? (
                            <CardContent>
                                <form onSubmit={(event) => handleEditSubmit(event, year.id)} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor={`editName-${year.id}`}>Name</Label>
                                        <Input
                                            id={`editName-${year.id}`}
                                            value={editForm.name}
                                            onChange={(event) => setEditForm((form) => ({ ...form, name: event.target.value }))}
                                            required
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor={`editStart-${year.id}`}>Start date</Label>
                                            <Input
                                                id={`editStart-${year.id}`}
                                                type="date"
                                                value={editForm.startDate}
                                                onChange={(event) => setEditForm((form) => ({ ...form, startDate: event.target.value }))}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor={`editEnd-${year.id}`}>End date</Label>
                                            <Input
                                                id={`editEnd-${year.id}`}
                                                type="date"
                                                value={editForm.endDate}
                                                onChange={(event) => setEditForm((form) => ({ ...form, endDate: event.target.value }))}
                                            />
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={updateAcademicYear.isPending}>
                                            {updateAcademicYear.isPending ? 'Saving…' : 'Save'}
                                        </Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => setEditingId(null)}>
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        ) : (
                            <CardContent className="flex items-center justify-between gap-4">
                                <div className="flex min-w-0 items-center gap-4">
                                    <Tile
                                        tone={year.isActive ? 'emerald' : 'neutral'}
                                        size="md"
                                        variant="soft"
                                        Icon={CalendarRange}
                                    />
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Text className="truncate font-medium">{year.name}</Text>
                                            {year.isActive && (
                                                <Badge variant="secondary" className={cn('border-transparent', TONE.emerald.chip)}>
                                                    Active
                                                </Badge>
                                            )}
                                        </div>
                                        {(year.startDate || year.endDate) && (
                                            <Text variant="small">
                                                {formatDate(year.startDate)} to {formatDate(year.endDate)}
                                            </Text>
                                        )}
                                    </div>
                                </div>
                                {canManage && (
                                    <div className="flex shrink-0 gap-2">
                                        <Button size="sm" variant="outline" onClick={() => startEditing(year)}>
                                            Edit
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant={year.isActive ? 'outline' : 'secondary'}
                                            disabled={setActive.isPending}
                                            onClick={() => handleToggleActive(year)}
                                        >
                                            {year.isActive ? 'Deactivate' : 'Activate'}
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        )}
                        {editingId !== year.id && <SemesterSection academicYearId={year.id} canManage={canManage} />}
                    </Card>
                ))}
            </div>

            <Separator />
            <Text variant="caption">
                Only one academic year can be active at a time — activating one automatically deactivates the
                previous one.
            </Text>
        </div>
    );
}
