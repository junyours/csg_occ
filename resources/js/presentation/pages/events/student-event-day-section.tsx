import { Link } from 'react-router-dom';
import { CalendarDays, Moon, QrCode, Sun, Sunrise, type LucideIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { MyAttendanceDay } from '@/infrastructure/events/events.repository.http';
import {
    ATTENDANCE_STATUS_BADGE_CLASS,
    ATTENDANCE_STATUS_LABEL,
    CHECK_TYPE_LABEL,
    SessionStatus,
    WINDOW_TYPE_LABEL,
    WindowType,
} from '@/domain/enums';
import { cn, formatDate, formatScannedAt, formatTimeOfDay } from '@/lib/utils';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';

const WINDOW_STYLE: Record<string, { Icon: LucideIcon; tone: Tone }> = {
    [WindowType.Morning]: { Icon: Sunrise, tone: 'amber' },
    [WindowType.Afternoon]: { Icon: Sun, tone: 'orange' },
    [WindowType.Evening]: { Icon: Moon, tone: 'violet' },
};

export function StudentEventDaySection({ day }: { day: MyAttendanceDay }) {
    return (
        <div className="space-y-4 rounded-3xl border border-border bg-card p-4">
            <div className="flex items-center gap-2">
                <Tile tone="sky" size="sm" variant="soft" Icon={CalendarDays} />
                <div className="min-w-0">
                    <Text variant="small" className="font-medium text-foreground">
                        Day {day.dayNumber}
                    </Text>
                    <Text variant="caption">{formatDate(day.date)}</Text>
                </div>
            </div>

            {day.sessions.length === 0 && (
                <Text variant="small" className="px-0.5">
                    No sessions scheduled yet.
                </Text>
            )}

            <div className="space-y-2">
                {day.sessions.map((session) => {
                    const windowStyle = WINDOW_STYLE[session.windowType] ?? { Icon: Sun, tone: 'neutral' as Tone };
                    const isOngoing = session.sessionStatus === SessionStatus.Ongoing;
                    const needsScan = !session.attendanceStatus && isOngoing;

                    return (
                        <div
                            key={session.id}
                            className={cn('space-y-2 rounded-2xl border border-border p-4', needsScan && TONE.violet.wash)}
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex min-w-0 items-center gap-4">
                                    <Tile tone={windowStyle.tone} variant={isOngoing ? 'solid' : 'soft'} Icon={windowStyle.Icon} />
                                    <div className="min-w-0">
                                        <Text variant="small" className="font-medium text-foreground">
                                            {WINDOW_TYPE_LABEL[session.windowType]} · {CHECK_TYPE_LABEL[session.checkType]}
                                        </Text>
                                        <Text variant="caption">
                                            {formatTimeOfDay(session.startTime)}–{formatTimeOfDay(session.endTime)}
                                        </Text>
                                    </div>
                                </div>

                                {session.attendanceStatus && (
                                    <div className="flex shrink-0 flex-col items-end gap-2">
                                        <Badge variant="secondary" className={ATTENDANCE_STATUS_BADGE_CLASS[session.attendanceStatus]}>
                                            {ATTENDANCE_STATUS_LABEL[session.attendanceStatus]}
                                        </Badge>
                                        {session.scannedAt && <Text variant="caption">{formatScannedAt(session.scannedAt)}</Text>}
                                        {session.scannedByName && <Text variant="caption">Scanned by {session.scannedByName}</Text>}
                                    </div>
                                )}
                            </div>

                            {/*
                              The one action a student can take on this whole
                              screen, so it gets the only colored surface — and
                              the QR button sits inside it rather than being a
                              quiet outline off to the side.
                            */}
                            {needsScan && (
                                <div className="flex items-center justify-between gap-4 rounded-xl bg-card p-2">
                                    <Text variant="caption" className="min-w-0">
                                        Open now — show your QR to the attendance committee.
                                    </Text>
                                    <Button asChild size="sm" className="shrink-0 gap-2">
                                        <Link to="/profile">
                                            <QrCode className="size-4" />
                                            My QR
                                        </Link>
                                    </Button>
                                </div>
                            )}

                            {!session.attendanceStatus && session.sessionStatus === SessionStatus.Scheduled && (
                                <Text variant="caption" className="px-0.5">
                                    Not started yet.
                                </Text>
                            )}

                            {!session.attendanceStatus && session.sessionStatus === SessionStatus.Ended && (
                                <Text variant="caption" className="px-0.5">
                                    Closed — your result hasn't been recorded yet.
                                </Text>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
