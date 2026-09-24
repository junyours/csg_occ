import { isRouteErrorResponse, Link, useRouteError } from 'react-router-dom';
import { Unplug } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Heading, Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { cn } from '@/lib/utils';
import { TONE } from '@/presentation/components/tone';

export function ErrorPage() {
    const error = useRouteError();

    const message = isRouteErrorResponse(error)
        ? error.statusText || 'Something went wrong.'
        : error instanceof Error
          ? error.message
          : 'Something went wrong.';

    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-background px-6">
            <div className={cn('flex w-full max-w-sm flex-col items-center gap-4 rounded-3xl border p-8 text-center', TONE.red.wash)}>
                <Tile tone="red" size="lg" variant="solid" Icon={Unplug} />
                {/* No "Error" eyebrow above the heading — the heading already
                    says it, and a label restating its own content is noise. */}
                <Heading level="h2">Something went wrong</Heading>
                <Text variant="small" className="max-w-xs break-words">
                    {message}
                </Text>
                <Button asChild className="mt-2">
                    <Link to="/dashboard">Back to dashboard</Link>
                </Button>
            </div>
        </div>
    );
}
