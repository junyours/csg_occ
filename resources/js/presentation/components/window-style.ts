import { Moon, Sun, Sunrise, type LucideIcon } from 'lucide-react';

import type { Tone } from '@/presentation/components/tone';

/**
 * The one place a session window's look is defined: icon + hue.
 *
 *   Morning   — sunrise, yellow
 *   Afternoon — sun,     orange
 *   Evening   — moon,    violet
 *
 * Every screen that shows a window (dashboard schedule and streak strip, the
 * event screens, attendance history, the scanner) reads from here, so a
 * "Morning" row is the same yellow sunrise everywhere. Morning is yellow, not
 * amber, on purpose: amber already means Late.
 */
export const WINDOW_STYLE: Record<string, { Icon: LucideIcon; tone: Tone }> = {
    morning: { Icon: Sunrise, tone: 'yellow' },
    afternoon: { Icon: Sun, tone: 'orange' },
    evening: { Icon: Moon, tone: 'violet' },
};
