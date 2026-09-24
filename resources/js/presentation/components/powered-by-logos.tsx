import bsitLogo from '@/assets/bsit-logo.png';
import bsbaLogo from '@/assets/bsba-logo.png';
import csgLogo from '@/assets/csg-logo.png';
import educLogo from '@/assets/educ-logo.png';
import occLogo from '@/assets/occ-logo.png';
import { Text } from '@/presentation/components/typography';
import { ShimmerLogo } from '@/presentation/components/shimmer-logo';

// NOTE: a dedicated bsit-logo.png hasn't been supplied yet — drop it into
// resources/js/assets/ and add an entry below (following the same shape)
// to slot BSIT in alongside the others.
const POWERED_BY_LOGOS = [
    { src: occLogo, alt: 'OCC' },
    { src: csgLogo, alt: 'CSG' },
    { src: bsitLogo, alt: 'BSIT' },
    { src: bsbaLogo, alt: 'BSBA' },
    { src: educLogo, alt: 'EDUC' },
];

// Random (not sequential) so the logos never look like they're taking
// turns in order — each gets its own unpredictable point in the 16s loop.
function randomDelay() {
    return Math.random() * 16;
}

export function PoweredByLogos() {
    return (
        <div className="space-y-4 pt-2 text-center">
            <Text variant="caption">Powered by</Text>
            <div className="flex flex-wrap items-center justify-center gap-4">
                {POWERED_BY_LOGOS.map((logo) => (
                    <ShimmerLogo key={logo.alt} src={logo.src} alt={logo.alt} delaySeconds={randomDelay()} />
                ))}
            </div>
        </div>
    );
}
