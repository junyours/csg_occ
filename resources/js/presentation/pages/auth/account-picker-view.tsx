import { useState } from 'react';
import { ChevronRight, Settings, UserPlus, X } from 'lucide-react';

import { Text } from '@/presentation/components/typography';
import { UserAvatar } from '@/presentation/components/user-avatar';
import { TONE } from '@/presentation/components/tone';
import type { RememberedAccount } from '@/application/auth/remembered-accounts.store';
import { ROLE_LABEL } from '@/domain/enums';
import { cn } from '@/lib/utils';
import { CHIP } from '@/presentation/components/spacing';

interface AccountPickerRowProps {
    account: RememberedAccount;
    managing: boolean;
    onSelect: (account: RememberedAccount) => void;
    onForget: (studentNumber: string) => void;
}

/**
 * Each account is its own card now rather than a row in one bordered list
 * with separators between. On a phone the separated list read as a table;
 * discrete cards read as "pick one of these", which is the actual job.
 *
 * The avatar carries a soft violet ring so it matches the tile shapes used
 * everywhere else — a circle inside a rounded square is the one place the
 * dashboard's squircle doesn't apply, since a face is a face.
 */
function AccountPickerRow({ account, managing, onSelect, onForget }: AccountPickerRowProps) {
    const body = (
        <>
            <UserAvatar student={account} className="size-11 ring-2 ring-violet-500/15" />
            <div className="min-w-0 flex-1 text-left">
                <Text className="truncate font-medium leading-tight">
                    {account.firstName} {account.lastName}
                </Text>
                <span className={cn('mt-2 inline-flex', CHIP, TONE.violet.chip)}>
                    {ROLE_LABEL[account.role]}
                </span>
            </div>
        </>
    );

    if (managing) {
        return (
            <div className="flex w-full items-center gap-4 rounded-2xl border border-border bg-card p-4">
                {body}
                <button
                    type="button"
                    onClick={() => onForget(account.studentNumber)}
                    aria-label={`Remove ${account.firstName} ${account.lastName} from saved accounts`}
                    className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-red-500/10 text-red-600 transition-colors hover:bg-red-500 hover:text-white dark:text-red-400 dark:hover:text-white"
                >
                    <X className="size-4" />
                </button>
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={() => onSelect(account)}
            className="flex w-full items-center gap-4 rounded-2xl border border-border bg-card p-4 transition-all hover:border-violet-500/30 hover:shadow-md hover:shadow-violet-500/10 active:scale-[0.99]"
        >
            {body}
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
        </button>
    );
}

interface AccountPickerViewProps {
    accounts: RememberedAccount[];
    onSelect: (account: RememberedAccount) => void;
    onForget: (studentNumber: string) => void;
    onUseDifferentAccount: () => void;
}

export function AccountPickerView({ accounts, onSelect, onForget, onUseDifferentAccount }: AccountPickerViewProps) {
    const managing = useManagingAccounts();

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-2">
                <Text variant="small">
                    {managing.isManaging
                        ? 'Tap the cross to remove an account.'
                        : `${accounts.length} saved ${accounts.length === 1 ? 'account' : 'accounts'}`}
                </Text>
                <button
                    type="button"
                    onClick={managing.toggle}
                    aria-label={managing.isManaging ? 'Done managing accounts' : 'Manage saved accounts'}
                    aria-pressed={managing.isManaging}
                    className={cn(
                        'flex h-8 shrink-0 items-center justify-center rounded-xl px-2 text-small font-medium transition-colors',
                        managing.isManaging ? TONE.violet.solid : 'bg-muted text-muted-foreground hover:text-foreground',
                    )}
                >
                    {managing.isManaging ? 'Done' : <Settings className="size-4" />}
                </button>
            </div>

            <div className="space-y-2">
                {accounts.map((account: RememberedAccount) => (
                    <AccountPickerRow
                        key={account.studentNumber}
                        account={account}
                        managing={managing.isManaging}
                        onSelect={onSelect}
                        onForget={onForget}
                    />
                ))}
            </div>

            {/* Dashed rather than solid: this is the "none of the above" door,
                and an outline button next to filled account cards was pulling
                more attention than the accounts themselves. */}
            <button
                type="button"
                onClick={onUseDifferentAccount}
                className="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-border p-4 text-small font-medium text-muted-foreground transition-colors hover:border-violet-500/40 hover:text-foreground"
            >
                <UserPlus className="size-4" />
                Use a different account
            </button>

            <Text variant="caption" className="text-center">
                Restricted to authorized personnel only.
            </Text>
        </div>
    );
}

// Kept local to this view — whether the picker is in "manage" mode isn't
// meaningful to anything outside it, so it doesn't belong in LoginPage's
// state (that was part of what pushed LoginPage's own complexity too high).
function useManagingAccounts() {
    const [isManaging, setIsManaging] = useState(false);
    return { isManaging, toggle: () => setIsManaging((prev) => !prev) };
}
