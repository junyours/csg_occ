import { type ChangeEvent, type FormEvent, type MouseEvent, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useAuthStore } from '@/application/auth/auth.store';
import { useDepartments } from '@/application/departments/use-departments';
import { useDebouncedValue } from '@/application/shared/use-debounced-value';
import { useStudents } from '@/application/students/use-students';
import { useCreateStudent } from '@/application/students/use-create-student';
import { useUpdateStudent, useUpdateStudentStatus } from '@/application/students/use-update-student';
import { useBulkImportStudents } from '@/application/students/use-bulk-import-students';
import { usePreviewBulkImportStudents } from '@/application/students/use-preview-bulk-import-students';
import { useDownloadBulkImportTemplate } from '@/application/students/use-download-bulk-import-template';
import { useUpdateStudentRole } from '@/application/students/use-update-student-role';
import { useResetStudentPassword } from '@/application/students/use-reset-student-password';
import { useEvents } from '@/application/events/use-events';
import { StudentAlreadyExistsError, type BulkImportPreview, type BulkImportReport } from '@/application/students/students.repository';
import type { Student } from '@/domain/entities';
import { Role, ROLE_LABEL, type StudentStatus } from '@/domain/enums';
import { canResetStudentPassword } from '@/domain/student-access';
import { apiErrorMessage, cn, formatStudentNumber } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { AvatarLightbox } from '@/presentation/components/avatar-lightbox';
import { UserAvatar } from '@/presentation/components/user-avatar';
import { departmentTone, TONE, type Tone } from '@/presentation/components/tone';

// Same tinted-squircle language as the dashboard: a role gets one
// consistent hue everywhere it shows up (row avatar, "change role" chip),
// rather than every page inventing its own badge palette.
const ROLE_TONE: Record<Role, Tone> = {
    [Role.SystemAdmin]: 'violet',
    [Role.CsgAdmin]: 'violet',
    [Role.ScAdmin]: 'sky',
    [Role.Officer]: 'orange',
    [Role.Student]: 'neutral',
};

// System Admin can promote up to csg_admin; CSG Admin cannot reach that
// high. Mirrors StudentPolicy::assignRole's $assignable sets exactly.
const ASSIGNABLE_ROLES: Record<string, Role[]> = {
    [Role.SystemAdmin]: [Role.CsgAdmin, Role.ScAdmin, Role.Officer, Role.Student],
    [Role.CsgAdmin]: [Role.ScAdmin, Role.Officer, Role.Student],
};

const PER_PAGE = 25;

interface CreateFormValues {
    studentNumber: string;
    lastName: string;
    firstName: string;
    middleName: string;
    suffix: string;
    departmentId: string;
    major: string;
    yearLevel: string;
    section: string;
}

const EMPTY_CREATE_FORM: CreateFormValues = {
    studentNumber: '',
    lastName: '',
    firstName: '',
    middleName: '',
    suffix: '',
    departmentId: '',
    major: '',
    yearLevel: '',
    section: '',
};

