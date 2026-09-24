import { useEffect, useMemo, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Text } from '@/presentation/components/typography';

// Square viewport the user drags/zooms within. The circular guide drawn on
// top is only a visual crop indicator — the exported image is a plain
// square, since every avatar display spot in the app (UserAvatar, the
// lanyard card) already clips to a circle with CSS, so a square upload is
// all the backend needs.
const VIEWPORT = 288;
const OUTPUT_SIZE = 480;
const MIN_ZOOM = 1;
const MAX_ZOOM = 3;

interface AvatarPhotoEditorProps {
    file: File;
    onCancel: () => void;
    onConfirm: (blob: Blob) => void;
    isSaving?: boolean;
}

export function AvatarPhotoEditor({ file, onCancel, onConfirm, isSaving = false }: AvatarPhotoEditorProps) {
    const imageUrl = useMemo(() => URL.createObjectURL(file), [file]);
    useEffect(() => () => URL.revokeObjectURL(imageUrl), [imageUrl]);

    const imgRef = useRef<HTMLImageElement>(null);
    const [natural, setNatural] = useState<{ w: number; h: number } | null>(null);
    const [zoom, setZoom] = useState(MIN_ZOOM);
    const [pos, setPos] = useState({ x: 0, y: 0 });
    const dragRef = useRef<{ startClientX: number; startClientY: number; startX: number; startY: number } | null>(
        null,
    );

    // Covers the square viewport at zoom=1, same idea as CSS `background-size: cover`.
    const baseScale = natural ? Math.max(VIEWPORT / natural.w, VIEWPORT / natural.h) : 1;
    const displayScale = baseScale * zoom;
    const displayW = natural ? natural.w * displayScale : VIEWPORT;
    const displayH = natural ? natural.h * displayScale : VIEWPORT;

    function clamp(x: number, y: number, w: number, h: number) {
        const minX = Math.min(0, VIEWPORT - w);
        const minY = Math.min(0, VIEWPORT - h);
        return { x: Math.max(minX, Math.min(0, x)), y: Math.max(minY, Math.min(0, y)) };
    }

    function handleImageLoad() {
        const img = imgRef.current;
        if (!img) return;
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        setNatural({ w, h });
        const scale = Math.max(VIEWPORT / w, VIEWPORT / h);
        setPos({ x: (VIEWPORT - w * scale) / 2, y: (VIEWPORT - h * scale) / 2 });
    }

    function handleZoomChange(nextZoom: number) {
        setZoom(nextZoom);
        if (!natural) return;
        const nextScale = baseScale * nextZoom;
        const w = natural.w * nextScale;
        const h = natural.h * nextScale;
        setPos((prev) => clamp(prev.x, prev.y, w, h));
    }

    function handlePointerDown(event: React.PointerEvent) {
        event.currentTarget.setPointerCapture(event.pointerId);
        dragRef.current = { startClientX: event.clientX, startClientY: event.clientY, startX: pos.x, startY: pos.y };
    }

    function handlePointerMove(event: React.PointerEvent) {
        const drag = dragRef.current;
        if (!drag) return;
        const nextX = drag.startX + (event.clientX - drag.startClientX);
        const nextY = drag.startY + (event.clientY - drag.startClientY);
        setPos(clamp(nextX, nextY, displayW, displayH));
    }

    function handlePointerUp(event: React.PointerEvent) {
        if (event.currentTarget.hasPointerCapture(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
        dragRef.current = null;
    }

    function handleConfirm() {
        const img = imgRef.current;
        if (!img || !natural) return;

        // Map the visible viewport square back to source-image pixel
        // coordinates, then paint just that region into a fixed-size
        // output canvas — this is the actual crop.
        const sx = -pos.x / displayScale;
        const sy = -pos.y / displayScale;
        const sSize = VIEWPORT / displayScale;

        const canvas = document.createElement('canvas');
        canvas.width = OUTPUT_SIZE;
        canvas.height = OUTPUT_SIZE;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.drawImage(img, sx, sy, sSize, sSize, 0, 0, OUTPUT_SIZE, OUTPUT_SIZE);

        canvas.toBlob(
            (blob) => {
                if (blob) onConfirm(blob);
            },
            'image/jpeg',
            0.92,
        );
    }

    return (
        <div className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-black/80 p-6">
            <Text variant="small" className="text-white/80">
                Drag to reposition, use the slider to zoom.
            </Text>

            <div
                className="relative touch-none overflow-hidden rounded-md bg-neutral-900"
                style={{ width: VIEWPORT, height: VIEWPORT }}
                onPointerDown={handlePointerDown}
                onPointerMove={handlePointerMove}
                onPointerUp={handlePointerUp}
                onPointerCancel={handlePointerUp}
            >
                {/* eslint-disable-next-line jsx-a11y/alt-text -- decorative, being cropped, not shown to end users */}
                <img
                    ref={imgRef}
                    src={imageUrl}
                    onLoad={handleImageLoad}
                    draggable={false}
                    className="absolute cursor-grab select-none active:cursor-grabbing"
                    style={{ left: pos.x, top: pos.y, width: displayW, height: displayH, maxWidth: 'none' }}
                />
                {/* Circular crop guide: everything outside the circle is
                    dimmed via a huge box-shadow, purely for visual framing —
                    the actual export is the full square behind it. */}
                <div
                    className="pointer-events-none absolute inset-0 rounded-full"
                    style={{ boxShadow: '0 0 0 9999px rgba(0,0,0,0.6)' }}
                />
            </div>

            <input
                type="range"
                min={MIN_ZOOM}
                max={MAX_ZOOM}
                step={0.01}
                value={zoom}
                onChange={(event) => handleZoomChange(Number(event.target.value))}
                className="w-full max-w-[288px]"
                aria-label="Zoom"
            />

            <div className="flex w-full max-w-[288px] gap-2">
                <Button type="button" variant="outline" className="flex-1" onClick={onCancel} disabled={isSaving}>
                    Cancel
                </Button>
                <Button type="button" className="flex-1" onClick={handleConfirm} disabled={isSaving}>
                    {isSaving ? 'Saving…' : 'Save photo'}
                </Button>
            </div>
        </div>
    );
}
