import {
    CheckCircle2,
    ChevronRight,
    Clock,
    FileBarChart,
    FileSpreadsheet,
    FileText,
    Loader2,
    MinusCircle,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useAuthStore } from '@/application/auth/auth.store';
import { useDepartments } from '@/application/departments/use-departments';
import { useMasterReport } from '@/application/reports/use-master-report';
import { useMasterReportGeneration } from '@/application/reports/use-master-report-generation';
import { EVENT_STATUS_BADGE_CLASS, EVENT_STATUS_LABEL, Role } from '@/domain/enums';
import { Heading, Text } from '@/presentation/components/typography';
import { StatTile } from '@/presentation/components/tile';
import type { Tone } from '@/presentation/components/tone';

// Mirrors EventModelPolicy::viewRosterReport exactly — same three roles
// as the per-event roster report page.
const REPORT_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin, Role.ScAdmin];

// Same status→tile language as the dashboard/attendance history, so an
// event card here reads the same way those do rather than inventing its
// own bordered mini-box style.
const EVENT_STAT_CONFIG: { key: 'present' | 'late' | 'absent' | 'excluded'; label: string; tone: Tone; Icon: LucideIcon }[] = [
    { key: 'present', label: 'Present', tone: 'emerald', Icon: CheckCircle2 },
    { key: 'late', label: 'Late', tone: 'amber', Icon: Clock },
    { key: 'absent', label: 'Absent', tone: 'red', Icon: XCircle },
    { key: 'excluded', label: 'Excluded', tone: 'neutral', Icon: MinusCircle },
];

