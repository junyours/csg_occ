import { useMemo, useState } from 'react';
import { CalendarX, Moon, ReceiptText, Sun, Sunrise, Wallet, type LucideIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useAuthStore } from '@/application/auth/auth.store';
import { useMyAttendanceHistory } from '@/application/events/use-my-attendance-history';
import { useMyPenaltyHistory } from '@/application/penalties/use-my-penalty-history';
import type { MyPenaltyEntry } from '@/infrastructure/penalties/penalties.repository.http';
import {
    ATTENDANCE_STATUS_BADGE_CLASS,
    ATTENDANCE_STATUS_LABEL,
    AttendanceStatus,
    CHECK_TYPE_LABEL,
    Role,
    WINDOW_TYPE_LABEL,
} from '@/domain/enums';
import { cn, formatCurrency, formatDate, formatScannedAt, formatTimeOfDay } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { EmptyState, ListSkeleton } from '@/presentation/components/empty-state';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';
import { CHIP } from '@/presentation/components/spacing';

const ALL = 'all';

const WINDOW_STYLE: Record<string, { Icon: LucideIcon; tone: Tone }> = {
    morning: { Icon: Sunrise, tone: 'amber' },
    afternoon: { Icon: Sun, tone: 'orange' },
    evening: { Icon: Moon, tone: 'violet' },
};

/** Attendance status → the hue it carries everywhere else in the app. */
const STATUS_TONE: Record<string, Tone> = {
    [AttendanceStatus.Present]: 'emerald',
    [AttendanceStatus.Late]: 'amber',
    [AttendanceStatus.Absent]: 'red',
    [AttendanceStatus.Excluded]: 'neutral',
    [AttendanceStatus.Pending]: 'neutral',
};

// Ties a penalty back to the session it was issued for. Penalty entries
// don't carry a sessionId of their own (see MyPenaltyHistoryController —
// a penalty is recorded against an event day/window/check, not a
// session row), so matching happens on that same tuple instead.
function sessionKey(parts: { eventId: number; dayNumber: number; windowType: string; checkType: string }): string {
    return `${parts.eventId}:${parts.dayNumber}:${parts.windowType}:${parts.checkType}`;
}

