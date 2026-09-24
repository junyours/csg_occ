import { FileSpreadsheet, FileText, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
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
import { useEvents } from '@/application/events/use-events';
import { useRosterReportGeneration } from '@/application/reports/use-roster-report-generation';
import { EVENT_STATUS_BADGE_CLASS, EVENT_STATUS_LABEL, Role } from '@/domain/enums';
import { Heading, Text } from '@/presentation/components/typography';

// Mirrors EventModelPolicy::viewRosterReport exactly — an Officer runs
// the scanner, not the paperwork, so it's the other three roles only.
const REPORT_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin, Role.ScAdmin];

export function EventRosterReportPage() {
    const { eventId } = useParams<{ eventId: string }>();
    const student = useAuthStore((state) => state.student);
    const { data: events, isLoading } = useEvents();
    const { data: departments } = useDepartments();

    const [departmentFilter, setDepartmentFilter] = useState<string>('all');
    const [yearLevel, setYearLevel] = useState('');
    const [section, setSection] = useState('');

    const numericEventId = Number(eventId);
    const event = events?.find((candidate) => candidate.id === numericEventId);
    const { start, reset, generation, isActive, error, displayPercentage } = useRosterReportGeneration(numericEventId);

    // See master-report-page.tsx for why this exists: isActive only
    // flips once the start request resolves with a tracking row, so
    // without this the button looked clickable for however long that
    // request took — this covers that gap from the moment of the click.
    const [pendingFormat, setPendingFormat] = useState<'xlsx' | 'pdf' | null>(null);
    const isBusy = isActive || pendingFormat !== null;

    useEffect(() => {
        if (error) {
            toast.error(error);
        }
    }, [error]);

    if (!student) return null;

    // An SC Admin's own department is enforced server-side regardless of
    // what's sent (see RosterReportGenerationController) — the picker is
    // just hidden for them since it wouldn't do anything.
    const canFilterDepartment = student.role === Role.SystemAdmin || student.role === Role.CsgAdmin;

    if (!REPORT_ROLES.includes(student.role)) {
        return <Text variant="small">You don&apos;t have access to reports.</Text>;
    }

    if (isLoading) {
        return <Text variant="small">Loading…</Text>;
    }

    if (!event) {
        return <Text variant="small">Event not found.</Text>;
    }

    async function handleGenerate(format: 'xlsx' | 'pdf') {
        setPendingFormat(format);
        try {
            await start({
                departmentId: canFilterDepartment && departmentFilter !== 'all' ? Number(departmentFilter) : undefined,
                yearLevel,
                section,
                format,
            });
        } catch {
            toast.error('Could not start the report. Try narrowing the filters and try again.');
        } finally {
            setPendingFormat(null);
        }
    }

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            <div className="space-y-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <Heading level="h1">Roster Report</Heading>
                        <Text variant="small">{event.name}</Text>
                    </div>
                    <Badge variant="secondary" className={EVENT_STATUS_BADGE_CLASS[event.status]}>
                        {EVENT_STATUS_LABEL[event.status]}
                    </Badge>
                </div>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <Heading level="h3">Filters</Heading>
                    <Text variant="small">
                        Leave a field blank to include everything. The report is grouped into one sheet/page per
                        course, year level, and section — sorted course → year level → section, with students
                        listed A–Z by last name inside each group.
                    </Text>
                </CardHeader>
                <CardContent className="space-y-4">
                    {canFilterDepartment && (
                        <div className="space-y-2">
                            <Label htmlFor="department">Course / Department</Label>
                            <Select value={departmentFilter} onValueChange={setDepartmentFilter} disabled={isBusy}>
                                <SelectTrigger id="department" className="w-full">
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
                            <Label htmlFor="yearLevel">Year level</Label>
                            <Input
                                id="yearLevel"
                                placeholder="e.g. 1"
                                value={yearLevel}
                                onChange={(e) => setYearLevel(e.target.value)}
                                disabled={isBusy}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="section">Section</Label>
                            <Input
                                id="section"
                                placeholder="e.g. A"
                                value={section}
                                onChange={(e) => setSection(e.target.value)}
                                disabled={isBusy}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <Heading level="h3">Generate</Heading>
                    <Text variant="small">
                        Present, Late, Absent, and Excluded are colour-coded, with a penalty total per student and
                        per group. Large exports run in the background — the bar below tracks real progress, not a
                        timer, and the file downloads automatically once it's ready.
                    </Text>
                </CardHeader>
                <CardContent className="space-y-4">
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
        </div>
    );
}
