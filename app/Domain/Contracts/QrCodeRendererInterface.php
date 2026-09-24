<?php

namespace App\Domain\Contracts;

interface QrCodeRendererInterface
{
    /**
     * Render $data as a scannable QR code image and return the raw
     * binary bytes (spec §5.1: must be readable by any standard QR
     * scanner app — the *image* format has no secrecy requirement, only
     * the encrypted string it encodes does).
     */
    public function renderPng(string $data): string;
}
