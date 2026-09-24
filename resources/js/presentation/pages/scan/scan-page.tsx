import { useCallback, useEffect, useRef, useState } from 'react';
import { BrowserQRCodeReader } from '@zxing/browser';
import type { IScannerControls } from '@zxing/browser';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    Clock,
    Loader2,
    Moon,
    ScanLine,
    Sun,
    Sunrise,
    Undo2,
    X,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
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
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useRecentScans } from '@/application/sessions/use-recent-scans';
import { useReverseScan } from '@/application/sessions/use-reverse-scan';
import { useScanAttendance } from '@/application/sessions/use-scan-attendance';
import { useScannableSessions, type ScannableSession } from '@/application/sessions/use-scannable-sessions';
import { AttendanceStatus, CHECK_TYPE_LABEL, ScanOutcome } from '@/domain/enums';
import { ScanError, type ScanResult, type ScannedStudent } from '@/infrastructure/sessions/sessions.repository.http';
import { Heading, Text } from '@/presentation/components/typography';
import { UserAvatar } from '@/presentation/components/user-avatar';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';
import { cn } from '@/lib/utils';

/**
 * One-tap reasons for the reversal dialog. The officer at the gate has a
 * queue in front of them — typing a reason for every reversal isn't
 * realistic — so these cover the common case (a QR presented by someone
 * other than its owner) plus the next couple of most likely ones. Picking
 * a preset just fills the text field below; the officer can still edit or
 * replace it, and leaving it untouched sends no reason at all, which the
 * server fills in with ReverseAttendanceRecord::DEFAULT_REASON.
 */
const REVERSAL_REASON_PRESETS = [
    "Photo didn't match the person",
    'QR belongs to someone else',
    'Accidental scan',
] as const;

// The double-scan guard: how long a given decoded QR string is ignored
// after being accepted. Continuous scanning re-decodes the same frame
// several times a second while a badge is still held up to the camera —
// without this, one badge in view would fire the scan endpoint repeatedly.
const TOKEN_COOLDOWN_MS = 3000;

// The result panel (photo/name/status) now stays up until the officer
// taps its close button or the next badge is scanned — no auto-hide
// timer, so there's no risk of it disappearing before they've had a
// chance to read it.

const WINDOW_LABEL: Record<string, string> = {
    morning: 'Morning',
    afternoon: 'Afternoon',
    evening: 'Evening',
};

/** Window → icon + hue, matching the streak strip and the event schedule, so
 *  an officer glancing at three screens sees one vocabulary. */
const WINDOW_STYLE: Record<string, { Icon: LucideIcon; tone: Tone }> = {
    morning: { Icon: Sunrise, tone: 'amber' },
    afternoon: { Icon: Sun, tone: 'orange' },
    evening: { Icon: Moon, tone: 'violet' },
};

/**
 * The scanner's three outcomes, mapped onto the app's status palette. Same
 * hues the dashboard uses for Present / Late / Absent — an officer scanning
 * a gate and an admin reading a report are looking at the same colors for
 * the same facts.
 */
const OUTCOME_TONE: Record<'good' | 'warn' | 'bad', Tone> = {
    good: 'emerald',
    warn: 'amber',
    bad: 'red',
};

type Feedback = { kind: 'success'; result: ScanResult } | { kind: 'error'; message: string };

