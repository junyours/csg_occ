import { type FormEvent, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Clock, RotateCcw, Wallet, XCircle } from 'lucide-react';
import { toast } from 'sonner';

import { AvatarBadge } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useDepartments } from '@/application/departments/use-departments';
import { useEvents } from '@/application/events/use-events';
import { usePenalties } from '@/application/penalties/use-penalties';
import { useReversePenalty } from '@/application/penalties/use-reverse-penalty';
import type { PenaltyLedgerEntry } from '@/infrastructure/penalties/penalties.repository.http';
import { CHECK_TYPE_LABEL, WINDOW_TYPE_LABEL } from '@/domain/enums';
import { cn, formatCurrency, formatDate, formatScannedAt } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { StatTile, Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import { UserAvatar } from '@/presentation/components/user-avatar';

const PER_PAGE = 20;

type StatusFilter = 'all' | 'active' | 'reversed';

// EndSession always writes the penalty reason as "{Absent|Late} - {check
// label}" (see EndSession::applyPenalties) — the outcome is the only part
// of that string worth calling out here, since the check label ("Time
// In"/"Time Out") is already shown by the session line right next to it.
function penaltyStatusFromReason(reason: string): string {
    return reason.split(' - ')[0] ?? reason;
}

function isStatusFilter(value: string | null): value is StatusFilter {
    return value === 'all' || value === 'active' || value === 'reversed';
}

export function PenaltiesPage() {
    const { data: departments } = useDepartments();
    const { data: events } = useEvents();

    // The dashboard's "Total penalty balance" card links here with
    // ?status=active (that total excludes reversed penalties — see
    // BuildDashboardSummary::penaltyTotal), so the filter starts synced
    // to whatever number the person just tapped instead of defaulting
    // to "all" and looking like a different, bigger total.
    const [searchParams] = useSearchParams();
    const initialStatus = searchParams.get('status');

    const [search, setSearch] = useState('');
    const [departmentFilter, setDepartmentFilter] = useState<string>('all');
    const [eventFilter, setEventFilter] = useState<string>('all');
    const [statusFilter, setStatusFilter] = useState<StatusFilter>(isStatusFilter(initialStatus) ? initialStatus : 'all');
    const [page, setPage] = useState(1);

    const { data: ledgerPage, isLoading } = usePenalties({
        search: search || undefined,
        departmentId: departmentFilter !== 'all' ? Number(departmentFilter) : undefined,
        eventId: eventFilter !== 'all' ? Number(eventFilter) : undefined,
        status: statusFilter,
        page,
        perPage: PER_PAGE,
    });

    const [reversingId, setReversingId] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    const reversePenalty = useReversePenalty();

    function startReversing(row: PenaltyLedgerEntry) {
        setReversingId(row.id);
        setReason('');
    }

    function cancelReversing() {
        setReversingId(null);
        setReason('');
    }

    function handleReverseSubmit(event: FormEvent, row: PenaltyLedgerEntry) {
        event.preventDefault();

        if (reason.trim().length < 3) {
            toast.error('Enter a reason — at least a few words.');
            return;
        }

        reversePenalty.mutate(
            { penaltyId: row.id, reason: reason.trim() },
            {
                onSuccess: () => {
                    toast.success('Penalty reversed.');
                    cancelReversing();
                },
                onError: (error: unknown) => {
                    const status = (error as { response?: { status?: number } }).response?.status;
                    if (status === 409) {
                        toast.error('This penalty was already reversed.');
                    } else {
                        toast.error('Could not reverse this penalty.');
                    }
                },
            },
        );
    }

    return (
        <div className="mx-auto max-w-3xl space-y-8">
            <div>
                <Heading level="h1">Penalties</Heading>
                <Text variant="small">
                    Every penalty charged across every event — reverse one here to excuse a student after the fact.
                </Text>
            </div>

            <div className="space-y-4">
                {/* Below `sm`, the search bar stays full-width but the
                    three selects pack into a 2-col grid (department+event
                    on one row, status alone on the next) instead of each
                    getting its own full-width row — four stacked rows for
                    "just a filter" ate too much vertical space before
                    anyone reached the actual list. `sm:contents` drops the
                    grid wrapper on larger screens so `sm:flex sm:flex-wrap`
                    on the parent lays every control out inline again. */}
                <div className="space-y-2 sm:flex sm:flex-wrap sm:items-start sm:gap-2 sm:space-y-0">
                    <Input
                        placeholder="Search by ID or name…"
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                        className="sm:max-w-56"
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
                                setStatusFilter(value as StatusFilter);
                                setPage(1);
                            }}
                        >
                            <SelectTrigger className="col-span-2 w-full sm:w-36">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="reversed">Reversed</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {ledgerPage && (
                    <Card className={cn('border', TONE.red.wash)}>
                        <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-4">
                                <Tile tone="red" size="lg" variant="solid" Icon={Wallet} />
                                <div>
                                    <Text variant="small">Total (filtered)</Text>
                                    <span className={cn('block text-h1 leading-tight font-semibold tabular-nums', TONE.red.text)}>
                                        {formatCurrency(ledgerPage.summary.total)}
                                    </span>
                                </div>
                            </div>
                            {/* Grid on mobile so three stats stay legible instead
                                of cramming into one row; from `sm` up they sit
                                inline, right-aligned next to the total. */}
                            <div className="grid grid-cols-3 gap-2 sm:flex sm:gap-2">
                                <StatTile size="sm" label="Absent" value={ledgerPage.summary.absentCount} Icon={XCircle} tone="red" />
                                <StatTile size="sm" label="Late" value={ledgerPage.summary.lateCount} Icon={Clock} tone="amber" />
                                <StatTile size="sm" label="Penalties" value={ledgerPage.summary.count} Icon={Wallet} tone="neutral" />
                            </div>
                        </CardContent>
                    </Card>
                )}

                {isLoading && <Text variant="small">Loading…</Text>}
                {!isLoading && ledgerPage?.data.length === 0 && (
                    <Text variant="small">No penalties match these filters.</Text>
                )}

                {ledgerPage?.data.map((row) => (
                    <Card key={row.id} className="overflow-hidden">
                        {reversingId === row.id ? (
                            <CardContent>
                                <form onSubmit={(e) => handleReverseSubmit(e, row)} className="space-y-4">
                                    <Text variant="small" className="font-medium">
                                        Reverse {formatCurrency(row.amount)} — {row.lastName}, {row.firstName}
                                    </Text>
                                    <div className="space-y-2">
                                        <Label htmlFor={`reason-${row.id}`}>Reason</Label>
                                        <Input
                                            id={`reason-${row.id}`}
                                            value={reason}
                                            onChange={(e) => setReason(e.target.value)}
                                            placeholder="e.g. Documented excuse letter approved"
                                            autoFocus
                                        />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={reversePenalty.isPending}>
                                            {reversePenalty.isPending ? 'Reversing…' : 'Confirm reversal'}
                                        </Button>
                                        <Button type="button" size="sm" variant="outline" onClick={cancelReversing}>
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        ) : (
                            <CardContent className="space-y-4">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex min-w-0 items-center gap-4">
                                        {/* Student's own photo instead of a generic wallet icon — a
                                            reversed row keeps a small badge in the corner so that
                                            status is still visible at a glance without the icon
                                            swap we used to do. */}
                                        <UserAvatar
                                            student={row}
                                            size="lg"
                                            className={cn(row.isReversed && 'opacity-60 grayscale')}
                                        >
                                            {row.isReversed && (
                                                <AvatarBadge className="bg-muted-foreground">
                                                    <RotateCcw />
                                                </AvatarBadge>
                                            )}
                                        </UserAvatar>
                                        <div className="min-w-0">
                                            <Text className="truncate font-medium">
                                                {row.lastName}, {row.firstName}
                                            </Text>
                                            <Text variant="small" className="truncate">
                                                {row.studentNumber}
                                                {row.departmentCode ? ` · ${row.departmentCode}` : ''}
                                            </Text>
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <span
                                            className={cn(
                                                'block font-semibold tabular-nums',
                                                row.isReversed ? 'text-muted-foreground line-through' : TONE.red.text,
                                            )}
                                        >
                                            {formatCurrency(row.amount)}
                                        </span>
                                        {row.isReversed && (
                                            <Badge variant="outline" className="mt-2 text-muted-foreground">
                                                Reversed
                                            </Badge>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <Text variant="small" className={cn(row.isReversed && 'line-through')}>
                                        {row.eventName} — Day {row.dayNumber} ({formatDate(row.date)}) ·{' '}
                                        {WINDOW_TYPE_LABEL[row.windowType]} · {CHECK_TYPE_LABEL[row.checkType]}
                                    </Text>
                                    {/* The session already conveys check type ("Morning · Time
                                        In"), so the badge only needs the outcome (Absent/Late) —
                                        stated plainly as "Absent - Time In" it just repeated
                                        "Time In" a second time right below the line that already
                                        said it. */}
                                    <Badge
                                        variant="secondary"
                                        className={cn(
                                            'border-transparent',
                                            row.isReversed
                                                ? 'text-muted-foreground line-through'
                                                : penaltyStatusFromReason(row.reason) === 'Late'
                                                  ? TONE.amber.chip
                                                  : TONE.red.chip,
                                        )}
                                    >
                                        {penaltyStatusFromReason(row.reason) === 'Late' ? (
                                            <Clock className="size-3" />
                                        ) : (
                                            <XCircle className="size-3" />
                                        )}
                                        {penaltyStatusFromReason(row.reason)}
                                    </Badge>
                                </div>

                                {row.isReversed ? (
                                    <Text variant="caption">
                                        Reversed{row.reversedByName ? ` by ${row.reversedByName}` : ''}
                                        {row.reversedAt ? ` on ${formatScannedAt(row.reversedAt)}` : ''}
                                        {row.reversalReason ? ` — “${row.reversalReason}”` : ''}
                                    </Text>
                                ) : (
                                    <div className="pt-2">
                                        <Button size="sm" variant="outline" onClick={() => startReversing(row)}>
                                            Reverse
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        )}
                    </Card>
                ))}

                {ledgerPage && ledgerPage.lastPage > 1 && (
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
                            Page {ledgerPage.currentPage} of {ledgerPage.lastPage} — {ledgerPage.total} penalties
                        </Text>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={page >= ledgerPage.lastPage}
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
