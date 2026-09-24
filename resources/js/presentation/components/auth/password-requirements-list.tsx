import { Check, CheckCircle2, X } from 'lucide-react';

import { Progress } from '@/components/ui/progress';
import {
    evaluatePasswordPolicy,
    passwordStrengthBarClass,
    passwordStrengthLabel,
} from '@/domain/password-policy';
import { cn } from '@/lib/utils';

interface PasswordRequirementsListProps {
    password: string;
    className?: string;
}

/**
 * Live-updating (re-evaluates on every keystroke, no debounce needed —
 * these are cheap regex tests) checklist + strength meter for the new
 * password field on the change-password screen. Once every requirement
 * is met, the itemized list (five already-green lines) is just noise —
 * it collapses to a single confirmation line instead.
 */
export function PasswordRequirementsList({ password, className }: PasswordRequirementsListProps) {
    const { results, metCount, total, score, isValid } = evaluatePasswordPolicy(password);
    const label = password.length > 0 ? passwordStrengthLabel(metCount, total) : null;

    return (
        <div className={cn('space-y-4 rounded-md border border-border bg-muted/30 p-4', className)}>
            <div className="space-y-2">
                <div className="flex items-center justify-between text-xs">
                    <span className="font-medium text-muted-foreground">Password strength</span>
                    {label ? <span className="font-medium text-foreground">{label}</span> : null}
                </div>
                <Progress
                    value={score}
                    aria-label="Password strength"
                    indicatorClassName={cn('transition-colors duration-300', passwordStrengthBarClass(metCount, total))}
                />
            </div>

            {isValid ? (
                <p className="flex items-center gap-2 text-xs font-medium text-emerald-600">
                    <CheckCircle2 className="size-3.5 shrink-0" aria-hidden />
                    All requirements met
                </p>
            ) : (
                <ul className="space-y-2">
                    {results.map((requirement) => (
                        <li
                            key={requirement.id}
                            className={cn(
                                'flex items-center gap-2 text-xs transition-colors',
                                requirement.met ? 'text-emerald-600' : 'text-muted-foreground',
                            )}
                        >
                            {requirement.met ? (
                                <Check className="size-3.5 shrink-0" aria-hidden />
                            ) : (
                                <X className="size-3.5 shrink-0" aria-hidden />
                            )}
                            <span>{requirement.label}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
