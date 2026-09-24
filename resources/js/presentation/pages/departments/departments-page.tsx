import { type ChangeEvent, type FormEvent, useRef, useState } from 'react';
import { isAxiosError } from 'axios';
import { Building2 } from 'lucide-react';
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
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useAuthStore } from '@/application/auth/auth.store';
import { useDepartments } from '@/application/departments/use-departments';
import { useCreateDepartment } from '@/application/departments/use-create-department';
import { useUpdateDepartment } from '@/application/departments/use-update-department';
import { useUpdateDepartmentLogo } from '@/application/departments/use-update-department-logo';
import { useDeleteDepartment } from '@/application/departments/use-delete-department';
import type { Department } from '@/domain/entities';
import { Role } from '@/domain/enums';
import { cn } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { DEPARTMENT_TONES, TONE } from '@/presentation/components/tone';

const MANAGE_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin];

interface DepartmentFormValues {
    name: string;
    code: string;
}

const EMPTY_FORM: DepartmentFormValues = { name: '', code: '' };

export function DepartmentsPage() {
    const student = useAuthStore((state) => state.student);
    const { data: departments, isLoading } = useDepartments();
    const createDepartment = useCreateDepartment();
    const updateDepartment = useUpdateDepartment();
    const updateLogo = useUpdateDepartmentLogo();
    const deleteDepartment = useDeleteDepartment();

    const [isCreating, setIsCreating] = useState(false);
    const [createForm, setCreateForm] = useState<DepartmentFormValues>(EMPTY_FORM);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editForm, setEditForm] = useState<DepartmentFormValues>(EMPTY_FORM);
    const [logoTargetId, setLogoTargetId] = useState<number | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [departmentPendingDelete, setDepartmentPendingDelete] = useState<Department | null>(null);
    const logoInputRef = useRef<HTMLInputElement>(null);

    if (!student) return null;

    const canManage = MANAGE_ROLES.includes(student.role);

    function startEditing(department: Department) {
        setEditingId(department.id);
        setEditForm({ name: department.name, code: department.code });
    }

    function handleCreateSubmit(event: FormEvent) {
        event.preventDefault();
        createDepartment.mutate(
            { name: createForm.name, code: createForm.code },
            {
                onSuccess: () => {
                    toast.success('Department created.');
                    setCreateForm(EMPTY_FORM);
                    setIsCreating(false);
                },
                onError: () => {
                    toast.error("Could not create department. Check the code isn't already in use.");
                },
            },
        );
    }

    function handleEditSubmit(event: FormEvent, id: number) {
        event.preventDefault();
        updateDepartment.mutate(
            { id, payload: { name: editForm.name, code: editForm.code } },
            {
                onSuccess: () => {
                    toast.success('Department updated.');
                    setEditingId(null);
                },
                onError: () => {
                    toast.error("Could not update department. Check the code isn't already in use.");
                },
            },
        );
    }

    function openLogoPicker(departmentId: number) {
        setLogoTargetId(departmentId);
        // Reset so choosing the same file twice in a row still fires onChange.
        if (logoInputRef.current) logoInputRef.current.value = '';
        logoInputRef.current?.click();
    }

    function handleLogoFileChange(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        if (!file || logoTargetId === null) return;

        updateLogo.mutate(
            { departmentId: logoTargetId, file },
            {
                onSuccess: () => toast.success('Logo updated.'),
                onError: () => toast.error('Could not upload logo. Use a JPEG/PNG/WebP under 5MB.'),
                onSettled: () => setLogoTargetId(null),
            },
        );
    }

    function confirmDelete() {
        const department = departmentPendingDelete;
        if (!department) return;

        setDeletingId(department.id);
        deleteDepartment.mutate(department.id, {
            onSuccess: () => toast.success('Department deleted.'),
            onError: (error) => {
                // Backend refuses with 409 when the department still has
                // students (a plain student's home department or an SC
                // Admin's administered department) — surface that reason
                // instead of a generic failure message.
                if (isAxiosError(error) && error.response?.status === 409) {
                    toast.error(
                        typeof error.response.data?.message === 'string'
                            ? error.response.data.message
                            : 'This department still has students and cannot be deleted.',
                    );
                    return;
                }
                toast.error('Could not delete department.');
            },
            onSettled: () => setDeletingId(null),
        });
    }

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            <input
                ref={logoInputRef}
                type="file"
                accept="image/jpeg,image/jpg,image/png,image/webp"
                className="hidden"
                onChange={handleLogoFileChange}
            />

            <div className="flex items-start justify-between gap-4">
                <div>
                    <Heading level="h1">Departments</Heading>
                    <Text variant="small">
                        Departments (courses) students, events, and reports are grouped by — e.g. BSIT, BSBA.
                    </Text>
                </div>
                {canManage && !isCreating && (
                    <Button onClick={() => setIsCreating(true)} size="sm">
                        Add department
                    </Button>
                )}
            </div>

            {isCreating && (
                <Card>
                    <CardHeader>
                        <Text variant="small">New department</Text>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleCreateSubmit} className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="createName">Name</Label>
                                <Input
                                    id="createName"
                                    placeholder="Bachelor of Science in Information Technology"
                                    value={createForm.name}
                                    onChange={(event) => setCreateForm((form) => ({ ...form, name: event.target.value }))}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="createCode">Code</Label>
                                <Input
                                    id="createCode"
                                    placeholder="BSIT"
                                    value={createForm.code}
                                    onChange={(event) => setCreateForm((form) => ({ ...form, code: event.target.value }))}
                                    required
                                />
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" disabled={createDepartment.isPending}>
                                    {createDepartment.isPending ? 'Creating…' : 'Create'}
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

            {!isLoading && departments?.length === 0 && <Text variant="small">No departments yet.</Text>}

            <div className="space-y-4">
                {departments?.map((department, index) => {
                    const tone = DEPARTMENT_TONES[index % DEPARTMENT_TONES.length];
                    return (
                    <Card key={department.id} className="overflow-hidden">
                        {editingId === department.id ? (
                            <CardContent>
                                <form onSubmit={(event) => handleEditSubmit(event, department.id)} className="space-y-4">
                                    <div className="flex items-center gap-4">
                                        <Avatar size="lg" className="rounded-2xl">
                                            {department.logoUrl ? (
                                                <AvatarImage src={department.logoUrl} alt={department.code} />
                                            ) : null}
                                            <AvatarFallback className={cn('rounded-2xl', TONE[tone].soft)}>
                                                <Building2 className="size-4" />
                                            </AvatarFallback>
                                        </Avatar>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={updateLogo.isPending && logoTargetId === department.id}
                                            onClick={() => openLogoPicker(department.id)}
                                        >
                                            {updateLogo.isPending && logoTargetId === department.id
                                                ? 'Uploading…'
                                                : 'Change logo'}
                                        </Button>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={`editName-${department.id}`}>Name</Label>
                                        <Input
                                            id={`editName-${department.id}`}
                                            value={editForm.name}
                                            onChange={(event) => setEditForm((form) => ({ ...form, name: event.target.value }))}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={`editCode-${department.id}`}>Code</Label>
                                        <Input
                                            id={`editCode-${department.id}`}
                                            value={editForm.code}
                                            onChange={(event) => setEditForm((form) => ({ ...form, code: event.target.value }))}
                                            required
                                        />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={updateDepartment.isPending}>
                                            {updateDepartment.isPending ? 'Saving…' : 'Save'}
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
                                    <Avatar size="lg" className="rounded-2xl">
                                        {department.logoUrl ? <AvatarImage src={department.logoUrl} alt={department.code} /> : null}
                                        <AvatarFallback className={cn('rounded-2xl', TONE[tone].soft)}>
                                            <Building2 className="size-4" />
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <Text className="truncate font-medium">{department.name}</Text>
                                        <Text variant="small">{department.code}</Text>
                                    </div>
                                </div>
                                {canManage && (
                                    <div className="flex shrink-0 gap-2">
                                        <Button size="sm" variant="outline" onClick={() => startEditing(department)}>
                                            Edit
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-destructive hover:text-destructive"
                                            disabled={deleteDepartment.isPending && deletingId === department.id}
                                            onClick={() => setDepartmentPendingDelete(department)}
                                        >
                                            {deleteDepartment.isPending && deletingId === department.id ? 'Deleting…' : 'Delete'}
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        )}
                    </Card>
                    );
                })}
            </div>

            <Separator />
            <Text variant="caption">
                Department codes (e.g. BSIT, BSBA) are used as the course throughout the app. A department can only be
                deleted while it has no students — including students who administer it as SC Admin.
            </Text>

            <AlertDialog open={departmentPendingDelete !== null} onOpenChange={(open) => !open && setDepartmentPendingDelete(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete department?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {departmentPendingDelete && (
                                <>
                                    Delete {departmentPendingDelete.name} ({departmentPendingDelete.code})? This can't be undone.
                                </>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete} className={buttonVariants({ variant: 'destructive' })}>
                            Yes, delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
