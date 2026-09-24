import { AnimatePresence, motion } from 'framer-motion';
import { useNavigate } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { Camera, RefreshCw, ShieldCheck, WifiOff } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/application/auth/auth.store';
import { useStudentQr } from '@/application/students/use-student-qr';
import { needsPhotoUpload } from '@/domain/student-photo';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import { PremiumIdCard } from '@/presentation/components/lanyard/PremiumIdCard';
import { cn } from '@/lib/utils';

const REVEAL_TRANSITION = { type: 'spring', stiffness: 260, damping: 26, mass: 0.9 } as const;

export function QrPage() {
    const student = useAuthStore((state) => state.student);
    const navigate = useNavigate();

    if (!student) return null;

    // Covers every way onto this screen — the bottom nav already stops a
    // tap from getting here (see app-bottom-nav.tsx), but a typed URL, an
    // old bookmark, or the browser's own back/forward all skip that, so
    // the gate has to live here too rather than only upstream of it.
    const locked = needsPhotoUpload(student);

    // Called unconditionally, same as `student` above — only `enabled`
    // changes, never whether the hook itself runs, so this stays a valid
    // hook call on every render regardless of lock state.
    const { qrUrl, isLoading, isError, error, refetch } = useStudentQr(student.id, { enabled: !locked });

    // The API's own EnsurePhotoHasBeenUploaded middleware (routes/api.php)
    // enforces this same rule independently, returning 423 — the same
    // never-trust-the-client-alone treatment as must_change_password. The
    // `locked` check above is what a student actually sees in normal use
    // (it stops the request before it's even made); this is the fallback
    // for whenever the two disagree, e.g. stale cached auth state after a
    // photo was cleared elsewhere, so the screen still explains itself
    // correctly instead of showing a generic "didn't load" error.
    const serverLocked = isAxiosError(error) && error.response?.status === 423;

    if (locked || serverLocked) {
        return <PhotoRequiredNotice onCompleteProfile={() => navigate('/account/personal-information')} />;
    }

    const showCard = Boolean(qrUrl) && !isLoading && !isError;
    const showError = isError && !isLoading;
    const course = student.departmentCode ?? student.departmentName ?? null;

    return (
        <motion.div
            initial={{ opacity: 0, y: 28, scale: 0.92 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            transition={REVEAL_TRANSITION}
            // `w-full` + a min-height (instead of a hard 100dvh) keeps this
            // fluid inside whatever container it's placed in, on any screen
            // size — sizing against the whole viewport made the card blow
            // past AppLayout's max-w-5xl on desktop and render off-centre.
            className="relative flex min-h-[70svh] w-full flex-col items-center justify-center px-2 py-6"
        >
            {/*
              The card's violet drop shadow does the close-range lighting;
              this pool does the room. Together they're why a white card on
              a white page still reads as an object sitting above the
              surface. Hidden while erroring — nothing to light.
            */}
            {!showError && (
                <span
                    aria-hidden
                    className="pointer-events-none absolute top-1/2 left-1/2 size-[24rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-violet-500 opacity-[0.08] blur-3xl dark:opacity-[0.14]"
                />
            )}

            {showCard && qrUrl && (
                <PremiumIdCard
                    firstName={student.firstName}
                    lastName={student.lastName}
                    studentNumber={student.studentNumber}
                    section={student.section}
                    qrUrl={qrUrl}
                    photoUrl={student.photoUrl}
                    course={course}
                />
            )}

            <AnimatePresence>
                {!showCard && (
                    <motion.div
                        key={showError ? 'error' : 'loading'}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="relative flex w-full flex-col items-center justify-center"
                    >
                        {showError ? (
                            <div
                                className={cn(
                                    'flex w-[min(92vw,24rem)] flex-col items-center gap-4 rounded-3xl border p-8 text-center',
                                    TONE.red.wash,
                                )}
                            >
                                <Tile tone="red" size="lg" variant="solid" Icon={WifiOff} />
                                <div>
                                    <Text className="font-medium">Your QR code didn't load</Text>
                                    <Text variant="small">You'll need a connection to fetch it. Your code itself hasn't changed.</Text>
                                </div>
                                <Button size="sm" variant="outline" onClick={() => refetch()}>
                                    <RefreshCw className="size-4" />
                                    Try again
                                </Button>
                            </div>
                        ) : (
                            /*
                              A skeleton in the card's own shape rather than a
                              pulsing icon. The card is the entire screen here,
                              so a small throbbing glyph in the middle of an
                              empty page reads as "broken" for the second or
                              two before it lands; an outline of the thing
                              that's coming reads as "loading".
                            */
                            <div className="flex w-[min(92vw,24rem)] animate-pulse flex-col items-center gap-6 rounded-[clamp(1.5rem,5vw,2rem)] border border-border bg-card px-6 py-8">
                                <div className="size-16 rounded-full bg-muted" />
                                <div className="h-4 w-40 rounded-full bg-muted" />
                                <div className="h-3 w-28 rounded-full bg-muted" />
                                <div className="aspect-square w-full rounded-3xl bg-muted" />
                            </div>
                        )}
                    </motion.div>
                )}
            </AnimatePresence>
        </motion.div>
    );
}

/**
 * What a student sees at /profile instead of their QR while no photo is
 * on file — whether they landed here by typing the URL, an old bookmark,
 * or the browser's back/forward, since none of those go through the
 * bottom nav's own block. Deliberately explains *why* (officers verify
 * against this photo) rather than just "locked", and the one thing to do
 * about it, since a bare "access denied" here would just leave a student
 * confused at the gate later instead of fixing it now.
 */
function PhotoRequiredNotice({ onCompleteProfile }: { onCompleteProfile: () => void }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={REVEAL_TRANSITION}
            className="relative flex min-h-[70svh] w-full flex-col items-center justify-center px-2 py-6"
        >
            <div
                className={cn(
                    'flex w-[min(92vw,24rem)] flex-col items-center gap-4 rounded-3xl border p-8 text-center',
                    TONE.amber.wash,
                )}
            >
                <Tile tone="amber" size="lg" variant="solid" Icon={Camera} />
                <div>
                    <Text className="font-medium">Upload your photo to see My QR</Text>
                    <Text variant="small">
                        Officers verify you in person by matching your face to this photo before scanning you in,
                        so your QR stays hidden until it's on file. Add it now — you won't be able to once an
                        attendance session has started.
                    </Text>
                </div>
                <Button size="sm" onClick={onCompleteProfile}>
                    <ShieldCheck className="size-4" />
                    Complete profile
                </Button>
            </div>
        </motion.div>
    );
}
