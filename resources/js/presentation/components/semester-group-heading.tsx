import { SEMESTER_LABEL, type Semester as SemesterTerm } from '@/domain/enums';
import { cn } from '@/lib/utils';
import { Text } from '@/presentation/components/typography';

interface SemesterGroupHeadingProps {
    semesterName: string | null;
    academicYearName: string | null;
    isCurrent: boolean;
    /** Subtotal line for the whole semester (e.g. "12 records · 9 present"). */
    summary: string;
    className?: string;
}

/**
 * Heading shown above each semester's rows when a ledger lists "All
 * semesters" — a semester's rows are never blended into the next one's, and
 * the subtotal covers every matching row, not just the current page.
 */
export function SemesterGroupHeading({
    semesterName,
    academicYearName,
    isCurrent,
    summary,
    className,
}: SemesterGroupHeadingProps) {
    const label = semesterName ? (SEMESTER_LABEL[semesterName as SemesterTerm] ?? semesterName) : 'No semester';

    return (
        <div className={cn('flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b pt-4 pb-2', className)}>
            <Text variant="small" className="font-semibold text-foreground">
                {academicYearName ? `${academicYearName} — ` : ''}
                {label}
                {isCurrent ? ' (current)' : ''}
            </Text>
            <Text variant="caption">{summary}</Text>
        </div>
    );
}
