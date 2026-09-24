import { useState } from 'react';
import { ChevronDown, MapPin } from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';

/**
 * Static, hand-written FAQ — best-guess questions a student using this app
 * would actually have, answered from how the system behaves (scanning,
 * late/absent classification, penalties, exclusions). Not fetched from the
 * backend; there's no FAQ table or endpoint, just this page.
 */
interface FaqItem {
    question: string;
    answer: string;
}

const FAQ_ITEMS: FaqItem[] = [
    {
        question: 'How do I check in or check out of a session?',
        answer: "Open your QR code from the dashboard and have an officer scan it at the gate. Each session only needs one scan — scanning again just confirms you're already recorded, it won't double-count or double-penalize you.",
    },
    {
        question: 'Why was I marked Late instead of Present?',
        answer: "Every session has a scanning window set by the event organizers. If your scan lands after the on-time cutoff but before the window closes, it's automatically classified as Late instead of Present — there's no manual step involved.",
    },
    {
        question: "What's the difference between Absent and Excluded?",
        answer: "Absent means you were expected at a session and didn't scan in for it. Excluded means CSG excused you from that session (or window, or the whole event) ahead of time, so it never counts against your attendance rate or carries a penalty.",
    },
    {
        question: 'My attendance percentage looks wrong — how is it calculated?',
        answer: 'Your score is (Present + Late) sessions divided by all tracked sessions (Present + Late + Absent). Excluded sessions are left out of both sides of that math entirely, so being excused never drags your percentage down.',
    },
    {
        question: 'I got a penalty but I think it was a mistake — what now?',
        answer: 'Penalties tied to a Late or Absent session can be reviewed and reversed by a CSG Admin. If one of yours gets reversed, it stops counting toward what you owe and any report generated afterward will show that session as Reversed rather than Absent.',
    },
    {
        question: 'What happens if my QR code stops working?',
        answer: "Your QR code is tied to a version number that changes if it's ever reset — an old, stale code will be rejected at the scanner rather than silently accepted. If yours won't scan, visit the CSG Office to have it reissued.",
    },
    {
        question: 'Can I still get credit if I forget my phone or the app is down?',
        answer: 'An officer at the gate can still record your attendance manually on their end. If that happens and it never shows up on your dashboard, report it to the CSG Office so it can be checked and corrected.',
    },
    {
        question: 'Do I need to scan for both time-in and time-out?',
        answer: "It depends on the session — some are time-in only, others require both. Check the event schedule on your dashboard; each session there is labeled with whether it's a time-in or time-out check.",
    },
];

function FaqRow({ item, isOpen, onToggle }: { item: FaqItem; isOpen: boolean; onToggle: () => void }) {
    return (
        <Card className="overflow-hidden py-0">
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-4 px-4 py-4 text-left"
                aria-expanded={isOpen}
            >
                <Text variant="small" className="font-medium text-foreground">
                    {item.question}
                </Text>
                <ChevronDown className={cn('size-4 shrink-0 text-muted-foreground transition-transform', isOpen && 'rotate-180')} />
            </button>

            {isOpen && (
                <CardContent className="px-4 pt-0 pb-4">
                    <Text variant="small" className="text-muted-foreground">
                        {item.answer}
                    </Text>
                </CardContent>
            )}
        </Card>
    );
}

export function FaqPage() {
    const [openIndex, setOpenIndex] = useState<number | null>(0);

    return (
        <div className="mx-auto max-w-md space-y-8">
            <div>
                <Heading level="h1">FAQ</Heading>
                <Text variant="small">Answers to what students ask most about attendance and check-ins.</Text>
            </div>

            <div className="space-y-2">
                {FAQ_ITEMS.map((item, index) => (
                    <FaqRow
                        key={item.question}
                        item={item}
                        isOpen={openIndex === index}
                        onToggle={() => setOpenIndex((current) => (current === index ? null : index))}
                    />
                ))}
            </div>

            <div className="flex items-start gap-2 rounded-2xl border bg-card p-4">
                <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <Text variant="small" className="text-muted-foreground">
                    Didn't find what you're looking for? For more inquiries, visit the CSG Office.
                </Text>
            </div>
        </div>
    );
}