export function ScanPage() {
    const { sessions, isLoading } = useScannableSessions();
    const [selectedSessionId, setSelectedSessionId] = useState<number | null>(null);

    useEffect(() => {
        if (sessions.length === 1) {
            setSelectedSessionId(sessions[0].id);
            return;
        }
        if (selectedSessionId && !sessions.some((session) => session.id === selectedSessionId)) {
            // The session being scanned into just ended (or the periodic
            // refresh found it's no longer ongoing) — fall back to the
            // picker instead of silently posting scans into a dead session.
            setSelectedSessionId(null);
        }
    }, [sessions, selectedSessionId]);

    if (isLoading) {
        return (
            <div className="mx-auto max-w-md space-y-4 pt-8">
                <Heading level="h1">Scan</Heading>
                <div className="aspect-[3/4] w-full animate-pulse rounded-3xl bg-muted" />
                <Text variant="small" className="text-center">
                    Checking for an open session…
                </Text>
            </div>
        );
    }

    if (sessions.length === 0) {
        return (
            <div className="mx-auto max-w-md space-y-4 pt-8">
                <Heading level="h1">Scan</Heading>
                <Card>
                    <CardContent className="flex flex-col items-center gap-4 text-center">
                        <Tile tone="neutral" size="lg" variant="soft" Icon={ScanLine} />
                        <div>
                            <Text className="font-medium">No session is open for scanning</Text>
                            <Text variant="small">
                                Scanning opens automatically once a CSG Admin marks a session as ongoing.
                            </Text>
                        </div>
                    </CardContent>
                </Card>
            </div>
        );
    }

    if (!selectedSessionId) {
        return (
            <div className="mx-auto max-w-md space-y-4 pt-8">
                <Heading level="h1">Scan</Heading>
                <Text variant="small">Choose a session to begin scanning.</Text>

                {/* Non-dismissable: there's nothing to scan into until an
                    officer picks one, so no close button, and clicking the
                    overlay or pressing Escape shouldn't be able to leave
                    the screen with no session selected. */}
                <Dialog open>
                    <DialogContent
                        showCloseButton={false}
                        onInteractOutside={(event) => event.preventDefault()}
                        onEscapeKeyDown={(event) => event.preventDefault()}
                    >
                        <DialogHeader>
                            <DialogTitle>Multiple sessions are open</DialogTitle>
                            <DialogDescription>Choose which one to scan into.</DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2">
                            {sessions.map((session) => (
                                <SessionOption key={session.id} session={session} onSelect={() => setSelectedSessionId(session.id)} />
                            ))}
                        </div>
                    </DialogContent>
                </Dialog>
            </div>
        );
    }

    const session = sessions.find((candidate) => candidate.id === selectedSessionId);
    if (!session) return null;

    return (
        // key=session.id: remount on session change so the camera stream
        // and the scan-guard refs below reset cleanly instead of trying to
        // reconcile mid-flight.
        <Scanner
            key={session.id}
            session={session}
            showChangeSession={sessions.length > 1}
            onChangeSession={() => setSelectedSessionId(null)}
        />
    );
}

