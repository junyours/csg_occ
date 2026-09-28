import { useQueries } from '@tanstack/react-query';

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useAcademicYears } from '@/application/academic-years/use-academic-years';
import { semestersQueryKey } from '@/application/semesters/use-semesters';
import { httpSemestersRepository } from '@/infrastructure/semesters/semesters.repository.http';
import { SEMESTER_LABEL, type Semester as SemesterTerm } from '@/domain/enums';

/** `'current'` = the active semester (server default), a number = that semester, `'all'` = every semester. */
export type SemesterSelection = 'current' | 'all' | number;

interface SemesterPickerProps {
    value: SemesterSelection;
    onChange: (value: SemesterSelection) => void;
    /** Adds an "All semesters" entry (only for views that group by semester). */
    allowAll?: boolean;
    className?: string;
}

/**
 * Current semester (default) / a specific past semester / All. Lists every
 * semester of every academic year, newest year first.
 */
export function SemesterPicker({ value, onChange, allowAll = false, className }: SemesterPickerProps) {
    const { data: academicYears } = useAcademicYears();

    const results = useQueries({
        queries: (academicYears ?? []).map((year) => ({
            queryKey: semestersQueryKey(year.id),
            queryFn: () => httpSemestersRepository.list(year.id),
        })),
    });

    const options = (academicYears ?? []).flatMap((year, index) =>
        (results[index]?.data ?? []).map((semester) => ({
            id: semester.id,
            label: `${year.name} — ${SEMESTER_LABEL[semester.name as SemesterTerm] ?? semester.name}${semester.isActive ? ' (current)' : ''}`,
        })),
    );

    return (
        <Select
            value={String(value)}
            onValueChange={(next) => onChange(next === 'current' || next === 'all' ? next : Number(next))}
        >
            <SelectTrigger className={className ?? 'w-64'}>
                <SelectValue placeholder="Semester" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="current">Current semester</SelectItem>
                {allowAll && <SelectItem value="all">All semesters</SelectItem>}
                {options.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
