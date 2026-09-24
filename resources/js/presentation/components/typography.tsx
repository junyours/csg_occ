import type { ComponentPropsWithoutRef, ElementType } from 'react';
import { cn } from '@/lib/utils';

/**
 * Type hierarchy for the whole app. Every screen should reach for one of
 * these instead of an arbitrary text-* size, so the same "page title" or
 * "field label" looks the same on the login screen, the roster report,
 * and the scanner view.
 *
 *   display  — one per app, the auth screens' wordmark moment
 *   h1       — page title (e.g. "Students", "Intramurals 2026")
 *   h2       — section header inside a page (e.g. "BSIT — 1A")
 *   h3       — card/table group header
 *   body     — default paragraph and form text
 *   small    — secondary/meta text (timestamps, helper text)
 *   caption  — the smallest label (table column headers, badges)
 */

type HeadingLevel = 'display' | 'h1' | 'h2' | 'h3';

const headingStyles: Record<HeadingLevel, string> = {
    display: 'text-display font-semibold tracking-tight text-foreground',
    h1: 'text-h1 font-semibold tracking-tight text-foreground',
    h2: 'text-h2 font-semibold text-foreground',
    h3: 'text-h3 font-medium text-foreground',
};

const defaultTag: Record<HeadingLevel, ElementType> = {
    display: 'h1',
    h1: 'h1',
    h2: 'h2',
    h3: 'h3',
};

interface HeadingProps extends ComponentPropsWithoutRef<'h1'> {
    level: HeadingLevel;
    as?: ElementType;
}

export function Heading({ level, as, className, ...props }: HeadingProps) {
    const Tag = (as ?? defaultTag[level]) as any;
    return <Tag className={cn(headingStyles[level], className)} {...props} />;
}

type TextVariant = 'body' | 'small' | 'caption';

const textStyles: Record<TextVariant, string> = {
    body: 'text-body text-foreground',
    small: 'text-small text-muted-foreground',
    caption: 'text-caption text-muted-foreground',
};

interface TextProps extends ComponentPropsWithoutRef<'p'> {
    variant?: TextVariant;
    as?: ElementType;
}

export function Text({ variant = 'body', as = 'p', className, ...props }: TextProps) {
    const Tag = as as any;
    return <Tag className={cn(textStyles[variant], className)} {...props} />;
}
