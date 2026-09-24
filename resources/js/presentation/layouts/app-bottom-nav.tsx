import { useEffect, useRef, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Download } from 'lucide-react';
import { NavLink, useLocation, useNavigate } from 'react-router-dom';
import { CalendarDays, LayoutDashboard, QrCode, ScanLine, Settings } from 'lucide-react';

import { useStudentQr } from '@/application/students/use-student-qr';

import { Role, ROLE_LABEL } from '@/domain/enums';
import type { Student } from '@/domain/entities';
import { UserAvatar } from '@/presentation/components/user-avatar';
import { cn } from '@/lib/utils';

interface NavItem {
    to: string;
    label: string;
    icon: typeof LayoutDashboard;
}

const SCAN_ROLES: Student['role'][] = [Role.SystemAdmin, Role.CsgAdmin, Role.Officer];
const EVENTS_ROLES: Student['role'][] = [Role.SystemAdmin, Role.CsgAdmin];

const MORPH_TRANSITION = { type: 'spring', stiffness: 480, damping: 36, mass: 0.9 } as const;

export function AppBottomNav({ student }: { student: Student }) {
    const { role } = student;
    const location = useLocation();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const shellRef = useRef<HTMLDivElement>(null);

    // The on-page "Save QR Code" button moved here. This shares the same
    // query key as the QR page itself (see use-student-qr.ts), so having
    // both mounted at once doesn't trigger a second network fetch — they
    // read from the same react-query cache entry.
    const isProfile = location.pathname.startsWith('/profile');
    const { save: saveQrCode, isLoading: qrCodeLoading } = useStudentQr(student.id);

    const items: NavItem[] = [{ to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard }];

    if (role === Role.Student) {
        // Student's Settings page has nothing on it (see SettingsPage —
        // every row there is gated to an admin/officer role), so it's
        // dropped entirely rather than linking to an empty screen. With
        // only three tabs, My QR in the middle slot is the one that
        // should draw the eye, so it goes between Dashboard and Events
        // instead of trailing after them.
        items.push({ to: '/profile', label: 'My QR', icon: QrCode });
        items.push({ to: '/events', label: 'Events', icon: CalendarDays });
    } else {
        // Events tab always shows for these roles — it shouldn't disappear
        // just because the current event was ended. Admins/officers need it
        // to review or manage past events too, not just while one is ongoing.
        if (EVENTS_ROLES.includes(role)) {
            items.push({ to: '/events', label: 'Events', icon: CalendarDays });
        }

        if (SCAN_ROLES.includes(role)) {
            items.push({ to: '/scan', label: 'Scan', icon: ScanLine });
        }

        items.push({ to: '/settings', label: 'Settings', icon: Settings });
    }

    // Pages reached from a Settings row (see settings-page.tsx) but that
    // live at their own top-level URL rather than under /settings —
    // without this, none of the `items` prefixes match here and the
    // collapsed pill falls through to its `items[0]` default (Dashboard),
    // which is wrong for these.
    const SETTINGS_SUBROUTES = ['/reports', '/students', '/academic-years', '/departments', '/penalties'];
    const isSettingsSubroute = SETTINGS_SUBROUTES.some((route) => location.pathname.startsWith(route));

    const current =
        [...items].sort((a, b) => b.to.length - a.to.length).find((item) => location.pathname.startsWith(item.to)) ??
        (isSettingsSubroute ? items.find((item) => item.to === '/settings') : undefined) ??
        items[0];

    // Whether there's actually somewhere to go back to. react-router's data
    // router stores a history index in history.state.idx — 0 (or missing,
    // e.g. opened straight into a deep link) means there's no in-app
    // history to pop back to. On Dashboard there's also conceptually
    // nowhere "back" to within the app. When this is false the back
    // button is hidden entirely rather than shown and disabled.
    const isHome = location.pathname === '/dashboard';
    const historyIndex = (window.history.state as { idx?: number } | null)?.idx ?? 0;
    const canGoBack = !isHome && historyIndex > 0;

    useEffect(() => {
        setOpen(false);
    }, [location.pathname]);

    useEffect(() => {
        if (!open) return;

        function handlePointerDown(event: PointerEvent) {
            if (shellRef.current && !shellRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        document.addEventListener('pointerdown', handlePointerDown);
        return () => document.removeEventListener('pointerdown', handlePointerDown);
    }, [open]);

    function handleBack() {
        navigate(-1);
    }

    return (
        <nav
            className="fixed inset-x-0 bottom-0 z-50 flex items-center justify-center gap-4 px-4"
            style={{ paddingBottom: 'max(1.25rem, env(safe-area-inset-bottom))' }}
        >
            {/* Standalone floating circle when the nav is collapsed, sized
                to match the collapsed pill. Shares layoutId="back-button"
                with the "Back" item rendered inside the expanded panel's
                icon row below, so opening the nav morphs this circle into
                that item instead of it just vanishing. Only rendered when
                there's somewhere to go back to. */}
            {!open && isProfile && (
                <button
                    type="button"
                    onClick={() => saveQrCode(`qr-${student.studentNumber}.png`)}
                    disabled={qrCodeLoading}
                    aria-label="Save QR code"
                    className="order-3 flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-[0_12px_40px_-8px_rgba(0,0,0,0.35)] disabled:opacity-50"
                >
                    <Download className="size-5" />
                </button>
            )}

            {!open && canGoBack && (
                <motion.button
                    layoutId="back-button"
                    transition={MORPH_TRANSITION}
                    type="button"
                    onClick={handleBack}
                    aria-label="Go back"
                    className={cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-[0_12px_40px_-8px_rgba(0,0,0,0.35)]',
                        isProfile ? 'order-1' : 'order-1',
                    )}
                >
                    <ChevronLeft className="size-5" />
                </motion.button>
            )}

            <div ref={shellRef} className="order-2">
                <AnimatePresence initial={false} mode="popLayout">
                    {open ? (
                        <motion.div
                            key="expanded"
                            layoutId="bottom-nav-shell"
                            transition={MORPH_TRANSITION}
                            className="w-[min(88vw,24rem)] rounded-[28px] bg-primary p-4 shadow-[0_20px_60px_-12px_rgba(0,0,0,0.45)]"
                        >
                            <div className="flex items-center gap-2 px-2 py-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setOpen(false);
                                        navigate('/account');
                                    }}
                                    className="flex min-w-0 items-center gap-2 rounded-2xl py-2 text-left transition-colors hover:bg-primary-foreground/10"
                                >
                                    <UserAvatar student={student} className="size-9" />
                                    <div className="min-w-0 max-w-40">
                                        <span className="block text-[11px] leading-tight text-primary-foreground/60">
                                            {ROLE_LABEL[student.role]}
                                        </span>
                                        <span className="block truncate text-sm leading-tight font-medium text-primary-foreground">
                                            {student.firstName} {student.lastName}
                                        </span>
                                    </div>
                                    <ChevronRight className="size-4 shrink-0 text-primary-foreground/50" />
                                </button>
                                <div className="flex-1" />
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    aria-label="Collapse navigation"
                                    className="flex size-7 shrink-0 items-center justify-center rounded-full text-primary-foreground/60 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground"
                                >
                                    <ChevronDown className="size-4" strokeWidth={2.5} />
                                </button>
                            </div>

                            {/* px-2 keeps the first/last item (which can be
                                highlighted when active) clear of the
                                panel's rounded edge instead of hugging it. */}
                            <div className="mt-2 flex items-center justify-center gap-2 px-4 pb-2">
                                {/* {canGoBack && (
                                    <motion.button
                                        layoutId="back-button"
                                        transition={MORPH_TRANSITION}
                                        type="button"
                                        onClick={handleBack}
                                        className="flex flex-col items-center gap-2 rounded-2xl px-4 py-4 text-primary-foreground/55 transition-colors hover:bg-primary-foreground/10"
                                    >
                                        <ChevronLeft className="size-5" strokeWidth={2} />
                                        <span className="text-center text-[11px] leading-none text-balance">
                                            Back
                                        </span>
                                    </motion.button>
                                )} */}
                                {items.map((item) => {
                                    const isHero = item.to === '/profile' || item.to === '/scan';

                                    return (
                                        <NavLink
                                            key={item.to}
                                            to={item.to}
                                            className={({ isActive }) =>
                                                cn(
                                                    'group flex flex-col items-center gap-2 rounded-2xl px-2 py-4 text-primary-foreground/55 transition-colors',
                                                    isActive && 'text-primary-foreground',
                                                )
                                            }
                                        >
                                            {isHero ? (
                                                <span className="relative flex size-11 items-center justify-center">
                                                    <motion.span
                                                        aria-hidden
                                                        className="absolute inset-0 rounded-full bg-emerald-400/20 blur-sm"
                                                        animate={{ scale: [1, 1.2, 1], opacity: [0.4, 0.8, 0.4] }}
                                                        transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut' }}
                                                    />
                                                    <span className="relative flex size-9 items-center justify-center overflow-hidden rounded-full">
                                                        <item.icon className="size-9 text-emerald-300" strokeWidth={2} />
                                                        <motion.span
                                                            aria-hidden
                                                            className="absolute inset-y-0 w-1/3 -skew-x-12 bg-linear-to-r from-transparent via-white/60 to-transparent mix-blend-overlay"
                                                            animate={{ x: ['-130%', '230%'] }}
                                                            transition={{ duration: 3.2, repeat: Infinity, repeatDelay: 2.4, ease: 'easeInOut' }}
                                                        />
                                                    </span>
                                                </span>
                                            ) : (
                                                <item.icon
                                                    className={cn('size-5', 'group-aria-[current=page]:text-emerald-400')}
                                                    strokeWidth={2}
                                                />
                                            )}
                                            <span
                                                className={cn(
                                                    'text-center font-medium leading-tight whitespace-nowrap group-aria-[current=page]:font-semibold',
                                                    isHero ? 'text-xs' : 'text-[11px]',
                                                )}
                                            >
                                                {item.label}
                                            </span>
                                        </NavLink>
                                    );
                                })}
                            </div>
                        </motion.div>
                    ) : (
                        <motion.button
                            key="collapsed"
                            layoutId="bottom-nav-shell"
                            type="button"
                            transition={MORPH_TRANSITION}
                            onClick={() => setOpen(true)}
                            aria-label={`Currently on ${current.label}. Tap to open navigation.`}
                            className="flex items-center gap-2 rounded-full bg-primary py-2 pr-2 pl-2 shadow-[0_12px_40px_-8px_rgba(0,0,0,0.35)]"
                        >
                            <UserAvatar student={student} className="size-7" />

                            <span className="relative flex size-2">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                            </span>

                            <span className="pr-0.5 text-sm font-medium whitespace-nowrap text-primary-foreground">
                                {current.label}
                            </span>

                            <span className="flex items-center gap-2 rounded-full bg-primary-foreground/10 py-2 pr-2 pl-2 text-primary-foreground/70">
                                <ChevronUp className="size-3.5" strokeWidth={2.5} />
                                <span className="text-[10px] font-semibold tracking-wide">TAP</span>
                            </span>
                        </motion.button>
                    )}
                </AnimatePresence>
            </div>
        </nav>
    );
}