export function MasterReportPage() {
    const navigate = useNavigate();
    const student = useAuthStore((state) => state.student);
    const { data, isLoading, isError } = useMasterReport();
    const { data: departments } = useDepartments();

    // Order matters here — it's what decides the left-to-right column
    // order in the combined file (see BuildMasterRosterReport on the
    // server), so this is a plain ordered array, not a Set. Selecting
    // adds to the end; deselecting just filters it out.
    const [selectedEventIds, setSelectedEventIds] = useState<number[]>([]);
    const [departmentFilter, setDepartmentFilter] = useState<string>('all');
    const [yearLevel, setYearLevel] = useState('');
    const [section, setSection] = useState('');

    const { start, reset, generation, isActive, error, displayPercentage } = useMasterReportGeneration();

    // Tracks the button just clicked from the moment of the click until
    // the start request resolves (or fails) — isActive only flips once
    // that request comes back with a tracking row, so without this the
    // button sat looking clickable for however long that request took,
    // which is exactly what read as "nothing happens" when clicked.
    const [pendingFormat, setPendingFormat] = useState<'xlsx' | 'pdf' | null>(null);
    const isBusy = isActive || pendingFormat !== null;

    useEffect(() => {
        if (error) {
            toast.error(error);
        }
    }, [error]);

    if (!student) return null;

    if (!REPORT_ROLES.includes(student.role)) {
        return <Text variant="small">You don&apos;t have access to reports.</Text>;
    }

    const canFilterDepartment = student.role === Role.SystemAdmin || student.role === Role.CsgAdmin;

    function toggleEvent(eventId: number) {
        setSelectedEventIds((current) =>
            current.includes(eventId) ? current.filter((id) => id !== eventId) : [...current, eventId],
        );
    }

    async function handleGenerate(format: 'xlsx' | 'pdf') {
        setPendingFormat(format);
        try {
            await start({
                eventIds: selectedEventIds,
                departmentId: canFilterDepartment && departmentFilter !== 'all' ? Number(departmentFilter) : undefined,
                yearLevel,
                section,
                format,
            });
        } catch {
            toast.error('Could not start the master report. Try narrowing the filters and try again.');
        } finally {
            setPendingFormat(null);
        }
    }

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            <div className="flex items-center gap-4">
                <FileBarChart className="size-6 text-muted-foreground" />
                <div>
                    <Heading level="h1">Reports</Heading>
                    <Text variant="small">
                        Every event in the current academic year and semester, with total attendance. Select two or
                        more events below to combine them into one master report.
                    </Text>
                </div>
            </div>

            <Separator />

            {isLoading && <Text variant="small">Loading…</Text>}

            {isError && (
                <Text variant="small">Couldn&apos;t load the master report. Try refreshing the page.</Text>
            )}

            {data && !data.semester && (
                <Card>
                    <CardContent className="py-8 text-center">
                        <Text variant="small">
                            No active academic year and semester right now — activate one from Academic Years to
                            start seeing events here.
                        </Text>
                    </CardContent>
                </Card>
            )}

            {data?.semester && (
                <>
                    <div>
                        <Text variant="small" className="text-muted-foreground">
                            {data.academic_year?.name} — {data.semester.name}
                        </Text>
                    </div>

                    {data.events.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center">
                                <Text variant="small">No events yet this semester.</Text>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-4">
                            {data.events.map((event) => {
                                const isSelected = selectedEventIds.includes(event.id);

                                return (
                                    <Card
                                        key={event.id}
                                        className={`transition-colors ${isSelected ? 'border-primary bg-accent/40' : ''}`}
                                    >
                                        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
                                            <label className="flex flex-1 cursor-pointer items-center gap-4">
                                                <input
                                                    type="checkbox"
                                                    className="size-4 shrink-0 accent-primary"
                                                    checked={isSelected}
                                                    disabled={isBusy}
                                                    onChange={() => toggleEvent(event.id)}
                                                />
                                                <div className="flex items-center gap-2">
                                                    <Heading level="h3">{event.name}</Heading>
                                                    <Badge
                                                        variant="secondary"
                                                        className={EVENT_STATUS_BADGE_CLASS[event.status as keyof typeof EVENT_STATUS_BADGE_CLASS]}
                                                    >
                                                        {EVENT_STATUS_LABEL[event.status as keyof typeof EVENT_STATUS_LABEL]}
                                                    </Badge>
                                                </div>
                                            </label>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="shrink-0 gap-2 text-muted-foreground"
                                                onClick={() => navigate(`/events/${event.id}/report`)}
                                            >
                                                View
                                                <ChevronRight className="size-4" />
                                            </Button>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                                {EVENT_STAT_CONFIG.map(({ key, label, tone, Icon }) => (
                                                    <StatTile key={key} label={label} value={event[key]} Icon={Icon} tone={tone} />
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}

                    {selectedEventIds.length > 0 && (
                        <>
                            <Separator />

                            <Card>
                                <CardHeader>
                                    <Heading level="h3">Master Report</Heading>
                                    <Text variant="small">
                                        {selectedEventIds.length} event{selectedEventIds.length === 1 ? '' : 's'}{' '}
                                        selected. The combined file lists every session from every selected event
                                        side by side — event, then day, then morning/afternoon/evening, then time
                                        in/out — with one shared roster of students, grouped the same way the
                                        per-event report is.
                                    </Text>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {canFilterDepartment && (
                                        <div className="space-y-2">
                                            <Label htmlFor="master-department">Course / Department</Label>
                                            <Select value={departmentFilter} onValueChange={setDepartmentFilter} disabled={isBusy}>
                                                <SelectTrigger id="master-department" className="w-full">
                                                    <SelectValue placeholder="All departments" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">All departments</SelectItem>
                                                    {departments?.map((department) => (
                                                        <SelectItem key={department.id} value={String(department.id)}>
                                                            {department.code}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}

                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="master-year-level">Year level</Label>
                                            <Input
                                                id="master-year-level"
                                                placeholder="e.g. 1"
                                                value={yearLevel}
                                                onChange={(e) => setYearLevel(e.target.value)}
                                                disabled={isBusy}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="master-section">Section</Label>
                                            <Input
                                                id="master-section"
                                                placeholder="e.g. A"
                                                value={section}
                                                onChange={(e) => setSection(e.target.value)}
                                                disabled={isBusy}
                                            />
                                        </div>
                                    </div>

                                    <Separator />

                                    {isActive && generation ? (
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between">
                                                <Text variant="small">
                                                    {generation.status === 'pending' ? 'Starting…' : 'Generating…'}
                                                </Text>
                                                <Text variant="small">{Math.round(displayPercentage)}%</Text>
                                            </div>
                                            <Progress value={displayPercentage} />
                                            <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={reset}>
                                                Cancel
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="flex flex-col gap-4 sm:flex-row">
                                            <Button
                                                className="flex-1 gap-2"
                                                onClick={() => handleGenerate('xlsx')}
                                                disabled={isBusy}
                                            >
                                                {pendingFormat === 'xlsx' ? (
                                                    <>
                                                        <Loader2 className="size-4 animate-spin" />
                                                        Generating…
                                                    </>
                                                ) : (
                                                    <>
                                                        <FileSpreadsheet className="size-4" />
                                                        Generate Excel (.xlsx)
                                                    </>
                                                )}
                                            </Button>
                                            <Button
                                                variant="outline"
                                                className="flex-1 gap-2"
                                                onClick={() => handleGenerate('pdf')}
                                                disabled={isBusy}
                                            >
                                                {pendingFormat === 'pdf' ? (
                                                    <>
                                                        <Loader2 className="size-4 animate-spin" />
                                                        Generating…
                                                    </>
                                                ) : (
                                                    <>
                                                        <FileText className="size-4" />
                                                        Generate PDF
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                    )}
                                    {generation?.status === 'completed' && (
                                        <Text variant="small">Downloaded. Generate again anytime with the buttons above.</Text>
                                    )}
                                </CardContent>
                            </Card>
                        </>
                    )}
                </>
            )}
        </div>
    );
}