export function StudentsPage() {
    const student = useAuthStore((state) => state.student);
    const { data: departments } = useDepartments();
    const { data: events } = useEvents();

    const [search, setSearch] = useState('');
    const [departmentFilter, setDepartmentFilter] = useState<string>('all');
    const [roleFilter, setRoleFilter] = useState<string>('all');
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [yearLevelFilter, setYearLevelFilter] = useState('');
    const [sectionFilter, setSectionFilter] = useState('');
    const [page, setPage] = useState(1);

    const isScAdmin = student?.role === Role.ScAdmin;
    const canFilterDepartment = student?.role === Role.SystemAdmin || student?.role === Role.CsgAdmin;

    // The inputs stay bound to the live text so typing feels instant, but the
    // query only sees the value once typing has paused (350ms) — otherwise
    // every keystroke is its own API request.
    const debouncedSearch = useDebouncedValue(search);
    const debouncedYearLevel = useDebouncedValue(yearLevelFilter);
    const debouncedSection = useDebouncedValue(sectionFilter);

    const { data: rosterPage, isLoading } = useStudents({
        search: debouncedSearch || undefined,
        departmentId:
            canFilterDepartment && departmentFilter !== 'all' ? Number(departmentFilter) : undefined,
        role: roleFilter !== 'all' ? (roleFilter as Role) : undefined,
        status: statusFilter !== 'all' ? (statusFilter as StudentStatus) : undefined,
        yearLevel: debouncedYearLevel || undefined,
        section: debouncedSection || undefined,
        page,
        perPage: PER_PAGE,
    });

    const [isAdding, setIsAdding] = useState(false);
    const [createForm, setCreateForm] = useState<CreateFormValues>(EMPTY_CREATE_FORM);
    const createStudent = useCreateStudent();
    // Set when "Add student" hit an existing ID: the form is now an
    // "update enrollment" form for that student (name/ID stay as they are).
    const [existingStudent, setExistingStudent] = useState<Student | null>(null);

    const [editing, setEditing] = useState<Student | null>(null);
    const [editForm, setEditForm] = useState<CreateFormValues>(EMPTY_CREATE_FORM);
    const updateStudent = useUpdateStudent();
    const updateStudentStatus = useUpdateStudentStatus();
    const [togglingStatus, setTogglingStatus] = useState<Student | null>(null);

    const [isBulkImportOpen, setIsBulkImportOpen] = useState(false);
    const [importReport, setImportReport] = useState<BulkImportReport | null>(null);
    const [preview, setPreview] = useState<BulkImportPreview | null>(null);
    const [pendingFiles, setPendingFiles] = useState<File[]>([]);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const previewBulkImportStudents = usePreviewBulkImportStudents();
    const bulkImportStudents = useBulkImportStudents();
    const { download: downloadTemplate, isDownloading } = useDownloadBulkImportTemplate();

    const [editingRoleId, setEditingRoleId] = useState<number | null>(null);
    const [roleForm, setRoleForm] = useState<{ role: Role; departmentId: string; eventId: string }>({
        role: Role.Student,
        departmentId: '',
        eventId: '',
    });
    const updateStudentRole = useUpdateStudentRole();

    const [resettingPassword, setResettingPassword] = useState<Student | null>(null);
    const resetStudentPassword = useResetStudentPassword();

    if (!student) return null;

    const assignableRoles = ASSIGNABLE_ROLES[student.role] ?? [];
    const canAssignRoles = assignableRoles.length > 0;
    const departmentName = (id: number) => departments?.find((d) => d.id === id)?.code ?? '—';
    const deptToneFor = (id: number): Tone => departmentTone(departments?.find((d) => d.id === id)?.code, id);

    function handleResetPasswordConfirmed(event: MouseEvent) {
        // Keep the dialog open until the request finishes, so a failure
        // can be seen instead of the dialog vanishing on click.
        event.preventDefault();
        if (!resettingPassword) return;

        const target = resettingPassword;
        resetStudentPassword.mutate(target.id, {
            onSuccess: () => {
                toast.success(`${target.firstName} ${target.lastName}'s password was reset to the default.`, {
                    description: 'They will be asked to choose a new password the next time they sign in.',
                });
                setResettingPassword(null);
            },
            onError: () => {
                toast.error('Could not reset the password. Please try again.');
            },
        });
    }

    function resetCreateForm() {
        setCreateForm(EMPTY_CREATE_FORM);
        setExistingStudent(null);
        setIsAdding(false);
    }

    function startEditing(row: Student) {
        setEditing(row);
        setEditForm({
            studentNumber: row.studentNumber,
            lastName: row.lastName,
            firstName: row.firstName,
            middleName: row.middleName ?? '',
            suffix: row.suffix ?? '',
            departmentId: String(row.departmentId),
            major: row.major ?? '',
            yearLevel: row.yearLevel ?? '',
            section: row.section ?? '',
        });
    }

    function handleEditSubmit(event: FormEvent) {
        event.preventDefault();
        if (!editing) return;

        updateStudent.mutate(
            {
                studentId: editing.id,
                payload: {
                    lastName: editForm.lastName,
                    firstName: editForm.firstName,
                    middleName: editForm.middleName || null,
                    suffix: editForm.suffix || null,
                    // SC Admins can't move a student out of their department.
                    ...(isScAdmin ? {} : { departmentId: Number(editForm.departmentId) }),
                    major: editForm.major || null,
                    yearLevel: editForm.yearLevel,
                    section: editForm.section || null,
                },
            },
            {
                onSuccess: () => {
                    toast.success('Student updated.');
                    setEditing(null);
                },
                onError: (error) => toast.error(apiErrorMessage(error, 'Could not update the student.')),
            },
        );
    }

    function handleStatusConfirmed(event: MouseEvent) {
        event.preventDefault();
        if (!togglingStatus) return;

        const target = togglingStatus;
        const next: StudentStatus = target.status === 'active' ? 'inactive' : 'active';

        updateStudentStatus.mutate(
            { studentId: target.id, status: next },
            {
                onSuccess: () => {
                    toast.success(
                        next === 'active'
                            ? `${target.firstName} ${target.lastName} is active again and enrolled in the current semester.`
                            : `${target.firstName} ${target.lastName} was deactivated and signed out.`,
                    );
                    setTogglingStatus(null);
                    setEditing(null);
                },
                onError: (error) => toast.error(apiErrorMessage(error, 'Could not change the status.')),
            },
        );
    }

    function handleCreateSubmit(event: FormEvent) {
        event.preventDefault();

        if (!isScAdmin && !createForm.departmentId) {
            toast.error('Select a department.');
            return;
        }

        createStudent.mutate(
            {
                updateExisting: existingStudent !== null,
              payload: {
                studentNumber: createForm.studentNumber,
                lastName: createForm.lastName,
                firstName: createForm.firstName,
                middleName: createForm.middleName,
                suffix: createForm.suffix || null,
                // SC Admin's own department is enforced server-side regardless
                // of what's sent, but we still need a real value here — the
                // field is hidden for them, so fall back to the one they
                // administer rather than sending an empty department_id.
                departmentId: isScAdmin ? (student.scAdminDepartmentId ?? 0) : Number(createForm.departmentId),
                major: createForm.major || null,
                yearLevel: createForm.yearLevel,
                section: createForm.section || null,
              },
            },
            {
                onSuccess: () => {
                    toast.success(existingStudent ? 'Enrollment updated.' : 'Student added.');
                    resetCreateForm();
                },
                onError: (error) => {
                    if (error instanceof StudentAlreadyExistsError) {
                        // Switch to update mode, pre-filled with the placement on file.
                        const known = error.existing;
                        setExistingStudent(known);
                        setCreateForm((f) => ({
                            ...f,
                            departmentId: String(known.departmentId),
                            major: known.major ?? '',
                            yearLevel: known.yearLevel ?? f.yearLevel,
                            section: known.section ?? '',
                        }));
                        toast.info('That student ID already exists — review and update their enrollment instead.');
                        return;
                    }
                    toast.error(apiErrorMessage(error, 'Could not add student.'));
                },
            },
        );
    }

    function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
        const files = Array.from(event.target.files ?? []);
        event.target.value = '';
        if (files.length === 0) return;

        setImportReport(null);

        previewBulkImportStudents.mutate(files, {
            onSuccess: (result) => {
                setPreview(result);
                setPendingFiles(files);
            },
            onError: (error) => {
                toast.error(apiErrorMessage(error, 'Could not read one or more files. Check the format and try again.'));
            },
        });
    }

    function handleConfirmImport() {
        if (pendingFiles.length === 0) return;

        bulkImportStudents.mutate(pendingFiles, {
            onSuccess: (report) => {
                setImportReport(report);
                setPreview(null);
                setPendingFiles([]);
                if (report.failed === 0) {
                    toast.success(`Imported ${report.created} new and updated ${report.updated} students${report.skipped ? ` (${report.skipped} already enrolled)` : ''}.`);
                } else {
                    toast.warning(`${report.created} added, ${report.updated} updated — ${report.failed} failed.`);
                }
            },
            onError: (error) => {
                toast.error(apiErrorMessage(error, 'Could not import the batch.'));
            },
        });
    }

    function handleCancelImport() {
        setPreview(null);
        setPendingFiles([]);
    }

    function startEditingRole(row: Student) {
        setEditingRoleId(row.id);
        setRoleForm({
            role: row.role,
            departmentId: row.scAdminDepartmentId ? String(row.scAdminDepartmentId) : '',
            eventId: row.officerEventId ? String(row.officerEventId) : '',
        });
    }

    function handleRoleSubmit(event: FormEvent, row: Student) {
        event.preventDefault();

        if (roleForm.role === Role.ScAdmin && !roleForm.departmentId) {
            toast.error('Select the department they will administer.');
            return;
        }

        updateStudentRole.mutate(
            {
                studentId: row.id,
                payload: {
                    role: roleForm.role,
                    departmentId: roleForm.role === Role.ScAdmin ? Number(roleForm.departmentId) : null,
                    eventId: roleForm.role === Role.Officer && roleForm.eventId ? Number(roleForm.eventId) : null,
                },
            },
            {
                onSuccess: () => {
                    toast.success('Role updated.');
                    setEditingRoleId(null);
                },
                onError: () => {
                    toast.error('Could not update role.');
                },
            },
        );
    }

    return (
        <div className="mx-auto max-w-3xl space-y-8">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <Heading level="h1">Students</Heading>
                    <Text variant="small">
                        {isScAdmin ? 'Your department\u2019s roster.' : 'The full student roster across every department.'}
                    </Text>
                </div>
                <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" onClick={() => setIsBulkImportOpen(true)}>
                        Bulk import
                    </Button>
                    <Button size="sm" onClick={() => setIsAdding(true)}>
                        Add student
                    </Button>
                </div>
            </div>

            <Dialog open={isAdding} onOpenChange={(open) => (open ? setIsAdding(true) : resetCreateForm())}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{existingStudent ? 'Update enrollment' : 'New student'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleCreateSubmit} className="space-y-4">
                        {existingStudent && (
                            <div className="rounded-md border border-border bg-muted/50 p-3">
                                <Text variant="small">
                                    <span className="font-medium">
                                        {existingStudent.lastName}, {existingStudent.firstName}
                                    </span>{' '}
                                    ({existingStudent.studentNumber}) is already on file
                                    {existingStudent.status === 'inactive' ? ' and inactive' : ''}. Saving moves them into the
                                    current semester with the placement below and makes them active. Their name, role and
                                    password are not changed.
                                </Text>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="studentNumber">Student ID</Label>
                                <Input
                                    id="studentNumber"
                                    value={createForm.studentNumber}
                                    onChange={(e) => setCreateForm((f) => ({ ...f, studentNumber: formatStudentNumber(e.target.value) }))}
                                    placeholder="e.g. 2023-1-05413"
                                    disabled={existingStudent !== null}
                                    required
                                />
                            </div>
                            {!isScAdmin && (
                                <div className="space-y-2">
                                    <Label htmlFor="department">Department</Label>
                                    <Select
                                        value={createForm.departmentId}
                                        onValueChange={(value) => setCreateForm((f) => ({ ...f, departmentId: value }))}
                                    >
                                        <SelectTrigger id="department" className="w-full">
                                            <SelectValue placeholder="Select department" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {departments?.map((dept) => (
                                                <SelectItem key={dept.id} value={String(dept.id)}>
                                                    {dept.code} — {dept.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                        </div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="lastName">Last name</Label>
                                <Input
                                    id="lastName"
                                    value={createForm.lastName}
                                    onChange={(e) => setCreateForm((f) => ({ ...f, lastName: e.target.value }))}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="firstName">First name</Label>
                                <Input
                                    id="firstName"
                                    value={createForm.firstName}
                                    onChange={(e) => setCreateForm((f) => ({ ...f, firstName: e.target.value }))}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="middleName">Middle name</Label>
                                <Input
                                    id="middleName"
                                    value={createForm.middleName}
                                    onChange={(e) => setCreateForm((f) => ({ ...f, middleName: e.target.value }))}
                                    required
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="suffix">Suffix</Label>
                                <Input
                                    id="suffix"
                                    placeholder="Jr., III, etc."
                                    value={createForm.suffix}
                                    onChange={(e) => setCreateForm((f) => ({ ...f, suffix: e.target.value }))}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="yearLevel">Year level</Label>
                                <Input
                                    id="yearLevel"
                                    placeholder="1"
                                    value={createForm.yearLevel}
                                    onChange={(e) => setCreateForm((f) => ({ ...f, yearLevel: e.target.value }))}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="section">Section</Label>
                                <Input
                                    id="section"
                                    placeholder="A"
                                    value={createForm.section}
                                    onChange={(e) => setCreateForm((f) => ({ ...f, section: e.target.value }))}
                                />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="major">Major (optional)</Label>
                            <Input
                                id="major"
                                placeholder="e.g. FM"
                                value={createForm.major}
                                onChange={(e) => setCreateForm((f) => ({ ...f, major: e.target.value }))}
                            />
                        </div>
                        <Text variant="caption">
                            Default password is <span className="font-medium">password123</span> — the student must change
                            it on first login.
                        </Text>
                        <div className="flex gap-2">
                            <Button type="submit" disabled={createStudent.isPending}>
                                {createStudent.isPending ? 'Saving…' : existingStudent ? 'Update enrollment' : 'Add student'}
                            </Button>
                            <Button type="button" variant="outline" onClick={resetCreateForm}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={isBulkImportOpen} onOpenChange={setIsBulkImportOpen}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Bulk import</DialogTitle>
                        <DialogDescription>
                            One file per section, named after the section itself (e.g. "BSIT-1A.xlsx" or
                            "BSBA-FM-1H.xlsx") — course, major, and year level are all read from the filename, so the
                            sheet itself only needs ID Number, Last Name, First Name, Middle Name, and Date Enrolled.
                            Select every section file you're uploading at once; you'll see a full preview before
                            anything is saved.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={downloadTemplate} disabled={isDownloading}>
                                {isDownloading ? 'Downloading…' : 'Download template'}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => fileInputRef.current?.click()}
                                disabled={previewBulkImportStudents.isPending || !!preview}
                            >
                                {previewBulkImportStudents.isPending ? 'Reading files…' : 'Choose section files'}
                            </Button>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".xlsx,.xls,.csv"
                                multiple
                                className="hidden"
                                onChange={handleFileChange}
                            />
                        </div>

                        {preview && (
                            <div className="space-y-4 rounded-md border border-border p-4">
                                <Text variant="small" className="font-medium">
                                    Preview — nothing has been saved yet
                                </Text>
                                <Text variant="small">
                                    {preview.totalRows} rows across {preview.totalFiles} file
                                    {preview.totalFiles === 1 ? '' : 's'}:
                                </Text>
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="secondary" className="border-transparent bg-emerald-100 text-emerald-800">
                                        {preview.created} new
                                    </Badge>
                                    <Badge variant="secondary" className="border-transparent bg-sky-100 text-sky-800">
                                        {preview.updated} updated
                                    </Badge>
                                    <Badge variant="secondary">{preview.skipped} already enrolled (skipped)</Badge>
                                    {preview.invalid > 0 && (
                                        <Badge variant="secondary" className="border-transparent bg-red-100 text-red-800">
                                            {preview.invalid} with errors (skipped)
                                        </Badge>
                                    )}
                                </div>

                                <ul className="max-h-72 space-y-2 overflow-y-auto">
                                    {preview.files.map((file) => (
                                        <li key={file.filename} className="space-y-2 rounded border border-border/60 p-2">
                                            <Text variant="caption" className="font-medium">
                                                {file.filename}
                                                {file.valid
                                                    ? ` — ${file.departmentCode}${file.major ? ` ${file.major}` : ''} Yr ${file.yearLevel} ${file.section}`
                                                    : ''}
                                            </Text>
                                            {!file.valid && (
                                                <Text variant="caption" className="text-destructive">
                                                    {file.parseError}
                                                </Text>
                                            )}
                                            {file.valid && file.rows.some((r) => r.action === 'update') && (
                                                <ul className="space-y-0.5 pl-2">
                                                    {file.rows
                                                        .filter((row) => row.action === 'update')
                                                        .map((row) => (
                                                            <li key={`u-${row.row}`}>
                                                                <Text variant="caption">
                                                                    Row {row.row} ({row.studentNumber}) — updated
                                                                    {row.change ? `: ${row.change}` : ', re-activated'}
                                                                </Text>
                                                            </li>
                                                        ))}
                                                </ul>
                                            )}
                                            {file.valid && file.rows.some((r) => !r.valid) && (
                                                <ul className="space-y-0.5 pl-2">
                                                    {file.rows
                                                        .filter((row) => !row.valid)
                                                        .map((row) => (
                                                            <li key={row.row}>
                                                                <Text variant="caption">
                                                                    Row {row.row}
                                                                    {row.studentNumber ? ` (${row.studentNumber})` : ''}:{' '}
                                                                    {row.reasons.join('; ')}
                                                                </Text>
                                                            </li>
                                                        ))}
                                                </ul>
                                            )}
                                            {file.valid && (
                                                <Text variant="caption">
                                                    {file.rows.filter((r) => r.valid && r.action !== 'skip').length} of {file.rows.length} rows will be written
                                                </Text>
                                            )}
                                        </li>
                                    ))}
                                </ul>

                                <div className="flex gap-2 pt-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={handleConfirmImport}
                                        disabled={preview.created + preview.updated === 0 || bulkImportStudents.isPending}
                                    >
                                        {bulkImportStudents.isPending
                                            ? 'Importing…'
                                            : `Confirm — ${preview.created} new, ${preview.updated} updated`}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={handleCancelImport}
                                        disabled={bulkImportStudents.isPending}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        )}

                        {importReport && (
                            <div className="space-y-2 rounded-md border border-border p-4">
                                <Text variant="small">
                                    {importReport.created} added, {importReport.updated} updated,{' '}
                                    {importReport.skipped} skipped (already enrolled)
                                    {importReport.failed > 0 ? `, ${importReport.failed} failed` : ''} — {importReport.totalRows}{' '}
                                    rows across {importReport.totalFiles} file{importReport.totalFiles === 1 ? '' : 's'}.
                                </Text>
                                {importReport.errors.length > 0 && (
                                    <ul className="space-y-2">
                                        {importReport.errors.map((error, index) => (
                                            <li key={`${error.filename}-${error.row}-${index}`}>
                                                <Text variant="caption">
                                                    {error.filename} · Row {error.row}
                                                    {error.studentNumber ? ` (${error.studentNumber})` : ''}:{' '}
                                                    {error.reasons.join('; ')}
                                                </Text>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <div className="space-y-4">
                <div className="flex flex-wrap gap-2">
                    <Input
                        placeholder="Search by ID or name…"
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                        className="max-w-56"
                    />
                    {canFilterDepartment && (
                        <Select
                            value={departmentFilter}
                            onValueChange={(value) => {
                                setDepartmentFilter(value);
                                setPage(1);
                            }}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="Department" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All departments</SelectItem>
                                {departments?.map((dept) => (
                                    <SelectItem key={dept.id} value={String(dept.id)}>
                                        {dept.code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Select
                        value={roleFilter}
                        onValueChange={(value) => {
                            setRoleFilter(value);
                            setPage(1);
                        }}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All roles</SelectItem>
                            {Object.values(Role).map((role) => (
                                <SelectItem key={role} value={role}>
                                    {ROLE_LABEL[role]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={statusFilter}
                        onValueChange={(value) => {
                            setStatusFilter(value);
                            setPage(1);
                        }}
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <Input
                        placeholder="Year level"
                        value={yearLevelFilter}
                        onChange={(e) => {
                            setYearLevelFilter(e.target.value);
                            setPage(1);
                        }}
                        className="w-28"
                    />
                    <Input
                        placeholder="Section"
                        value={sectionFilter}
                        onChange={(e) => {
                            setSectionFilter(e.target.value);
                            setPage(1);
                        }}
                        className="w-28"
                    />
                </div>

                {isLoading && <Text variant="small">Loading…</Text>}
                {!isLoading && rosterPage?.data.length === 0 && <Text variant="small">No students match these filters.</Text>}

                {rosterPage && rosterPage.data.length > 0 && (
                <div className="space-y-3">
                {rosterPage.data.map((row) => (
                    <div key={row.id} className="overflow-hidden rounded-[10px] border bg-card p-4 transition-colors hover:bg-muted/30 sm:px-6">
                        {editingRoleId === row.id ? (
                            <div>
                                <form onSubmit={(e) => handleRoleSubmit(e, row)} className="space-y-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Text variant="small" className="font-medium">
                                            {row.lastName}, {row.firstName} — change role
                                        </Text>
                                        {row.status === 'inactive' && (
                                            <Badge variant="secondary" className="border-transparent bg-red-100 text-red-800">
                                                Inactive
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor={`role-${row.id}`}>Role</Label>
                                        <Select
                                            value={roleForm.role}
                                            onValueChange={(value) => setRoleForm((f) => ({ ...f, role: value as Role }))}
                                        >
                                            <SelectTrigger id={`role-${row.id}`} className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {[...assignableRoles, Role.Student]
                                                    .filter((role, index, all) => all.indexOf(role) === index)
                                                    .map((role) => (
                                                        <SelectItem key={role} value={role}>
                                                            {ROLE_LABEL[role]}
                                                        </SelectItem>
                                                    ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    {roleForm.role === Role.ScAdmin && (
                                        <div className="space-y-2">
                                            <Label htmlFor={`sc-dept-${row.id}`}>Department administered</Label>
                                            <Select
                                                value={roleForm.departmentId}
                                                onValueChange={(value) => setRoleForm((f) => ({ ...f, departmentId: value }))}
                                            >
                                                <SelectTrigger id={`sc-dept-${row.id}`} className="w-full">
                                                    <SelectValue placeholder="Select department" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {departments?.map((dept) => (
                                                        <SelectItem key={dept.id} value={String(dept.id)}>
                                                            {dept.code} — {dept.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}
                                    {roleForm.role === Role.Officer && (
                                        <div className="space-y-2">
                                            <Label htmlFor={`officer-event-${row.id}`}>Event (optional)</Label>
                                            <Select
                                                value={roleForm.eventId || 'none'}
                                                onValueChange={(value) =>
                                                    setRoleForm((f) => ({ ...f, eventId: value === 'none' ? '' : value }))
                                                }
                                            >
                                                <SelectTrigger id={`officer-event-${row.id}`} className="w-full">
                                                    <SelectValue placeholder="Not scoped to an event" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">Not scoped to an event</SelectItem>
                                                    {events?.map((evt) => (
                                                        <SelectItem key={evt.id} value={String(evt.id)}>
                                                            {evt.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={updateStudentRole.isPending}>
                                            {updateStudentRole.isPending ? 'Saving…' : 'Save'}
                                        </Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => setEditingRoleId(null)}>
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            </div>
                        ) : (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                <div className="flex min-w-0 items-center gap-3">
                                    {/* Photo when there is one; otherwise initials in the
                                        role's hue. Square-ish like every Tile, not a circle. */}
                                    <AvatarLightbox photoUrl={row.photoUrl} name={`${row.firstName} ${row.lastName}`} className="rounded-lg">
                                        <UserAvatar
                                            student={row}
                                            size="lg"
                                            className="rounded-lg after:rounded-lg"
                                            fallbackClassName={cn('rounded-lg text-xs font-bold', TONE[ROLE_TONE[row.role]].soft)}
                                        />
                                    </AvatarLightbox>
                                    <div className="min-w-0 space-y-1">
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <Text className="truncate font-semibold text-foreground">
                                                {row.lastName}, {row.firstName} {row.middleName ?? ''}
                                            </Text>
                                            {row.status === 'inactive' && (
                                                <span className="rounded-sm bg-red-100 px-1.5 py-0.5 text-caption leading-none font-semibold text-red-800 dark:bg-red-500/15 dark:text-red-300">
                                                    Inactive
                                                </span>
                                            )}
                                            {row.role !== Role.Student && (
                                                <span
                                                    className={cn(
                                                        'rounded-sm px-1.5 py-0.5 text-caption leading-none font-semibold',
                                                        TONE[ROLE_TONE[row.role]].chip,
                                                    )}
                                                >
                                                    {ROLE_LABEL[row.role]}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span className="text-caption text-muted-foreground tabular-nums">{row.studentNumber}</span>
                                            <span
                                                className={cn(
                                                    'rounded-sm px-1.5 py-0.5 text-caption leading-none font-semibold',
                                                    TONE[deptToneFor(row.departmentId)].chip,
                                                )}
                                            >
                                                {departmentName(row.departmentId)}
                                            </span>
                                            {(row.yearLevel || row.section) && (
                                                <span className="text-caption text-muted-foreground">
                                                    {[row.yearLevel ? `Yr ${row.yearLevel}` : null, row.section].filter(Boolean).join(' · ')}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                {/* Phone: a full-width action bar under the row, split into
                                    equal cells. From sm up: quiet buttons on the right. */}
                                <div className="-mx-4 -mb-4 flex divide-x border-t sm:mx-0 sm:mb-0 sm:shrink-0 sm:divide-x-0 sm:border-t-0 sm:gap-1">
                                    {row.role !== Role.SystemAdmin && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-10 flex-1 rounded-none text-muted-foreground hover:text-foreground sm:h-8 sm:flex-none sm:rounded-md"
                                            onClick={() => startEditing(row)}
                                        >
                                            Edit
                                        </Button>
                                    )}
                                    {canAssignRoles && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-10 flex-1 rounded-none text-muted-foreground hover:text-foreground sm:h-8 sm:flex-none sm:rounded-md"
                                            onClick={() => startEditingRole(row)}
                                        >
                                            Change role
                                        </Button>
                                    )}
                                    {canResetStudentPassword(student, row) && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-10 flex-1 rounded-none text-muted-foreground hover:text-foreground sm:h-8 sm:flex-none sm:rounded-md"
                                            onClick={() => setResettingPassword(row)}
                                        >
                                            Reset password
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                ))}
                </div>
                )}

                {rosterPage && rosterPage.lastPage > 1 && (
                    <div className="flex items-center justify-between pt-2">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={page <= 1}
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                        >
                            Previous
                        </Button>
                        <Text variant="caption">
                            Page {rosterPage.currentPage} of {rosterPage.lastPage} — {rosterPage.total} students
                        </Text>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={page >= rosterPage.lastPage}
                            onClick={() => setPage((p) => p + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Edit student</DialogTitle>
                        <DialogDescription>
                            {editing?.studentNumber} — the ID number and role can't be changed here.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleEditSubmit} className="space-y-4">
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="edit-lastName">Last name</Label>
                                <Input id="edit-lastName" value={editForm.lastName} required
                                    onChange={(e) => setEditForm((f) => ({ ...f, lastName: e.target.value }))} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="edit-firstName">First name</Label>
                                <Input id="edit-firstName" value={editForm.firstName} required
                                    onChange={(e) => setEditForm((f) => ({ ...f, firstName: e.target.value }))} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="edit-middleName">Middle name</Label>
                                <Input id="edit-middleName" value={editForm.middleName}
                                    onChange={(e) => setEditForm((f) => ({ ...f, middleName: e.target.value }))} />
                            </div>
                        </div>
                        {!isScAdmin && (
                            <div className="space-y-2">
                                <Label htmlFor="edit-department">Department</Label>
                                <Select value={editForm.departmentId}
                                    onValueChange={(value) => setEditForm((f) => ({ ...f, departmentId: value }))}>
                                    <SelectTrigger id="edit-department" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {departments?.map((dept) => (
                                            <SelectItem key={dept.id} value={String(dept.id)}>
                                                {dept.code} — {dept.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="edit-major">Major</Label>
                                <Input id="edit-major" value={editForm.major}
                                    onChange={(e) => setEditForm((f) => ({ ...f, major: e.target.value }))} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="edit-yearLevel">Year level</Label>
                                <Input id="edit-yearLevel" value={editForm.yearLevel} required
                                    onChange={(e) => setEditForm((f) => ({ ...f, yearLevel: e.target.value }))} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="edit-section">Section</Label>
                                <Input id="edit-section" value={editForm.section}
                                    onChange={(e) => setEditForm((f) => ({ ...f, section: e.target.value }))} />
                            </div>
                        </div>
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex gap-2">
                                <Button type="submit" disabled={updateStudent.isPending}>
                                    {updateStudent.isPending ? 'Saving…' : 'Save changes'}
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                    Cancel
                                </Button>
                            </div>
                            {editing && editing.id !== student.id && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setTogglingStatus(editing)}
                                >
                                    {editing.status === 'active' ? 'Deactivate' : 'Reactivate'}
                                </Button>
                            )}
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={togglingStatus !== null}
                onOpenChange={(open) => !open && !updateStudentStatus.isPending && setTogglingStatus(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {togglingStatus?.status === 'active' ? 'Deactivate this account?' : 'Reactivate this account?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {togglingStatus?.status === 'active'
                                ? 'They are signed out everywhere, can no longer sign in or be scanned, and are skipped when a session ends. Their attendance history is kept.'
                                : 'They can sign in again and are enrolled in the current semester.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={updateStudentStatus.isPending}>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleStatusConfirmed} disabled={updateStudentStatus.isPending}>
                            {updateStudentStatus.isPending ? 'Saving…' : 'Confirm'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={resettingPassword !== null}
                onOpenChange={(open) => !open && !resetStudentPassword.isPending && setResettingPassword(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Reset this password?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Do you really want to reset the password of{' '}
                            <span className="font-medium text-foreground">
                                {resettingPassword?.lastName}, {resettingPassword?.firstName}
                            </span>{' '}
                            ({resettingPassword?.studentNumber})? Their password goes back to the default, they are signed
                            out everywhere, and they must choose a new password the next time they sign in.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={resetStudentPassword.isPending}>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleResetPasswordConfirmed} disabled={resetStudentPassword.isPending}>
                            {resetStudentPassword.isPending ? 'Resetting…' : 'Reset password'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Separator />
            <Text variant="caption">
                Every account is a student record — promoting someone to SC Admin or Officer here is what grants them
                that role's extra powers, without creating a separate account.
            </Text>
        </div>
    );
}
