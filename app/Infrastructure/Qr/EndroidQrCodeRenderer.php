<?php

namespace App\Infrastructure\Qr;

use App\Domain\Contracts\QrCodeRendererInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

final class EndroidQrCodeRenderer implements QrCodeRendererInterface
{
    // Medium error correction: the token payload shrank drastically once
    // QrPayload switched to raw AES-256-GCM (no JSON envelope, no separate
    // HMAC — see QrPayload::encrypt()), so High EC's ~2x module-count cost
    // is no longer worth paying. Medium still tolerates real-world badge
    // wear while keeping modules large enough for fast, off-angle phone
    // camera scans.
    private const SIZE = 400;

    private const MARGIN = 4;

    public function renderPng(string $data): string
    {
        // v6 dropped Builder::create()/fluent setters in favor of a
        // readonly class built via constructor (named args); build()
        // takes no arguments here since every option is fixed up front.
        $builder = new Builder(
            writer: new PngWriter(),
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::SIZE,
            margin: self::MARGIN,
        );

        return $builder->build()->getString();
    }
}
