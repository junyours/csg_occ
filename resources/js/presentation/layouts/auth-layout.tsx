import { useState, type PropsWithChildren } from 'react';

import csgLogo from '@/assets/csg-logo.png';
import psitsLogo from '@/assets/psits-logo.png';
import { Heading, Text } from '@/presentation/components/typography';
import { LegalDialog } from '@/presentation/components/legal/legal-dialog';
import { ShimmerLogo } from '@/presentation/components/shimmer-logo';

interface AuthLayoutProps extends PropsWithChildren {
    title: string;
    description?: string;
}

export function AuthLayout({ title, description, children }: AuthLayoutProps) {
    const [legalOpen, setLegalOpen] = useState<'terms' | 'privacy' | null>(null);

    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background px-6 py-10">
            {/*
              The one piece of ambient color on the whole screen: a single
              violet pool behind the crest, the same blurred glow the hero
              gauge sits in, scaled up. It's what makes the card read as lit
              from above rather than pasted on a flat page — and it's the
              reason nothing else here needs decoration.

              Violet because that's already the app's "you"/identity hue
              (the officer's own share, the student's QR nudge). Sign-in is
              the most identity-shaped moment in the product.
            */}
            <span
                aria-hidden
                className="pointer-events-none absolute -top-24 left-1/2 size-112 -translate-x-1/2 rounded-full bg-violet-500 opacity-[0.07] blur-3xl dark:opacity-[0.12]"
            />

            <div className="relative w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center text-center">
                    {/* No plate behind the crest — the seal already has its own
                        white disc, and stacking a second white square under it
                        just drew a box around the logo. The violet drop shadow
                        is applied to the image itself instead, so the glow
                        follows the seal's own edge. */}
                    <img
                        src={csgLogo}
                        alt="OCC CSG"
                        className="mb-4 size-20 object-contain drop-shadow-[0_10px_24px_rgba(109,40,217,0.28)]"
                    />
                    <Heading level="display" as="p" className="text-h3">
                        Central Student Government
                    </Heading>
                </div>

                <div className="rounded-3xl border border-border bg-card p-6 shadow-sm sm:p-8">
                    <div className="mb-6 space-y-2">
                        <Heading level="h2">{title}</Heading>
                        {description ? <Text variant="small">{description}</Text> : null}
                    </div>
                    {children}
                </div>

                <div className="mt-8 flex flex-col items-center gap-4 text-center">
                    <div className="flex items-center gap-2">
                        <Text variant="caption">Developed by</Text>
                        <ShimmerLogo src={psitsLogo} alt="PSITS" className="size-8 p-0 " />
                    </div>
                    <div className="flex items-center gap-2 text-caption text-muted-foreground">
                        <button
                            type="button"
                            onClick={() => setLegalOpen('terms')}
                            className="underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Terms &amp; Conditions
                        </button>
                        <span aria-hidden>·</span>
                        <button
                            type="button"
                            onClick={() => setLegalOpen('privacy')}
                            className="underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Privacy Policy
                        </button>
                    </div>
                </div>
            </div>

            <LegalDialog open={legalOpen} onOpenChange={(open) => !open && setLegalOpen(null)} />
        </div>
    );
}
