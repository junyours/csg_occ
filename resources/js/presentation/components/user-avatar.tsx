import type { ReactNode } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { Student } from '@/domain/entities';
import { cn } from '@/lib/utils';

function initials(student: Pick<Student, 'firstName' | 'lastName'>) {
    return `${student.firstName.charAt(0)}${student.lastName.charAt(0)}`.toUpperCase();
}

interface UserAvatarProps {
    student: Pick<Student, 'firstName' | 'lastName' | 'photoUrl'>;
    /** Forwarded to the underlying Avatar primitive — 'sm' | 'default' | 'lg'. */
    size?: 'sm' | 'default' | 'lg';
    className?: string;
    fallbackClassName?: string;
    /** e.g. an AvatarBadge overlay for status (reversed, online, etc). */
    children?: ReactNode;
}

export function UserAvatar({ student, size, className, fallbackClassName, children }: UserAvatarProps) {
    return (
        <Avatar size={size} className={cn(className)}>
            {student.photoUrl ? <AvatarImage src={student.photoUrl} alt={`${student.firstName} ${student.lastName}`} /> : null}
            <AvatarFallback className={cn(fallbackClassName)}>{initials(student)}</AvatarFallback>
            {children}
        </Avatar>
    );
}
