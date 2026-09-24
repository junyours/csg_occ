<?php

use App\Domain\Exceptions\InvalidQrPayloadException;
use App\Domain\ValueObjects\QrPayload;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('round-trips a payload through encrypt and decrypt', function () {
    $issuedAt = Carbon::parse('2026-11-01 00:00:00', 'UTC');
    $payload = new QrPayload('2023105413', 1, $issuedAt);

    $decoded = QrPayload::decrypt($payload->encrypt());

    expect($decoded->studentNumber)->toBe('2023105413')
        ->and($decoded->qrVersion)->toBe(1)
        ->and($decoded->issuedAt->timestamp)->toBe($issuedAt->timestamp);
});

it('produces different ciphertext on every call for the same student', function () {
    // Laravel's encrypter uses a random IV per call. If this ever failed, it'd mean
    // someone could fingerprint or replay a token just by matching ciphertext bytes.
    $payload = QrPayload::forStudent('2021000007', 1);

    expect($payload->encrypt())->not->toBe($payload->encrypt());
});

it('rejects a string that was never encrypted by this app', function () {
    QrPayload::decrypt('not-a-real-token');
})->throws(InvalidQrPayloadException::class);

it('rejects a tampered token', function () {
    $token = QrPayload::forStudent('2021000001', 1)->encrypt();

    // Flip a character rather than overwriting with a fixed 'X': the token's
    // IV is random per encryption, so on the rare run where the byte at this
    // position already happened to be 'X', a hardcoded overwrite would be a
    // silent no-op and the test would flake. Swapping to a value guaranteed
    // different from the original always produces a real tamper.
    $replacement = $token[10] === 'X' ? 'Y' : 'X';
    $tampered = substr($token, 0, 10).$replacement.substr($token, 11);

    QrPayload::decrypt($tampered);
})->throws(InvalidQrPayloadException::class);

it('rejects a validly-encrypted but wrong-shape payload', function () {
    // Simulates someone who has APP_KEY encrypting arbitrary junk, not a real payload.
    $token = \Illuminate\Support\Facades\Crypt::encryptString('garbage');

    QrPayload::decrypt($token);
})->throws(InvalidQrPayloadException::class);
