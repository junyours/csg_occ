/**
 * The dashboard's color language, in one place.
 *
 * Lifted from the streak card, which already had the look the rest of the
 * dashboard didn't: a solid saturated squircle carrying a white glyph, with
 * a soft shadow in its own hue underneath it
 * (`bg-orange-500 text-white shadow-lg shadow-orange-500/30`). That glow is
 * what makes a tile read as lit rather than printed, and it's the one thing
 * every surface here shares.
 *
 * Three weights per hue, used for three different jobs:
 *
 *   solid — filled + glowing. The single most important number or status in
 *           a card. At most one per card; two glows next to each other and
 *           neither one is the answer to "where do I look".
 *   soft  — tinted fill, colored glyph, no glow. Supporting stats, avatars,
 *           anything that needs to be identifiable but not loud.
 *   chip  — pill/badge backgrounds, with dark-mode pairs (the older inline
 *           `bg-amber-100 text-amber-800` strings around the app had none,
 *           so those chips went unreadable in dark mode).
 *
 * Hue keeps its existing meaning across the app — emerald is Present, amber
 * is Late, red is Missed/owed, orange is streak, violet is "you"/share, sky
 * is scheduling. Nothing here is decorative: if a color shows up, it's
 * carrying the same meaning it carries on the badges and the rings.
 */

export type Tone = 'emerald' | 'amber' | 'red' | 'orange' | 'violet' | 'sky' | 'blue' | 'yellow' | 'maroon' | 'neutral';

export interface ToneStyle {
    /** Filled tile: saturated bg, white glyph, glow in its own hue. */
    solid: string;
    /** Tinted tile: quiet bg, colored glyph, no glow. */
    soft: string;
    /** Text-only accent, for a number or a label. */
    text: string;
    /** Pill / badge background + text, dark-mode included. */
    chip: string;
    /** Progress + segment fills. */
    bar: string;
    /** Bare swatch, for legends and dots. */
    dot: string;
    /** SVG arc stroke, for the gauges. */
    stroke: string;
    /** Card wash — a barely-there tint + matching hairline, for a card that
     *  needs to be flagged without being filled. */
    wash: string;
    /** Gradient stops for the hero ring arc. */
    gradient: [string, string];
}

/*
 * Every class string below is written out in full rather than composed at
 * runtime (`bg-${tone}-500`), because Tailwind scans source text — an
 * interpolated class name is invisible to it and gets dropped from the
 * build. Verbose here, but it's the difference between working and silently
 * unstyled.
 */
