import { KeyRound, Lock, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useNow } from '@/application/shared/use-now';
import {
    useClearPasswordResetCodes,
    usePasswordResetCodes,
} from '@/application/password-reset/use-password-reset-codes';
import type { PasswordResetCodeEntry } from '@/infrastructure/password-reset/password-reset.repository.http';
import { ROLE_LABEL, Role } from '@/domain/enums';
import {
    PasswordResetCodeStatus,
    effectiveStatus,
    formatCodeForDisplay,
    formatCountdown,
    secondsRemaining,
} from '@/domain/password-reset';
import { cn } from '@/lib/utils';
import { EmptyState, ListSkeleton } from '@/presentation/components/empty-state';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';
import { Heading, Text } from '@/presentation/components/typography';
import { UserAvatar } from '@/presentation/components/user-avatar';

const STATUS_LABEL: Record<PasswordResetCodeStatus, string> = {
    [PasswordResetCodeStatus.Pending]: 'Active',
    [PasswordResetCodeStatus.Locked]: 'Locked',
    [PasswordResetCodeStatus.Used]: 'Used',
    [PasswordResetCodeStatus.Expired]: 'Expired',
};

const STATUS_TONE: Record<PasswordResetCodeStatus, Tone> = {
    [PasswordResetCodeStatus.Pending]: 'emerald',
    [PasswordResetCodeStatus.Locked]: 'red',
    [PasswordResetCodeStatus.Used]: 'neutral',
    [PasswordResetCodeStatus.Expired]: 'neutral',
};

export function PasswordResetCodesPage() {
    const { data, isLoading, isError, isFetching, refetch, dataUpdatedAt } = usePasswordResetCodes();
    const clearCodes = useClearPasswordResetCodes();
    // Ticks every second so a countdown reaches zero — and the code hides —
    // without asking the server anything. This is a local clock only.
    const now = useNow(1000);

    // "Server now" = this device's clock corrected by how far off it was
    // when the list arrived, so 5 minutes here means 5 minutes there.
    const serverNow = now + (data?.clockSkewMs ?? 0);

    // Anything the "Clear" button could remove: spent or run-out codes.
    const hasClearable =
        data?.entries.some((entry) => {
            const status = effectiveStatus(entry.status, Date.parse(entry.expiresAt), serverNow);
            return status === PasswordResetCodeStatus.Used || status === PasswordResetCodeStatus.Expired;
        }) ?? false;

    function handleClear() {
        clearCodes.mutate(undefined, {
            onSuccess: ({ deleted }) => {
                toast.success(deleted === 0 ? 'Nothing to clear.' : `Cleared ${deleted} used or expired ${deleted === 1 ? 'code' : 'codes'}.`);
            },
            onError: () => toast.error('Could not clear the codes. Please try again.'),
        });
    }

    return (
        <div className="mx-auto max-w-3xl space-y-8">
            <div className="space-y-4">
                <div>
                    <Heading level="h1">Password Reset Codes</Heading>
                    <Text variant="small">
                        Students who forgot their password ask you for the code shown here.
                    </Text>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant="outline" onClick={() => refetch()} disabled={isFetching || clearCodes.isPending}>
                        <RefreshCw className={cn(isFetching && 'animate-spin')} />
                        Refresh
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={handleClear}
                        disabled={!hasClearable || clearCodes.isPending || isFetching}
                    >
                        <Trash2 />
                        {clearCodes.isPending ? 'Clearing…' : 'Clear used & expired'}
                    </Button>
                    {data && (
                        <Text variant="caption" className="ml-auto">
                            Updated {new Date(dataUpdatedAt).toLocaleTimeString()}
                        </Text>
                    )}
                </div>
            </div>

            <div className={cn('flex items-start gap-4 rounded-2xl border p-4', TONE.amber.wash)}>
                <Tile tone="amber" size="sm" variant="solid" Icon={ShieldCheck} />
                <div className="min-w-0 space-y-1">
                    <Text variant="small" className="font-medium text-foreground">
                        Check their ID before you share a code
                    </Text>
                    <Text variant="caption">
                        Anyone can ask for a code using a Student ID Number, so only give it to the student in
                        person. Each code works once and expires after 5 minutes. This list does not update by
                        itself — press Refresh to see new requests, and Clear used &amp; expired to remove old ones.
                    </Text>
                </div>
            </div>

            {isLoading && <ListSkeleton rows={3} />}

            {isError && !data && (
                <EmptyState
                    Icon={KeyRound}
                    tone="red"
                    title="Couldn't load the codes"
                    description="Check your connection and try again."
                    action={
                        <Button size="sm" variant="outline" onClick={() => refetch()}>
                            Try again
                        </Button>
                    }
                />
            )}

            {data && data.entries.length === 0 && (
                <EmptyState
                    Icon={KeyRound}
                    title="No reset codes right now"
                    description="When a student asks for one, press Refresh to see it here."
                />
            )}

            <div className="space-y-4">
                {data?.entries.map((entry) => (
                    <CodeCard key={entry.id} entry={entry} serverNow={serverNow} />
                ))}
            </div>
        </div>
    );
}

function CodeCard({ entry, serverNow }: { entry: PasswordResetCodeEntry; serverNow: number }) {
    const expiresAt = Date.parse(entry.expiresAt);
    const status = effectiveStatus(entry.status, expiresAt, serverNow);
    const isActive = status === PasswordResetCodeStatus.Pending && entry.code !== null;
    const secondsLeft = secondsRemaining(expiresAt, serverNow);

    return (
        <Card className={cn('overflow-hidden', !isActive && 'opacity-80')}>
            <CardContent className="space-y-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex min-w-0 items-center gap-4">
                        <UserAvatar student={entry} size="lg" />
                        <div className="min-w-0">
                            <Text className="truncate font-medium">
                                {entry.lastName}, {entry.firstName}
                            </Text>
                            <Text variant="small" className="truncate">
                                {entry.studentNumber}
                                {entry.departmentCode ? ` · ${entry.departmentCode}` : ''}
                                {entry.role !== Role.Student ? ` · ${ROLE_LABEL[entry.role]}` : ''}
                            </Text>
                        </div>
                    </div>
                    <Badge variant="secondary" className={cn('shrink-0 border-transparent', TONE[STATUS_TONE[status]].chip)}>
                        {status === PasswordResetCodeStatus.Locked && <Lock />}
                        {STATUS_LABEL[status]}
                    </Badge>
                </div>

                {isActive && entry.code !== null && (
                    <div className={cn('flex items-center justify-between gap-4 rounded-2xl border p-4', TONE.emerald.wash)}>
                        <span
                            className={cn('font-mono text-h1 font-semibold tracking-[0.2em] tabular-nums', TONE.emerald.text)}
                            aria-label={`Code ${entry.code.split('').join(' ')}`}
                        >
                            {formatCodeForDisplay(entry.code)}
                        </span>
                        <Text variant="small" className="shrink-0 tabular-nums">
                            Expires in {formatCountdown(secondsLeft)}
                        </Text>
                    </div>
                )}

                {status === PasswordResetCodeStatus.Locked && (
                    <Text variant="caption">
                        Locked after too many wrong tries — the student has to request a new code.
                    </Text>
                )}
                {status === PasswordResetCodeStatus.Used && (
                    <Text variant="caption">The student entered this code and is setting a new password.</Text>
                )}
                {status === PasswordResetCodeStatus.Expired && (
                    <Text variant="caption">This code ran out. Use Clear used &amp; expired to remove it.</Text>
                )}
            </CardContent>
        </Card>
    );
}
