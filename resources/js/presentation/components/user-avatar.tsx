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
    const avatar = (
        <Avatar size={size} className={cn(className)}>
            {student.photoUrl ? <AvatarImage src={student.photoUrl} alt={`${student.firstName} ${student.lastName}`} /> : null}
            <AvatarFallback className={cn(fallbackClassName)}>{initials(student)}</AvatarFallback>
        </Avatar>
    );

    if (!children) return avatar;

    // The Avatar circle is overflow-hidden (that's what rounds the photo), so
    // anything placed *inside* it — a status badge on the corner — gets sliced
    // by the circle. The overlay goes in a wrapper beside the circle instead,
    // sitting on top of it and free to overhang the edge. The wrapper carries
    // the same group/data-size hooks AvatarBadge's size classes key off.
    return (
        <span data-size={size ?? 'default'} className="group/avatar relative inline-flex shrink-0">
            {avatar}
            {children}
        </span>
    );
}
