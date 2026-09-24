import { useState } from 'react';
import { CalendarCheck, HelpCircle, LogOut, UserRound } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

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
import { buttonVariants } from '@/components/ui/button';
import { useAuthStore } from '@/application/auth/auth.store';
import { useMyPenaltyHistory } from '@/application/penalties/use-my-penalty-history';
import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';
import { Role, ROLE_LABEL } from '@/domain/enums';
import { cn, formatCurrency } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { SettingsRow } from '@/presentation/components/settings-row';
import { UserAvatar } from '@/presentation/components/user-avatar';
import { PoweredByLogos } from '@/presentation/components/powered-by-logos';
import { TONE } from '@/presentation/components/tone';

export function AccountPage() {
    const student = useAuthStore((state) => state.student);
    const clear = useAuthStore((state) => state.clear);
    const navigate = useNavigate();
    const [isLogoutDialogOpen, setIsLogoutDialogOpen] = useState(false);

    // Only students carry a penalty balance (see BuildDashboardSummary::
    // buildStudentSummary) — enabled here so admins/officers never fire this
    // request, since the "Attendance & Penalties" row's trailing balance
    // (which they don't have) never waits on a fetch that would 404/empty
    // anyway.
    const isStudent = student?.role === Role.Student;
    const { data: penaltyHistory } = useMyPenaltyHistory({ enabled: isStudent });

    if (!student) return null;

    async function handleLogout() {
        try {
            await httpAuthRepository.logout();
        } finally {
            clear();
            navigate('/login', { replace: true });
        }
    }

    const owes = (penaltyHistory?.total ?? 0) > 0;

    return (
        <div className="mx-auto max-w-md space-y-8">
            <Heading level="h1">Account</Heading>

            {/*
              The identity block was a bare avatar on the page background.
              It's a card now, with the same violet pool behind it that the
              login screen and the QR card use — the three places in the app
              that are about *who you are* rather than what's happening.
            */}
            <div className="relative overflow-hidden rounded-3xl border bg-card p-6">
                <span
                    aria-hidden
                    className="pointer-events-none absolute -top-20 left-1/2 size-64 -translate-x-1/2 rounded-full bg-violet-500 opacity-[0.07] blur-3xl dark:opacity-[0.12]"
                />
                <div className="relative flex flex-col items-center gap-4 text-center">
                    <UserAvatar
                        student={student}
                        className="size-20 shadow-lg shadow-violet-500/20 ring-4 ring-violet-500/10"
                        fallbackClassName="text-h2"
                    />
                    <div>
                        <Heading level="h3" as="p">
                            {student.firstName} {student.lastName}
                        </Heading>
                        <Text variant="caption" className="font-mono tracking-wide">
                            {student.studentNumber}
                        </Text>
                    </div>
                    <span className={cn('rounded-full px-4 py-2 text-caption font-medium', TONE.violet.chip)}>{ROLE_LABEL[student.role]}</span>
                </div>
            </div>

            {/* Each row keeps its own hue so the list is scannable by color;
                the balance is red only when something is actually owed. */}
            <div className="space-y-2">
                <SettingsRow to="/account/personal-information" tone="violet" icon={<UserRound />}>
                    Personal information
                </SettingsRow>
                <SettingsRow
                    to="/account/attendance-history"
                    tone={isStudent && owes ? 'red' : 'emerald'}
                    icon={<CalendarCheck />}
                    trailing={isStudent ? formatCurrency(penaltyHistory?.total ?? 0) : undefined}
                >
                    Attendance & Penalties
                </SettingsRow>
                <SettingsRow to="/account/faq" tone="sky" icon={<HelpCircle />}>
                    FAQ
                </SettingsRow>
            </div>

            <SettingsRow onClick={() => setIsLogoutDialogOpen(true)} icon={<LogOut />} destructive>
                Log out
            </SettingsRow>

            <PoweredByLogos />

            <AlertDialog open={isLogoutDialogOpen} onOpenChange={setIsLogoutDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Log out?</AlertDialogTitle>
                        <AlertDialogDescription>
                            You'll need to sign in again to access your account.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleLogout} className={cn(buttonVariants({ variant: 'destructive' }))}>
                            Yes, log out
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
