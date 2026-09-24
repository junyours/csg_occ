/**
 * The 8-point spacing scale.
 *
 * One base unit — 8px — and everything that positions or separates
 * elements is a multiple of it: 8, 16, 24, 32, 40, 48. Tailwind's unit is
 * 4px, so the classes that land on the scale are the even ones:
 *
 *      8px → 2      24px → 6      40px → 10
 *     16px → 4      32px → 8      48px → 12
 *
 * Anything odd (`p-3` = 12px, `gap-2.5` = 10px, `px-3.5` = 14px, `p-5` =
 * 20px) is off the scale and is what this module exists to replace. Reach
 * for a token here before typing a raw spacing class.
 *
 * ---------------------------------------------------------------------
 * The one documented exception
 * ---------------------------------------------------------------------
 * A 4px half-step is allowed in exactly one situation: spacing *inside* a
 * component's own box where 8px would be wider than the box can carry —
 * the icon and the numeral stacked inside a 48px squircle, the gap between
 * a figure and its caption in a single text block. It never governs the
 * distance between two components, a card's padding, or a section break.
 *
 * Hairlines (`gap-px`, borders) aren't spacing and aren't on the scale.
 *
 * As the infographic this came from puts it: an 8-point grid is a useful
 * convention, not a universal law. The rule below is the convention this
 * app has settled on — deviate deliberately, in a comment, or not at all.
 */

/** The scale itself, in pixels, for anywhere a raw number is needed (SVG sizes, etc.). */
export const SPACE = {
    /** 8px — the base unit. Icon-to-text, tight list rows. */
    xs: 8,
    /** 16px — the workhorse. Card padding, gaps between components. */
    sm: 16,
    /** 24px — screen padding, roomy card padding. */
    md: 24,
    /** 32px — between two sections of a page. */
    lg: 32,
    /** 40px — secondary control height. */
    xl: 40,
    /** 48px — primary control height and the minimum comfortable tap target. */
    xxl: 48,
} as const;

/**
 * Card padding. Tailwind's `Card` ships 24px all round, which is right on
 * a desktop and too generous on a 390px phone — 16px on mobile leaves the
 * content more room without ever leaving the scale.
 *
 * `root` also carries the header-to-content gap, so a card with a header
 * and a card without one have the same outer rhythm.
 */
export const CARD = {
    /** On `<Card>`: 16px padding + 16px header-to-content gap, 24px from `sm` up. */
    root: 'gap-4 py-4 sm:gap-6 sm:py-6',
    /** On `<CardHeader>` / `<CardContent>` / `<CardFooter>`: matches `root`'s horizontal padding. */
    inset: 'px-4 sm:px-6',
    /** For a hand-rolled card (a plain `div` with a border) that needs padding on all four sides. */
    pad: 'p-4 sm:p-6',
} as const;

/** Vertical rhythm. Three levels, and three is enough. */
export const STACK = {
    /** 32px — between major sections of a page. */
    section: 'space-y-8',
    /** 16px — between sibling components inside one section. */
    group: 'space-y-4',
    /** 8px — between a label and the thing it labels, or rows of one tight list. */
    label: 'space-y-2',
} as const;

export const GAP = {
    /** 16px — the default gap for a grid or a row of components. */
    grid: 'gap-4',
    /** 8px — icon to its text. Keep this constant everywhere; it's what makes
     *  a label read as one object instead of two. */
    iconText: 'gap-2',
} as const;

/**
 * A pill/chip: 32px tall, 16px of horizontal padding, 8px icon-to-text.
 * Compose with a `TONE[...].chip` for color.
 */
export const CHIP = 'inline-flex h-8 shrink-0 items-center gap-2 rounded-full px-4 text-caption font-medium';

/**
 * A list row holding a 32px (`sm`) Tile: 8px top and bottom lands the row
 * at exactly 48px, the tap-target height.
 */
export const ROW = 'flex items-center gap-4 rounded-2xl px-4 py-2';

/** 48px — minimum height for anything tappable. */
export const TAP = 'min-h-12';

/** 40px — secondary controls (filter pills, segmented day tabs). */
export const TAP_SM = 'h-10';