function Scanner({
    session,
    showChangeSession,
    onChangeSession,
}: {
    session: ScannableSession;
    showChangeSession: boolean;
    onChangeSession: () => void;
}) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const scanAttendance = useScanAttendance(session.id);

    // useMutation's identity is stable, but re-created on every render with
    // fresh closures — this ref lets the zxing decode callback (set up once
    // per session mount) always call the latest mutate function.
    const scanAttendanceRef = useRef(scanAttendance);
    scanAttendanceRef.current = scanAttendance;

    const isProcessingRef = useRef(false);
    const cooldownRef = useRef<Map<string, number>>(new Map());

    const [feedback, setFeedback] = useState<Feedback | null>(null);
    const [cameraError, setCameraError] = useState<string | null>(null);
    // Mirrors isProcessingRef into render state purely so the UI can show a
    // "Processing…" status. The ref is what the decode loop actually reads
    // (it needs a value it can check synchronously inside a callback that
    // was set up once at mount) — this state is just for display.
    const [isProcessing, setIsProcessing] = useState(false);

    // Stays on screen until dismissFeedback runs (manual close, or the next
    // successful/failed scan replacing it) — no timer. Genuinely stable
    // now: the "Recent scans" strip is populated from the server (see
    // RecentScansPanel), refreshed by useScanAttendance's own invalidation,
    // so this callback has nothing left to persist itself.
    const showFeedback = useCallback((next: Feedback) => {
        const tone = next.kind === 'success' ? resultTone(next.result) : 'bad';
        setFeedback(next);
        playFeedbackTone(tone);
        vibrateForTone(tone);
    }, []);

    const dismissFeedback = useCallback(() => setFeedback(null), []);

    // Browsers block audio until a user gesture unlocks the page — the
    // first scan is triggered by the camera, not a tap, so without this
    // the very first beep could be silently dropped. One-time listener
    // primes (creates/resumes) the shared AudioContext ahead of that.
    useEffect(() => {
        const unlock = () => getAudioContext();
        window.addEventListener('pointerdown', unlock, { once: true });
        return () => window.removeEventListener('pointerdown', unlock);
    }, []);

    useEffect(() => {
        let cancelled = false;
        let controls: IScannerControls | null = null;
        const codeReader = new BrowserQRCodeReader(undefined, { delayBetweenScanAttempts: 200 });

        function handleDecoded(token: string) {
            const now = Date.now();
            const lastSeen = cooldownRef.current.get(token);
            if (lastSeen && now - lastSeen < TOKEN_COOLDOWN_MS) return; // same badge still in frame
            if (isProcessingRef.current) return; // a previous scan is still in flight

            cooldownRef.current.set(token, now);
            isProcessingRef.current = true;
            setIsProcessing(true);

            scanAttendanceRef.current.mutate(token, {
                onSuccess: (result) => {
                    isProcessingRef.current = false;
                    setIsProcessing(false);
                    showFeedback({ kind: 'success', result });
                },
                onError: (error) => {
                    isProcessingRef.current = false;
                    setIsProcessing(false);
                    showFeedback({
                        kind: 'error',
                        message: error instanceof ScanError ? error.message : 'Scan failed — try again.',
                    });
                },
            });
        }

        async function start() {
            const video = videoRef.current;
            if (!video) return;

            try {
                if (!navigator.mediaDevices?.getUserMedia) {
                    throw new Error(
                        'Camera access requires a secure connection (HTTPS). Ask an admin to enable HTTPS for this site.',
                    );
                }
                // decodeFromVideoDevice(undefined, ...) requests the camera
                // with NO resolution constraints, so phones hand back their
                // native photo resolution (often 3000px+ on the long edge).
                // zxing re-decodes that full-size frame ~5x/sec, and on
                // mid-range Android hardware that alone is what stretches
                // "detect one QR" out to several seconds — it's CPU-bound
                // decode time, not the network (the network call only
                // happens once a code is already decoded, in handleDecoded
                // below). Capping the capture to a sane size keeps every
                // decode attempt fast without hurting read range at normal
                // scanning distance. continuous focus mode is requested
                // where supported so held-up badges snap into focus instead
                // of feeding zxing several blurry, undecodable frames first.
                const constraints: MediaStreamConstraints = {
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 960, max: 1280 },
                        height: { ideal: 960, max: 1280 },
                        advanced: [{ focusMode: 'continuous' } as MediaTrackConstraintSet],
                    },
                    audio: false,
                };
                const scannerControls = await codeReader.decodeFromConstraints(constraints, video, (result) => {
                    if (!result || cancelled) return;
                    handleDecoded(result.getText());
                });

                if (cancelled) {
                    scannerControls.stop();
                    return;
                }
                controls = scannerControls;
            } catch (error) {
                if (!cancelled) {
                    setCameraError(
                        error instanceof Error
                            ? error.message
                            : 'Could not access the camera. Check camera permissions and try again.',
                    );
                }
            }
        }

        start();

        return () => {
            cancelled = true;
            controls?.stop();
        };
        // showFeedback is stable (empty dep array itself); session identity
        // is what should restart the camera, and that's handled by the
        // key={session.id} remount in ScanPage rather than a dependency here.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [showFeedback]);

    const tone: 'good' | 'warn' | 'bad' | null =
        feedback?.kind === 'success' ? resultTone(feedback.result) : feedback?.kind === 'error' ? 'bad' : null;

    return (
        <div className="mx-auto flex max-w-md flex-col gap-4">
            <div className="flex items-start justify-between gap-4">
                <Heading level="h1">Scan</Heading>
                {showChangeSession && (
                    <Button variant="outline" size="sm" onClick={onChangeSession}>
                        Change session
                    </Button>
                )}
            </div>

            {/* What you're scanning into, as a card rather than a line of
                em-dash-joined text under the title. An officer picks this up
                at arm's length between badges; it has to survive a glance. */}
            <SessionContextCard session={session} />

            <div
                className={cn(
                    'relative aspect-[3/4] w-full overflow-hidden rounded-3xl bg-black ring-4 ring-transparent transition-all duration-300',
                    // The glow is the point: a lit ring in the outcome's own
                    // hue turns the whole viewfinder into the status light,
                    // readable from further away than any badge or icon.
                    tone === 'good' && 'ring-emerald-500 shadow-2xl shadow-emerald-500/40',
                    tone === 'warn' && 'ring-amber-500 shadow-2xl shadow-amber-500/40',
                    tone === 'bad' && 'ring-red-500 shadow-2xl shadow-red-500/40',
                )}
            >
                {/* The camera never stops for feedback — it keeps decoding
                    underneath the overlay, so the very next badge is
                    already being read while the previous result is shown. */}
                <video ref={videoRef} className="size-full object-cover" muted playsInline autoPlay />

                {!feedback && !cameraError && (
                    <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-4">
                        <div
                            className={cn(
                                'size-56 rounded-3xl border-2 transition-colors duration-200',
                                isProcessing ? 'border-amber-400/80' : 'border-white/60',
                            )}
                        />
                        {/* Status pill: this is the "what's happening" cue —
                            without it, an officer who scans a second badge
                            while the first is still being posted to the
                            server just sees nothing happen and assumes the
                            scanner missed it, so they try again (or worse,
                            think the first scan never went through). */}
                        <div
                            className={cn(
                                'flex items-center gap-2 rounded-full px-4 py-2 text-xs font-medium text-white transition-colors duration-200',
                                isProcessing ? 'bg-amber-500 shadow-lg shadow-amber-500/40' : 'bg-black/50 backdrop-blur-sm',
                            )}
                        >
                            {isProcessing ? (
                                <>
                                    <Loader2 className="size-3.5 animate-spin" />
                                    Processing scan…
                                </>
                            ) : (
                                'Ready to scan'
                            )}
                        </div>
                    </div>
                )}

                {cameraError && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/90 p-6 text-center">
                        <Tile tone="amber" size="lg" variant="solid" Icon={AlertTriangle} />
                        <Text className="text-white">{cameraError}</Text>
                    </div>
                )}

                {feedback && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-black/80 p-6 text-center backdrop-blur-sm">
                        <button
                            type="button"
                            onClick={dismissFeedback}
                            aria-label="Close"
                            className="absolute right-3 top-3 rounded-xl bg-white/10 p-2 text-white/80 transition-colors hover:bg-white/20 hover:text-white"
                        >
                            <X className="size-5" />
                        </button>
                        {feedback.kind === 'success' ? (
                            <SuccessPanel result={feedback.result} checkType={session.checkType} />
                        ) : (
                            <>
                                <Tile tone="red" size="lg" variant="solid" Icon={XCircle} />
                                <Text className="font-medium text-white">{feedback.message}</Text>
                            </>
                        )}
                    </div>
                )}
            </div>

            <Text variant="caption" className="text-center">
                {isProcessing
                    ? 'Recording the last scan — the next code will be read automatically once this finishes.'
                    : "Hold each student's QR code steady in the frame, then confirm the photo matches their face."}
            </Text>

            <RecentScansPanel sessionId={session.id} checkType={session.checkType} />
        </div>
    );
}