export function AttendanceHistoryPage() {
    const student = useAuthStore((state) => state.student);
    const isStudent = student?.role === Role.Student;

    const { data: entries, isLoading } = useMyAttendanceHistory();
    // Only students carry a penalty balance — see account-page.tsx for
    // the same guard. Merged into this page (rather than kept as its
    // own screen) so a penalty always shows next to the session that
    // caused it, for auditing.
    const { data: penaltyHistory, isLoading: isPenaltyLoading } = useMyPenaltyHistory({ enabled: isStudent });

    const [eventFilter, setEventFilter] = useState(ALL);
    const [statusFilter, setStatusFilter] = useState(ALL);

    // One dropdown entry per event, in the order events already come
    // back in (newest first) — de-duplicated by id since an event
    // contributes one row per session.
    const eventOptions = useMemo(() => {
        const seen = new Map<number, string>();
        for (const entry of entries ?? []) {
            if (!seen.has(entry.eventId)) seen.set(entry.eventId, entry.eventName);
        }
        return Array.from(seen, ([id, name]) => ({ id, name }));
    }, [entries]);

    // Every penalty grouped by the session that caused it, so it can
    // render inline with that session's attendance row.
    const penaltiesBySession = useMemo(() => {
        const map = new Map<string, MyPenaltyEntry[]>();
        for (const penalty of penaltyHistory?.entries ?? []) {
            const key = sessionKey(penalty);
            const bucket = map.get(key);
            if (bucket) bucket.push(penalty);
            else map.set(key, [penalty]);
        }
        return map;
    }, [penaltyHistory]);

    const filteredEntries = (entries ?? []).filter((entry) => {
        if (eventFilter !== ALL && String(entry.eventId) !== eventFilter) return false;
        if (statusFilter !== ALL) {
            const status = entry.attendanceStatus ?? AttendanceStatus.Pending;
            if (status !== statusFilter) return false;
        }
        return true;
    });

    // Penalties that don't line up with any session currently in this
    // student's attendance history — still surfaced, rather than
    // silently dropped, so the audit trail always accounts for every
    // peso in the total.
    const unmatchedPenalties = useMemo(() => {
        const matchedIds = new Set<number>();
        for (const entry of entries ?? []) {
            const bucket = penaltiesBySession.get(sessionKey(entry));
            bucket?.forEach((penalty) => matchedIds.add(penalty.id));
        }
        return (penaltyHistory?.entries ?? []).filter((penalty) => !matchedIds.has(penalty.id));
    }, [entries, penaltyHistory, penaltiesBySession]);

    // Same event/status filters the attendance rows use, applied to the
    // unmatched penalties too — they carry an eventId but no session
    // status, so a status filter (which only makes sense for a specific
    // session) hides them entirely rather than guessing.
    const visibleUnmatchedPenalties = useMemo(() => {
        if (statusFilter !== ALL) return [];
        return unmatchedPenalties.filter((penalty) => eventFilter === ALL || String(penalty.eventId) === eventFilter);
    }, [unmatchedPenalties, eventFilter, statusFilter]);

    const isFiltered = eventFilter !== ALL || statusFilter !== ALL;

    // The balance banner mirrors whatever's currently on screen: with no
    // filters it's the same figure as the account page, but narrowing to
    // one event or status recomputes it from only the penalties still
    // visible below (matched rows within filteredEntries, plus whichever
    // unmatched ones still pass the event filter).
    const visiblePenaltyTotal = useMemo(() => {
        let total = 0;
        for (const entry of filteredEntries) {
            const bucket = penaltiesBySession.get(sessionKey(entry));
            bucket?.forEach((penalty) => {
                if (!penalty.isReversed) total += penalty.amount;
            });
        }
        for (const penalty of visibleUnmatchedPenalties) {
            if (!penalty.isReversed) total += penalty.amount;
        }
        return total;
    }, [filteredEntries, penaltiesBySession, visibleUnmatchedPenalties]);

    return (
        <div className="mx-auto max-w-2xl space-y-8">
            <div>
                <Heading level="h1">Attendance & Penalties</Heading>
                <Text variant="small">Your Present / Late / Absent record, with any penalty shown against the session it came from.</Text>
            </div>

            {isStudent && !isPenaltyLoading && penaltyHistory && (
                <BalanceBanner
                    total={isFiltered ? visiblePenaltyTotal : penaltyHistory.total}
                    label={isFiltered ? 'Penalty total for current filters' : 'Total penalty balance'}
                />
            )}

            <div className="grid grid-cols-2 gap-4">
                <Select value={eventFilter} onValueChange={setEventFilter}>
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Event" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All events</SelectItem>
                        {eventOptions.map((option) => (
                            <SelectItem key={option.id} value={String(option.id)}>
                                {option.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select value={statusFilter} onValueChange={setStatusFilter}>
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All statuses</SelectItem>
                        {Object.values(AttendanceStatus).map((status) => (
                            <SelectItem key={status} value={status}>
                                {ATTENDANCE_STATUS_LABEL[status]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {isLoading && <ListSkeleton rows={3} />}

            {!isLoading && filteredEntries.length === 0 && (
                <EmptyState
                    Icon={CalendarX}
                    title={entries && entries.length > 0 ? 'Nothing matches these filters' : 'No attendance history yet'}
                    description={
                        entries && entries.length > 0
                            ? 'Try widening the event or status filter above.'
                            : 'Your record fills in after your first scanned session.'
                    }
                />
            )}

            <div className="space-y-2">
                {filteredEntries.map((entry) => {
                    const status = entry.attendanceStatus ?? AttendanceStatus.Pending;
                    const penalties = penaltiesBySession.get(sessionKey(entry)) ?? [];

                    const windowStyle = WINDOW_STYLE[entry.windowType] ?? { Icon: Sun, tone: 'neutral' as Tone };

                    return (
                        <div key={entry.sessionId} className="space-y-2 rounded-2xl border border-border bg-card p-4">
                            <div className="flex items-start gap-4">
                                {/* The window tile is filled in the status's hue,
                                    not the window's — on this screen the thing
                                    you're scanning for is your result, and one
                                    lit square per row is what makes a column of
                                    thirty rows readable. */}
                                <Tile tone={STATUS_TONE[status] ?? 'neutral'} variant="solid" Icon={windowStyle.Icon} />

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Text variant="small" className="min-w-0 truncate font-medium text-foreground">
                                            {entry.eventName}
                                        </Text>
                                        <Badge variant="secondary" className={ATTENDANCE_STATUS_BADGE_CLASS[status]}>
                                            {ATTENDANCE_STATUS_LABEL[status]}
                                        </Badge>
                                    </div>
                                    <Text variant="caption">
                                        Day {entry.dayNumber} · {formatDate(entry.date)}
                                    </Text>
                                    <Text variant="caption">
                                        {WINDOW_TYPE_LABEL[entry.windowType]} · {CHECK_TYPE_LABEL[entry.checkType]} ·{' '}
                                        {formatTimeOfDay(entry.startTime)}–{formatTimeOfDay(entry.endTime)}
                                    </Text>
                                    {entry.scannedAt && (
                                        <Text variant="caption" className="mt-2">
                                            {formatScannedAt(entry.scannedAt)}
                                            {entry.scannedByName ? ` · by ${entry.scannedByName}` : ''}
                                        </Text>
                                    )}
                                </div>
                            </div>

                            {penalties.length > 0 && (
                                <div className={cn('space-y-2 rounded-xl border p-2', TONE.red.wash)}>
                                    {penalties.map((penalty) => (
                                        <PenaltyDetail key={penalty.id} penalty={penalty} />
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            {visibleUnmatchedPenalties.length > 0 && (
                <div className="space-y-2">
                    <Text variant="caption">Other penalties (not tied to a session above)</Text>
                    <div className="space-y-2">
                        {visibleUnmatchedPenalties.map((penalty) => (
                            <div key={penalty.id} className="space-y-2 rounded-2xl border border-border bg-card p-4">
                                <Text className="font-medium">{penalty.eventName}</Text>
                                <Text variant="small">
                                    Day {penalty.dayNumber} — {formatDate(penalty.date)} · {WINDOW_TYPE_LABEL[penalty.windowType]} ·{' '}
                                    {CHECK_TYPE_LABEL[penalty.checkType]}
                                </Text>
                                <PenaltyDetail penalty={penalty} />
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// One penalty line: amount + reason it was issued, and — if it's since
// been waived — who reversed it. This is the "audit" detail: it's what
// lets a student see *why* a charge landed on a specific session rather
// than just seeing a lump total.
function PenaltyDetail({ penalty }: { penalty: MyPenaltyEntry }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div className="min-w-0">
                <Text variant="small" className={cn('text-foreground', penalty.isReversed && 'line-through text-muted-foreground')}>
                    {penalty.reason}
                </Text>
                {penalty.isReversed && (
                    <Text variant="caption">Reversed{penalty.reversedBy ? ` by ${penalty.reversedBy}` : ''}</Text>
                )}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <span
                    className={cn(
                        'text-small font-semibold tabular-nums',
                        penalty.isReversed ? 'text-muted-foreground line-through' : 'text-red-600 dark:text-red-400',
                    )}
                >
                    {formatCurrency(penalty.amount)}
                </span>
                {penalty.isReversed && (
                    <span className={cn(CHIP, TONE.neutral.chip)}>Reversed</span>
                )}
            </div>
        </div>
    );
}

/**
 * The running total, as the one lit object on the screen. Emerald when the
 * student owes nothing — "clear" is worth showing positively rather than as
 * a neutral zero.
 */
function BalanceBanner({ total, label }: { total: number; label: string }) {
    const owes = total > 0;
    const tone: Tone = owes ? 'red' : 'emerald';

    return (
        <div className={cn('flex items-center gap-4 rounded-2xl border p-4', TONE[tone].wash)}>
            <Tile tone={tone} size="lg" variant="solid" Icon={owes ? Wallet : ReceiptText} />
            <div className="min-w-0">
                <Text variant="caption">{label}</Text>
                <span className={cn('block text-h2 leading-tight font-semibold tabular-nums', TONE[tone].text)}>
                    {formatCurrency(total)}
                </span>
            </div>
        </div>
    );
}