export const TONE: Record<Tone, ToneStyle> = {
    emerald: {
        solid: 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/30 dark:shadow-emerald-500/20',
        soft: 'bg-emerald-500/12 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
        text: 'text-emerald-600 dark:text-emerald-400',
        chip: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
        bar: 'bg-emerald-500',
        dot: 'bg-emerald-500',
        stroke: 'stroke-emerald-500 dark:stroke-emerald-400',
        wash: 'border-emerald-500/20 bg-emerald-500/5 dark:bg-emerald-500/10',
        gradient: ['#34d399', '#10b981'],
    },
    amber: {
        solid: 'bg-amber-500 text-white shadow-lg shadow-amber-500/30 dark:shadow-amber-500/20',
        soft: 'bg-amber-500/12 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        text: 'text-amber-600 dark:text-amber-400',
        chip: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        bar: 'bg-amber-500',
        dot: 'bg-amber-500',
        stroke: 'stroke-amber-500 dark:stroke-amber-400',
        wash: 'border-amber-500/20 bg-amber-500/5 dark:bg-amber-500/10',
        gradient: ['#fbbf24', '#f59e0b'],
    },
    red: {
        solid: 'bg-red-500 text-white shadow-lg shadow-red-500/30 dark:shadow-red-500/20',
        soft: 'bg-red-500/12 text-red-600 dark:bg-red-500/15 dark:text-red-400',
        text: 'text-red-600 dark:text-red-400',
        chip: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
        bar: 'bg-red-500',
        dot: 'bg-red-500',
        stroke: 'stroke-red-500 dark:stroke-red-400',
        wash: 'border-red-500/20 bg-red-500/5 dark:bg-red-500/10',
        gradient: ['#f87171', '#ef4444'],
    },
    orange: {
        solid: 'bg-orange-500 text-white shadow-lg shadow-orange-500/30 dark:shadow-orange-500/20',
        soft: 'bg-orange-500/12 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
        text: 'text-orange-600 dark:text-orange-400',
        chip: 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-300',
        bar: 'bg-orange-500',
        dot: 'bg-orange-500',
        stroke: 'stroke-orange-500 dark:stroke-orange-400',
        wash: 'border-orange-500/20 bg-orange-500/5 dark:bg-orange-500/10',
        gradient: ['#fb923c', '#f97316'],
    },
    violet: {
        solid: 'bg-violet-500 text-white shadow-lg shadow-violet-500/30 dark:shadow-violet-500/20',
        soft: 'bg-violet-500/12 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
        text: 'text-violet-600 dark:text-violet-400',
        chip: 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
        bar: 'bg-violet-500',
        dot: 'bg-violet-500',
        stroke: 'stroke-violet-500 dark:stroke-violet-400',
        wash: 'border-violet-500/20 bg-violet-500/5 dark:bg-violet-500/10',
        gradient: ['#a78bfa', '#7c3aed'],
    },
    sky: {
        solid: 'bg-sky-500 text-white shadow-lg shadow-sky-500/30 dark:shadow-sky-500/20',
        soft: 'bg-sky-500/12 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
        text: 'text-sky-600 dark:text-sky-400',
        chip: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
        bar: 'bg-sky-500',
        dot: 'bg-sky-500',
        stroke: 'stroke-sky-500 dark:stroke-sky-400',
        wash: 'border-sky-500/20 bg-sky-500/5 dark:bg-sky-500/10',
        gradient: ['#38bdf8', '#0ea5e9'],
    },
    /* Department primary colors — blue (BEED / BSED), yellow (BSBA) and
       maroon (IT). Kept separate from the status hues above on purpose. */
    blue: {
        solid: 'bg-blue-600 text-white shadow-lg shadow-blue-600/30 dark:shadow-blue-600/20',
        soft: 'bg-blue-600/12 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
        text: 'text-blue-700 dark:text-blue-300',
        chip: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
        bar: 'bg-blue-600',
        dot: 'bg-blue-600',
        stroke: 'stroke-blue-600 dark:stroke-blue-400',
        wash: 'border-blue-600/25 bg-blue-600/5 dark:bg-blue-500/10',
        gradient: ['#3b82f6', '#2563eb'],
    },
    yellow: {
        solid: 'bg-yellow-400 text-yellow-950 shadow-lg shadow-yellow-400/30 dark:shadow-yellow-400/20',
        soft: 'bg-yellow-400/20 text-yellow-700 dark:bg-yellow-400/15 dark:text-yellow-300',
        text: 'text-yellow-700 dark:text-yellow-300',
        chip: 'bg-yellow-100 text-yellow-900 dark:bg-yellow-400/15 dark:text-yellow-200',
        bar: 'bg-yellow-400',
        dot: 'bg-yellow-400',
        stroke: 'stroke-yellow-400',
        wash: 'border-yellow-400/40 bg-yellow-400/10 dark:bg-yellow-400/10',
        gradient: ['#facc15', '#eab308'],
    },
    maroon: {
        solid: 'bg-[#800000] text-white shadow-lg shadow-[#800000]/30 dark:shadow-[#800000]/20',
        soft: 'bg-[#800000]/10 text-[#800000] dark:bg-[#b84a4a]/20 dark:text-[#e59a9a]',
        text: 'text-[#800000] dark:text-[#e59a9a]',
        chip: 'bg-[#800000]/10 text-[#800000] dark:bg-[#b84a4a]/20 dark:text-[#e59a9a]',
        bar: 'bg-[#800000]',
        dot: 'bg-[#800000]',
        stroke: 'stroke-[#800000] dark:stroke-[#e59a9a]',
        wash: 'border-[#800000]/25 bg-[#800000]/5 dark:bg-[#b84a4a]/10',
        gradient: ['#a52a2a', '#800000'],
    },
    /* The deliberate absence of color — nothing tracked, nothing owed,
       nothing to report. Reads as "quiet", not as "broken". */
    neutral: {
        solid: 'bg-muted text-muted-foreground',
        soft: 'bg-muted text-muted-foreground',
        text: 'text-muted-foreground',
        chip: 'bg-muted text-muted-foreground',
        bar: 'bg-muted-foreground/40',
        dot: 'bg-muted-foreground/40',
        stroke: 'stroke-muted-foreground/40',
        wash: 'border-border bg-muted/40',
        gradient: ['#d4d4d4', '#a3a3a3'],
    },
};

/** Cycling tints for department avatars — a visual anchor so each card is
 *  findable at a glance, not a meaningful code. Skips the four status hues
 *  so a department badge is never mistaken for a Present/Late/Missed one. */
export const DEPARTMENT_TONES: Tone[] = ['violet', 'sky', 'orange', 'emerald'];

/**
 * A department's fixed primary color: BEED and BSED are blue, BSBA is
 * yellow, IT (BSIT) is maroon. Every record tied to a department — a
 * dashboard card, a student row, a department row — uses this so a
 * department is recognisable at a glance. Unknown codes fall back to the
 * old cycling tint (pass the row index) so a new department still gets one.
 */
export function departmentTone(code: string | null | undefined, fallbackIndex = 0): Tone {
    const c = (code ?? '').toUpperCase().replace(/[^A-Z]/g, '');
    if (c.includes('BEED') || c.includes('BSED')) return 'blue';
    if (c.includes('BSBA') || c === 'BA') return 'yellow';
    if (c === 'IT' || c.endsWith('IT')) return 'maroon';
    return DEPARTMENT_TONES[fallbackIndex % DEPARTMENT_TONES.length];
}