/**
 * The "Recent scans" strip plus the officer's escape hatch for a mis-scan:
 * tap the undo icon on a row, confirm, and the record goes back to pending
 * server-side (see ReverseAttendanceRecord). Deliberately reads from
 * useRecentScans (server) rather than anything local, since the whole
 * point is showing what the *server* currently has for this session —
 * including rows another officer's phone already reversed.
 */
function RecentScansPanel({ sessionId, checkType }: { sessionId: number; checkType: string }) {
    const { data: recent = [] } = useRecentScans(sessionId);
    const reverseScan = useReverseScan(sessionId);

    const [revertTarget, setRevertTarget] = useState<ScanResult | null>(null);
    const [revertReason, setRevertReason] = useState('');

    const closeDialog = useCallback(() => {
        setRevertTarget(null);
        setRevertReason('');
    }, []);

    const confirmRevert = useCallback(() => {
        if (!revertTarget) return;
        const target = revertTarget;
        const reason = revertReason.trim();

        reverseScan.mutate(
            { recordId: target.recordId, reason: reason !== '' ? reason : undefined },
            {
                onSuccess: () => {
                    toast.success(`${studentFullName(target.student)} reversed — back to pending.`);
                },
                onError: (error) => {
                    toast.error(error instanceof ScanError ? error.message : 'Could not reverse this scan.');
                },
            },
        );
        closeDialog();
    }, [revertTarget, revertReason, reverseScan, closeDialog]);

    if (recent.length === 0) return null;

    return (
        <>
            <div className="space-y-2">
                <Text variant="small" className="font-medium">
                    Recent scans
                </Text>
                <div className="space-y-2">
                    {recent.map((entry) => (
                        <div
                            key={entry.recordId}
                            className="flex items-center gap-4 rounded-2xl border bg-card p-2"
                        >
                            <UserAvatar student={entry.student} className="size-9" />
                            <div className="min-w-0 flex-1">
                                <Text className="truncate text-sm font-medium">{studentFullName(entry.student)}</Text>
                                <Text variant="caption">{studentMeta(entry.student)}</Text>
                            </div>
                            <div className="flex flex-col items-end gap-2">
                                <OutcomeBadge result={entry} checkType={checkType} />
                                {formatScanTime(entry.scannedAt) && (
                                    <Text variant="caption">{formatScanTime(entry.scannedAt)}</Text>
                                )}
                            </div>
                            {/* The officer's undo: the wrong face on the
                                photo, a badge scanned by mistake, and so
                                on. Same tier of permission as scanning
                                itself (see AttendanceSessionPolicy::
                                reverseScan) — no admin has to be flagged
                                down for this. */}
                            <button
                                type="button"
                                onClick={() => setRevertTarget(entry)}
                                aria-label={`Reverse scan for ${studentFullName(entry.student)}`}
                                className="shrink-0 rounded-xl p-2 text-muted-foreground transition-colors hover:bg-red-500/10 hover:text-red-600"
                            >
                                <Undo2 className="size-4" />
                            </button>
                        </div>
                    ))}
                </div>
            </div>

            <AlertDialog open={revertTarget !== null} onOpenChange={(open) => !open && closeDialog()}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Reverse this scan?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {revertTarget &&
                                `${studentFullName(revertTarget.student)} will go back to pending and can be scanned again — use this when the photo didn't match the person who presented the QR code.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>

                    <div className="space-y-2">
                        <Text variant="small" className="font-medium">
                            Reason (optional)
                        </Text>
                        <div className="flex flex-wrap gap-2">
                            {REVERSAL_REASON_PRESETS.map((preset) => (
                                <button
                                    key={preset}
                                    type="button"
                                    onClick={() => setRevertReason(preset)}
                                    className={cn(
                                        'rounded-full border px-4 py-2 text-caption font-medium transition-colors',
                                        revertReason === preset
                                            ? 'border-red-500 bg-red-500/10 text-red-700'
                                            : 'border-border text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    {preset}
                                </button>
                            ))}
                        </div>
                        <Input
                            value={revertReason}
                            onChange={(event) => setRevertReason(event.target.value)}
                            placeholder="Leave blank to use the default reason"
                            maxLength={500}
                        />
                    </div>

                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmRevert} className={buttonVariants({ variant: 'destructive' })}>
                            Yes, reverse
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

/**
 * One row in the "which session?" dialog. Given the same tile-and-chevron
 * shape as the account picker on the login screen, since it's the same
 * decision: one tap, pick a thing, get on with it.
 */
function SessionOption({ session, onSelect }: { session: ScannableSession; onSelect: () => void }) {
    const windowStyle = WINDOW_STYLE[session.windowType] ?? { Icon: Clock, tone: 'neutral' as Tone };

    return (
        <button
            type="button"
            onClick={onSelect}
            className="flex w-full items-center gap-4 rounded-2xl border p-4 text-left transition-all hover:shadow-md active:scale-[0.99]"
        >
            <Tile tone={windowStyle.tone} variant="solid" Icon={windowStyle.Icon} />
            <div className="min-w-0 flex-1">
                <Text className="truncate font-medium leading-tight">{session.eventName}</Text>
                <Text variant="caption" className="truncate">
                    Day {session.dayNumber} · {WINDOW_LABEL[session.windowType] ?? session.windowType} ·{' '}
                    {CHECK_TYPE_LABEL[session.checkType as keyof typeof CHECK_TYPE_LABEL] ?? session.checkType}
                </Text>
            </div>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
        </button>
    );
}

/** The standing "you are scanning into X" banner above the viewfinder. */
function SessionContextCard({ session }: { session: ScannableSession }) {
    const windowStyle = WINDOW_STYLE[session.windowType] ?? { Icon: Clock, tone: 'neutral' as Tone };

    return (
        <div className={cn('flex items-center gap-4 rounded-2xl border p-4', TONE.emerald.wash)}>
            <Tile tone={windowStyle.tone} variant="solid" Icon={windowStyle.Icon} />
            <div className="min-w-0 flex-1">
                <Text variant="small" className="truncate font-medium text-foreground">
                    {session.eventName}
                </Text>
                <Text variant="caption" className="truncate">
                    Day {session.dayNumber} · {WINDOW_LABEL[session.windowType] ?? session.windowType} ·{' '}
                    {CHECK_TYPE_LABEL[session.checkType as keyof typeof CHECK_TYPE_LABEL] ?? session.checkType}
                </Text>
            </div>
            <span className={cn('flex shrink-0 items-center gap-2 rounded-full px-2 py-2 text-caption font-medium', TONE.emerald.chip)}>
                <span className="relative flex size-1.5">
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-75" />
                    <span className="relative inline-flex size-1.5 rounded-full bg-emerald-500" />
                </span>
                Open
            </span>
        </div>
    );
}

function SuccessPanel({ result, checkType }: { result: ScanResult; checkType: string }) {
    const tone = resultTone(result);
    const checkLabel = CHECK_TYPE_LABEL[checkType as keyof typeof CHECK_TYPE_LABEL] ?? checkType;
    const ringClass = tone === 'good' ? 'ring-emerald-400/70' : 'ring-amber-400/70';

    return (
        <>
            {/*
              The photo is the whole point of this panel — the officer is
              comparing a face to a face. It gets a ring in the outcome's hue
              so the verdict is already legible while they're still looking at
              the photo, instead of needing a second glance down at a label.
            */}
            <UserAvatar
                student={result.student}
                className={cn('size-32 shadow-2xl ring-4', ringClass, tone === 'good' ? 'shadow-emerald-500/40' : 'shadow-amber-500/40')}
                fallbackClassName="text-3xl"
            />
            <div>
                <Text className="text-lg font-semibold text-white">{studentFullName(result.student)}</Text>
                <Text className="text-white/70">{studentMeta(result.student)}</Text>
            </div>
            <span
                className={cn(
                    'flex items-center gap-2 rounded-full px-4 py-2 text-small font-semibold text-white',
                    tone === 'good' ? 'bg-emerald-500 shadow-lg shadow-emerald-500/40' : 'bg-amber-500 shadow-lg shadow-amber-500/40',
                )}
            >
                {tone === 'good' ? <CheckCircle2 className="size-4" /> : <AlertTriangle className="size-4" />}
                {resultHeadline(result, checkLabel)}
            </span>
            {formatScanTime(result.scannedAt) && (
                <Text variant="caption" className="text-white/60">
                    Scanned at {formatScanTime(result.scannedAt)}
                </Text>
            )}
        </>
    );
}

function OutcomeBadge({ result, checkType }: { result: ScanResult; checkType: string }) {
    const tone = OUTCOME_TONE[resultTone(result)];
    const checkLabel = CHECK_TYPE_LABEL[checkType as keyof typeof CHECK_TYPE_LABEL] ?? checkType;

    return (
        <span className={cn('rounded-full px-2 py-2 text-caption font-medium whitespace-nowrap', TONE[tone].chip)}>
            {resultHeadline(result, checkLabel)}
        </span>
    );
}

function resultTone(result: ScanResult): 'good' | 'warn' {
    if (result.outcome === ScanOutcome.Duplicate) return 'warn';
    if (result.status === AttendanceStatus.Late) return 'warn';
    return 'good';
}

function resultHeadline(result: ScanResult, checkLabel: string): string {
    if (result.outcome === ScanOutcome.Duplicate) return 'Already scanned';
    return result.status === AttendanceStatus.Late ? `Late (${checkLabel.toLowerCase()})` : `Present (${checkLabel.toLowerCase()})`;
}

function studentFullName(student: ScannedStudent): string {
    const middleInitial = student.middleName ? ` ${student.middleName.charAt(0)}.` : '';
    const suffix = student.suffix ? ` ${student.suffix}` : '';
    return `${student.firstName}${middleInitial} ${student.lastName}${suffix}`;
}

function studentMeta(student: ScannedStudent): string {
    return [student.departmentCode, student.yearLevel ? `Year ${student.yearLevel}` : null, student.section]
        .filter((part): part is string => Boolean(part))
        .join(' • ');
}

/** The wall-clock time the officer's device recorded the scan at, e.g. "10:32:05 AM". */
function formatScanTime(scannedAt: string | null): string | null {
    if (!scannedAt) return null;
    const date = new Date(scannedAt);
    if (Number.isNaN(date.getTime())) return null;
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
}

let sharedAudioContext: AudioContext | null = null;

function getAudioContext(): AudioContext | null {
    if (typeof window === 'undefined' || !window.AudioContext) return null;
    if (!sharedAudioContext) sharedAudioContext = new window.AudioContext();
    if (sharedAudioContext.state === 'suspended') void sharedAudioContext.resume();
    return sharedAudioContext;
}

/** Short synthesized beep(s) — no audio asset to bundle, and the pattern
 *  (one high note vs. two lower notes) doubles as an eyes-free cue. */
function playFeedbackTone(tone: 'good' | 'warn' | 'bad') {
    const ctx = getAudioContext();
    if (!ctx) return;

    const notes = tone === 'good' ? [880] : tone === 'warn' ? [660, 440] : [220, 165];
    let start = ctx.currentTime;

    for (const frequency of notes) {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.2, start + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.13);
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start(start);
        oscillator.stop(start + 0.14);
        start += 0.12;
    }
}

function vibrateForTone(tone: 'good' | 'warn' | 'bad') {
    if (typeof navigator === 'undefined' || !navigator.vibrate) return;
    navigator.vibrate(tone === 'good' ? 40 : tone === 'warn' ? [40, 60, 40] : [80, 60, 80]);
}
