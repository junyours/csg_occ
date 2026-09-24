import { useState } from 'react';
import { CheckCircle2, Clock, MinusCircle, XCircle, type LucideIcon } from 'lucide-react';

import { AutosuggestInput } from '@/components/ui/autosuggest-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AvatarBadge } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useDebouncedValue } from '@/application/shared/use-debounced-value';
import { useAuthStore } from '@/application/auth/auth.store';
import { useAttendanceHistory } from '@/application/attendance/use-attendance-history';
import { useAttendanceHistoryFilterOptions } from '@/application/attendance/use-attendance-history-filter-options';
import { useDepartments } from '@/application/departments/use-departments';
import { useEvents } from '@/application/events/use-events';
import type {
    AttendanceHistorySort,
    AttendanceHistoryStatusFilter,
} from '@/infrastructure/attendance/attendance-history.repository.http';
import {
    ATTENDANCE_STATUS_LABEL,
    AttendanceStatus,
    CHECK_TYPE_LABEL,
    ROLE_LABEL,
    Role,
    WINDOW_TYPE_LABEL,
} from '@/domain/enums';
import { cn, formatDate, formatScannedAt } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { TONE, type Tone } from '@/presentation/components/tone';
import { UserAvatar } from '@/presentation/components/user-avatar';

// Same status→hue mapping as the dashboard (present/late/absent/excluded).
// The row's left edge now carries the student's photo, so this hue rides on
// the small badge overlaid on that avatar (plus the pill on the right) —
// a row is still legible at a glance without the status owning the tile.
const STATUS_TONE: Record<AttendanceStatus, Tone> = {
    [AttendanceStatus.Present]: 'emerald',
    [AttendanceStatus.Late]: 'amber',
    [AttendanceStatus.Absent]: 'red',
    [AttendanceStatus.Excluded]: 'neutral',
    [AttendanceStatus.Pending]: 'neutral',
};

const STATUS_ICON: Record<AttendanceStatus, LucideIcon> = {
    [AttendanceStatus.Present]: CheckCircle2,
    [AttendanceStatus.Late]: Clock,
    [AttendanceStatus.Absent]: XCircle,
    [AttendanceStatus.Excluded]: MinusCircle,
    [AttendanceStatus.Pending]: Clock,
};

const PER_PAGE = 25;

const SORT_OPTIONS: { value: AttendanceHistorySort; label: string }[] = [
    { value: 'recent', label: 'Most recent scan' },
    { value: 'name', label: 'Name' },
    { value: 'course', label: 'Course' },
    { value: 'major', label: 'Major' },
    { value: 'year_level', label: 'Year level' },
    { value: 'section', label: 'Section' },
    { value: 'event', label: 'Event' },
];

const STATUS_OPTIONS: AttendanceHistoryStatusFilter[] = ['all', 'present', 'late', 'absent', 'excluded'];

function isStatusFilter(value: string): value is AttendanceHistoryStatusFilter {
    return (STATUS_OPTIONS as string[]).includes(value);
}

function isSort(value: string): value is AttendanceHistorySort {
    return SORT_OPTIONS.some((option) => option.value === value);
}

export function AttendanceHistoryAdminPage() {
    // An Officer hits this same page/endpoint, but the backend forces
    // scanned_by = themselves regardless of what filters they pass — so
    // every row below is already "their" scans. Only the heading copy
    // and title change here; the filters still work the same way, just
    // narrowed within that officer's own history.
    const student = useAuthStore((state) => state.student);
    const isOfficer = student?.role === Role.Officer;

    const { data: departments } = useDepartments();
    const { data: events } = useEvents();
    const { data: filterOptions } = useAttendanceHistoryFilterOptions();

    const [search, setSearch] = useState('');
    const debouncedSearch = useDebouncedValue(search);

    const [departmentFilter, setDepartmentFilter] = useState<string>('all');
    const [eventFilter, setEventFilter] = useState<string>('all');
    const [statusFilter, setStatusFilter] = useState<AttendanceHistoryStatusFilter>('all');
    const [sort, setSort] = useState<AttendanceHistorySort>('recent');
    const [major, setMajor] = useState('');
    const [yearLevel, setYearLevel] = useState('');
    const [section, setSection] = useState('');
    const debouncedMajor = useDebouncedValue(major);
    const debouncedYearLevel = useDebouncedValue(yearLevel);
    const debouncedSection = useDebouncedValue(section);
    const [page, setPage] = useState(1);

    const { data: historyPage, isLoading } = useAttendanceHistory({
        search: debouncedSearch || undefined,
        departmentId: departmentFilter !== 'all' ? Number(departmentFilter) : undefined,
        eventId: eventFilter !== 'all' ? Number(eventFilter) : undefined,
        status: statusFilter,
        sort,
        major: debouncedMajor || undefined,
        yearLevel: debouncedYearLevel || undefined,
        section: debouncedSection || undefined,
        page,
        perPage: PER_PAGE,
    });

    return (
        <div className="mx-auto max-w-3xl space-y-8">
            <div>
                <Heading level="h1">{isOfficer ? 'My Scan History' : 'Attendance History'}</Heading>
                <Text variant="small">
                    {isOfficer
                        ? "Every attendance record you've personally scanned, across every session — use the filters " +
                          'below to find a specific student or event.'
                        : "Every scan across every session — who scanned it, when, and the outcome. Use this to audit a " +
                          "disputed record or trace a section's attendance."}
                </Text>
            </div>

            <div className="space-y-4">
                {/* Same responsive pattern as the Penalties ledger: search
                    stays full-width, the selects pack 2-per-row on mobile
                    and go inline from `sm` up. */}
                <div className="space-y-2 sm:flex sm:flex-wrap sm:items-start sm:gap-2 sm:space-y-0">
                    <Input
                        placeholder="Search student or scanning officer…"
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                        className="sm:max-w-64"
                    />
                    <div className="grid grid-cols-2 gap-2 sm:contents">
                        <Select
                            value={departmentFilter}
                            onValueChange={(value) => {
                                setDepartmentFilter(value);
                                setPage(1);
                            }}
                        >
                            <SelectTrigger className="w-full sm:w-40">
                                <SelectValue placeholder="Course" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All courses</SelectItem>
                                {departments?.map((dept) => (
                                    <SelectItem key={dept.id} value={String(dept.id)}>
                                        {dept.code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={eventFilter}
                            onValueChange={(value) => {
                                setEventFilter(value);
                                setPage(1);
                            }}
                        >
                            <SelectTrigger className="w-full sm:w-44">
                                <SelectValue placeholder="Event" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All events</SelectItem>
                                {events?.map((evt) => (
                                    <SelectItem key={evt.id} value={String(evt.id)}>
                                        {evt.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={statusFilter}
                            onValueChange={(value) => {
                                if (isStatusFilter(value)) {
                                    setStatusFilter(value);
                                    setPage(1);
                                }
                            }}
                        >
                            <SelectTrigger className="w-full sm:w-32">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {STATUS_OPTIONS.filter((s) => s !== 'all').map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {ATTENDANCE_STATUS_LABEL[s as AttendanceStatus]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={sort}
                            onValueChange={(value) => {
                                if (isSort(value)) setSort(value);
                            }}
                        >
                            <SelectTrigger className="w-full sm:w-44">
                                <SelectValue placeholder="Sort" />
                            </SelectTrigger>
                            <SelectContent>
                                {SORT_OPTIONS.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Major/year level/section: free-text like the student
                    create form (no fixed enum backs these fields), so
                    filters here are inputs rather than selects. */}
                <div className="grid grid-cols-3 gap-2">
                    <AutosuggestInput
                        placeholder="Major (e.g. Web Development)"
                        value={major}
                        suggestions={filterOptions?.majors ?? []}
                        onChange={(value) => {
                            setMajor(value);
                            setPage(1);
                        }}
                    />
                    <AutosuggestInput
                        placeholder="Year level (e.g. 1)"
                        value={yearLevel}
                        suggestions={filterOptions?.yearLevels ?? []}
                        onChange={(value) => {
                            setYearLevel(value);
                            setPage(1);
                        }}
                    />
                    <AutosuggestInput
                        placeholder="Section (e.g. A)"
                        value={section}
                        suggestions={filterOptions?.sections ?? []}
                        onChange={(value) => {
                            setSection(value);
                            setPage(1);
                        }}
                    />
                </div>

                {isLoading && <Text variant="small">Loading…</Text>}
                {!isLoading && historyPage?.data.length === 0 && (
                    <Text variant="small">No attendance records match these filters.</Text>
                )}

                {historyPage?.data.map((row) => {
                    const tone = STATUS_TONE[row.status];
                    const StatusIcon = STATUS_ICON[row.status];
                    return (
                        <Card key={row.id} className="overflow-hidden">
                            <CardContent className="space-y-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex min-w-0 items-center gap-4">
                                        {/* The student's own photo rather than a status glyph —
                                            the status itself moves to a small tinted badge in the
                                            corner (and is still spelled out in the pill on the
                                            right), so the row keeps its at-a-glance hue from the
                                            left edge without spending the whole tile on it. */}
                                        <UserAvatar student={row} size="lg">
                                            <AvatarBadge className={cn(TONE[tone].dot, 'text-white')}>
                                                <StatusIcon />
                                            </AvatarBadge>
                                        </UserAvatar>
                                        <div className="min-w-0">
                                            <Text className="truncate font-medium">
                                                {row.lastName}, {row.firstName}
                                            </Text>
                                            <Text variant="small" className="truncate">
                                                {row.studentNumber}
                                                {row.departmentCode ? ` · ${row.departmentCode}` : ''}
                                                {row.major ? ` ${row.major}` : ''}
                                                {row.yearLevel ? ` · Yr ${row.yearLevel}` : ''}
                                                {row.section ? ` · ${row.section}` : ''}
                                            </Text>
                                        </div>
                                    </div>
                                    <Badge variant="secondary" className={cn('shrink-0 border-transparent', TONE[tone].chip)}>
                                        {ATTENDANCE_STATUS_LABEL[row.status]}
                                    </Badge>
                                </div>

                                <Text variant="small">
                                    {row.eventName} — Day {row.dayNumber} ({formatDate(row.date)}) ·{' '}
                                    {WINDOW_TYPE_LABEL[row.windowType]} · {CHECK_TYPE_LABEL[row.checkType]}
                                </Text>

                                {/* The audit ask itself: who scanned it and when.
                                    No scan exists for Absent/Excluded/Pending
                                    rows, so there's nothing to attribute. */}
                                <Text variant="caption">
                                    {row.scannedByName
                                        ? `Scanned by ${row.scannedByName}${row.scannedByRole && row.scannedByRole !== Role.Officer ? ` (${ROLE_LABEL[row.scannedByRole as Role]})` : ''}${row.scannedAt ? ` on ${formatScannedAt(row.scannedAt)}` : ''}`
                                        : 'Not scanned'}
                                </Text>
                            </CardContent>
                        </Card>
                    );
                })}

                {historyPage && historyPage.lastPage > 1 && (
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
                            Page {historyPage.currentPage} of {historyPage.lastPage} — {historyPage.total} records
                        </Text>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={page >= historyPage.lastPage}
                            onClick={() => setPage((p) => p + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
